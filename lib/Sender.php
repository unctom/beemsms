<?php
/**
 * Beem SMS for WHMCS — send pipeline: settings, preferences, templating, logging.
 *
 * @license MIT
 * @link    https://github.com/unctom/beemsms
 */

namespace BeemSms;

use WHMCS\Database\Capsule;

class Sender
{
    /** @var array|null */
    private static $settings = null;

    /**
     * Addon configuration values from tbladdonmodules.
     *
     * @return array<string,string>
     */
    public static function moduleSettings()
    {
        if (self::$settings === null) {
            self::$settings = [];
            $rows = Capsule::table('tbladdonmodules')->where('module', 'beemsms')->get();
            foreach ($rows as $row) {
                self::$settings[$row->setting] = $row->value;
            }
        }

        return self::$settings;
    }

    /**
     * @return BeemClient|null
     */
    public static function client()
    {
        $s = self::moduleSettings();
        $client = new BeemClient(
            isset($s['api_key']) ? $s['api_key'] : '',
            isset($s['secret_key']) ? $s['secret_key'] : '',
            isset($s['sender_id']) ? $s['sender_id'] : ''
        );

        return $client->isConfigured() ? $client : null;
    }

    /**
     * Normalize a phone number to international digits (e.g. 2557XXXXXXXX,
     * 14155551234). WHMCS stores numbers picked with the country selector as
     * "+<countrycode>.<number>" — a leading + means the country code is
     * already present (any country), so it is trusted as-is and only a stray
     * 0 at the start of the national part is dropped. Numbers without + are
     * treated as local to the configured default country code.
     *
     * @return string|null
     */
    public static function normalize($phone, $countryCode)
    {
        $raw = trim((string) $phone);
        $cc = preg_replace('/\D+/', '', (string) $countryCode);
        if ($cc === '') {
            $cc = '255';
        }

        $compact = preg_replace('/[^\d+.]/', '', $raw);
        if (preg_match('/^\+(\d{1,3})\.?(\d{4,14})$/', $compact, $m)) {
            $national = $m[2];
            // Drop a stray trunk 0 at the start of the national part - except
            // for Italy (+39), where the leading 0 is a real digit kept even
            // in international format.
            if ($m[1] !== '39' && strpos($national, '0') === 0) {
                $national = substr($national, 1);
            }
            $digits = $m[1] . $national;
            $len = strlen($digits);

            return ($len >= 9 && $len <= 15) ? $digits : null;
        }

        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') {
            return null;
        }
        if (strpos($digits, '00') === 0) {
            $digits = substr($digits, 2);
        }
        if (strpos($digits, '0') === 0) {
            $digits = $cc . substr($digits, 1);
        } elseif (strpos($digits, $cc) !== 0 && strlen($digits) <= 9) {
            $digits = $cc . $digits;
        }

        $len = strlen($digits);

        return ($len >= 10 && $len <= 15) ? $digits : null;
    }

    /**
     * Replace {field} placeholders (case-insensitive).
     */
    public static function merge($template, array $fields)
    {
        $message = (string) $template;
        foreach ($fields as $key => $value) {
            $message = str_ireplace('{' . $key . '}', (string) $value, $message);
        }

        return trim(preg_replace('/\{[a-z0-9_]+\}/i', '', $message));
    }

    /**
     * Client preference check. No stored row means enabled (default on).
     * Use event key '*' for the master switch.
     */
    public static function clientAllows($clientId, $eventKey)
    {
        $row = Capsule::table('mod_beemsms_prefs')
            ->where('clientid', (int) $clientId)
            ->where('event_key', $eventKey)
            ->first();

        return $row ? (bool) $row->enabled : true;
    }

    public static function setClientPref($clientId, $eventKey, $enabled)
    {
        Capsule::table('mod_beemsms_prefs')->updateOrInsert(
            ['clientid' => (int) $clientId, 'event_key' => $eventKey],
            ['enabled' => $enabled ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')]
        );
    }

    /**
     * Main entry point used by hooks. Never throws.
     */
    public static function trigger($eventKey, $clientId, array $fields = [])
    {
        try {
            $clientId = (int) $clientId;
            if ($clientId <= 0) {
                return;
            }

            $client = self::client();
            if (!$client) {
                return;
            }

            $event = Capsule::table('mod_beemsms_events')->where('event_key', $eventKey)->first();
            if (!$event || !$event->enabled) {
                return;
            }

            // Client preferences only govern opt-out-able events. Events
            // locked "always sent" (client_can_optout = 0, e.g. suspension
            // notices) ignore both the master switch and per-event prefs
            // while the admin has them enabled.
            if ($event->client_can_optout) {
                if (!self::clientAllows($clientId, '*')) {
                    return;
                }
                if (!self::clientAllows($clientId, $eventKey)) {
                    return;
                }
            }

            $settings = self::moduleSettings();
            $row = Capsule::table('tblclients')->where('id', $clientId)->first();
            if (!$row) {
                return;
            }

            $countryCode = isset($settings['country_code']) ? $settings['country_code'] : '255';
            $phone = self::normalize($row->phonenumber, $countryCode);
            if (!$phone) {
                self::log($clientId, $eventKey, '', '', 'no_phone', null, 'Client has no usable phone number');

                return;
            }

            $fields = array_merge([
                'firstname' => $row->firstname,
                'lastname' => $row->lastname,
                'client' => trim($row->firstname . ' ' . $row->lastname),
                'companyname' => trim((string) $row->companyname),
                'mycompany' => self::companyName(),
            ], $fields);

            $adminTemplate = '';
            if ($event->notify_admin) {
                $adminTemplate = (isset($event->admin_template) && trim((string) $event->admin_template) !== '')
                    ? (string) $event->admin_template
                    : self::defaultAdminTemplate($eventKey);
            }

            // Only spend a shortener call when a template actually uses {link}.
            if (isset($fields['link']) && $fields['link'] !== ''
                && class_exists('\\BeemSms\\Shortener')
                && (stripos((string) $event->template, '{link}') !== false || stripos($adminTemplate, '{link}') !== false)
            ) {
                $fields['link'] = Shortener::shorten($fields['link']);
            }

            $message = self::merge($event->template, $fields);
            if ($message === '') {
                return;
            }

            $result = $client->send($message, [$phone]);
            self::log($clientId, $eventKey, $phone, $message, $result['success'] ? 'submitted' : 'failed', $result['request_id'], $result['raw']);
            self::moduleLog($eventKey, $phone, $message, $result);

            if ($event->notify_admin) {
                $adminPhone = self::normalize(isset($settings['admin_phone']) ? $settings['admin_phone'] : '', $countryCode);
                if ($adminPhone && $adminPhone !== $phone) {
                    $adminMessage = $adminTemplate !== '' ? self::merge($adminTemplate, $fields) : '';
                    if ($adminMessage === '') {
                        $adminMessage = '[' . $fields['client'] . '] ' . $message;
                    }
                    $adminResult = $client->send(mb_substr($adminMessage, 0, 320), [$adminPhone]);
                    self::log(0, $eventKey . '_admin', $adminPhone, $adminMessage, $adminResult['success'] ? 'submitted' : 'failed', $adminResult['request_id'], $adminResult['raw']);
                }
            }
        } catch (\Throwable $e) {
            self::swallow('trigger:' . $eventKey, $e);
        }
    }

    /**
     * Direct send (admin one-off / test / cron alerts). Returns the API result.
     *
     * @return array
     */
    public static function sendRaw($phone, $message, $eventKey = 'manual', $clientId = 0)
    {
        $client = self::client();
        if (!$client) {
            return ['success' => false, 'message' => 'Beem API credentials are not configured yet.', 'raw' => ''];
        }

        $result = $client->send($message, [$phone]);
        self::log($clientId, $eventKey, $phone, $message, $result['success'] ? 'submitted' : 'failed', $result['request_id'], $result['raw']);
        self::moduleLog($eventKey, $phone, $message, $result);

        return $result;
    }

    public static function log($clientId, $eventKey, $phone, $message, $status, $requestId, $response)
    {
        try {
            Capsule::table('mod_beemsms_log')->insert([
                'clientid' => (int) $clientId,
                'event_key' => substr((string) $eventKey, 0, 50),
                'phone' => substr((string) $phone, 0, 20),
                'message' => (string) $message,
                'status' => substr((string) $status, 0, 20),
                'request_id' => $requestId !== null ? substr((string) $requestId, 0, 40) : null,
                'response' => $response !== null ? substr((string) $response, 0, 2000) : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            self::swallow('log', $e);
        }
    }

    public static function systemUrl()
    {
        $url = '';
        try {
            $row = Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->first();
            $url = $row ? (string) $row->value : '';
        } catch (\Throwable $e) {
            $url = '';
        }

        return $url !== '' ? rtrim($url, '/') . '/' : '';
    }

    public static function companyName()
    {
        try {
            $row = Capsule::table('tblconfiguration')->where('setting', 'CompanyName')->first();

            return $row ? (string) $row->value : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Default admin-copy template from the event registry.
     *
     * @return string
     */
    public static function defaultAdminTemplate($eventKey)
    {
        $meta = Events::get($eventKey);

        return $meta && isset($meta['admin_template']) ? (string) $meta['admin_template'] : '';
    }

    /**
     * Format a money amount with the currency code, dropping ".00".
     *
     * @return string
     */
    public static function money($amount, $currencyCode = '')
    {
        $formatted = number_format((float) $amount, 2, '.', ',');
        if (substr($formatted, -3) === '.00') {
            $formatted = substr($formatted, 0, -3);
        }

        return trim(((string) $currencyCode !== '' ? $currencyCode . ' ' : '') . $formatted);
    }

    /**
     * Beem credit balance, cached in mod_beemsms_meta so the dashboard can
     * show it on every load without an API call. Refreshed when older than
     * six hours (15 minutes after a failed attempt), when forced, and by the
     * daily cron.
     *
     * @param bool $force
     *
     * @return array{balance:?float,success:bool,checked_at:?int}
     */
    public static function cachedBalance($force = false)
    {
        $out = ['balance' => null, 'success' => false, 'checked_at' => null];
        try {
            $row = Capsule::table('mod_beemsms_meta')->where('meta_key', 'balance_cache')->first();
            $cache = $row ? json_decode($row->meta_value, true) : null;
            if (is_array($cache)) {
                $out['balance'] = isset($cache['balance']) && $cache['balance'] !== null ? (float) $cache['balance'] : null;
                $out['success'] = !empty($cache['ok']);
                $out['checked_at'] = isset($cache['checked_at']) ? (int) $cache['checked_at'] : null;
            }

            $ttl = $out['success'] ? 21600 : 900;
            $stale = $out['checked_at'] === null || (time() - $out['checked_at']) >= $ttl;
            if (!$force && !$stale) {
                return $out;
            }

            $client = self::client();
            if (!$client) {
                // Credentials missing: whatever the cache says is no longer
                // trustworthy - flag it stale so the chip warns and the cron
                // low-balance alert stays quiet.
                $out['success'] = false;

                return $out;
            }

            $result = $client->balance();
            if ($result['success'] && $result['credit_balance'] !== null) {
                $out = ['balance' => (float) $result['credit_balance'], 'success' => true, 'checked_at' => time()];
            } else {
                // keep the last known figure but flag it as stale
                $out['success'] = false;
                $out['checked_at'] = time();
            }

            Capsule::table('mod_beemsms_meta')->updateOrInsert(
                ['meta_key' => 'balance_cache'],
                ['meta_value' => json_encode(['balance' => $out['balance'], 'ok' => $out['success'], 'checked_at' => $out['checked_at']])]
            );
        } catch (\Throwable $e) {
            self::swallow('balance_cache', $e);
        }

        return $out;
    }

    /**
     * Merge-field bundle for an invoice. When $paymentAmount is given (a
     * payment was just applied), {amount} is that payment; otherwise it is
     * the invoice total. Also exposes {total} and {balance} (amount still
     * owed after credit and all recorded payments).
     *
     * @param int        $invoiceId
     * @param float|null $paymentAmount
     *
     * @return array|null
     */
    public static function invoiceFields($invoiceId, $paymentAmount = null)
    {
        $invoice = Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->first();
        if (!$invoice) {
            return null;
        }

        $currency = Capsule::table('tblclients')
            ->join('tblcurrencies', 'tblcurrencies.id', '=', 'tblclients.currency')
            ->where('tblclients.id', $invoice->userid)
            ->select('tblcurrencies.code')
            ->first();
        $code = $currency ? $currency->code : '';

        $paid = 0.0;
        try {
            $sums = Capsule::table('tblaccounts')
                ->where('invoiceid', (int) $invoice->id)
                ->selectRaw('COALESCE(SUM(amountin),0) as inflow, COALESCE(SUM(amountout),0) as outflow')
                ->first();
            if ($sums) {
                $paid = (float) $sums->inflow - (float) $sums->outflow;
            }
        } catch (\Throwable $e) {
            $paid = 0.0;
        }

        $total = (float) $invoice->total;
        $credit = isset($invoice->credit) ? (float) $invoice->credit : 0.0;
        $balance = max(0.0, round($total - $credit - $paid, 2));

        // WHMCS 9 records invoice adjustments as credit/debit notes the
        // manual formula cannot see; GetInvoice's balance does. Inside
        // AddTransaction the API figure may not yet include the payment just
        // recorded, so take the lower of the two estimates.
        if (function_exists('localAPI')) {
            try {
                $api = localAPI('GetInvoice', ['invoiceid' => (int) $invoice->id]);
                if (is_array($api) && isset($api['balance']) && (!isset($api['result']) || $api['result'] === 'success')) {
                    $balance = max(0.0, min($balance, round((float) $api['balance'], 2)));
                }
            } catch (\Throwable $e) {
                // keep the manual figure
            }
        }

        return [
            'clientid' => (int) $invoice->userid,
            'invoicenum' => $invoice->invoicenum !== '' && $invoice->invoicenum !== null ? $invoice->invoicenum : (string) $invoice->id,
            'amount' => self::money($paymentAmount !== null ? $paymentAmount : $total, $code),
            'total' => self::money($total, $code),
            'balance' => self::money($balance, $code),
            'raw_total' => $total,
            'raw_balance' => $balance,
            'duedate' => date('j M Y', strtotime($invoice->duedate)),
            'link' => self::systemUrl() . 'viewinvoice.php?id=' . (int) $invoice->id,
        ];
    }

    public static function moduleLog($action, $phone, $message, array $result)
    {
        if (function_exists('logModuleCall')) {
            try {
                logModuleCall('beemsms', $action, ['phone' => $phone, 'message' => $message], $result['raw'], $result);
            } catch (\Throwable $e) {
                // never block on logging
            }
        }
    }

    public static function swallow($context, \Throwable $e)
    {
        if (function_exists('logActivity')) {
            try {
                logActivity('Beem SMS error (' . $context . '): ' . $e->getMessage());
            } catch (\Throwable $inner) {
                // give up quietly — an SMS problem must never break WHMCS
            }
        }
    }
}
