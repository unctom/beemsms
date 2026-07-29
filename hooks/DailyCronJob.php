<?php
/**
 * Beem SMS notify your clients via SMS.
 *
 * @license MIT
 * @link    https://github.com/unctom/beemsms
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;
use BeemSms\Sender;
use BeemSms\Shortener;
use BeemSms\UpdateChecker;

require_once __DIR__ . '/../lib/BeemClient.php';
require_once __DIR__ . '/../lib/Events.php';
require_once __DIR__ . '/../lib/Sender.php';
require_once __DIR__ . '/../lib/Shortener.php';
require_once __DIR__ . '/../lib/UpdateChecker.php';
require_once __DIR__ . '/../lib/HooksHelpers.php';

add_hook('DailyCronJob', 1, function ($vars) {
    try {
        $settings = Sender::moduleSettings();

        $days = isset($settings['log_retention_days']) ? (int) $settings['log_retention_days'] : 90;
        if ($days > 0) {
            Capsule::table('mod_beemsms_log')
                ->where('created_at', '<', date('Y-m-d H:i:s', strtotime('-' . $days . ' days')))
                ->delete();
        }

        Shortener::prune();

        $cutoff = (string) (time() - (90 * 86400));
        foreach (['paytx\_%', 'payinv\_%', 'ordacc\_%'] as $pattern) {
            Capsule::table('mod_beemsms_meta')
                ->where('meta_key', 'like', $pattern)
                ->where('meta_value', '<', $cutoff)
                ->delete();
        }

        $balance = Sender::cachedBalance(true);

        $threshold = isset($settings['low_balance_threshold']) ? (float) $settings['low_balance_threshold'] : 0;
        $adminPhone = isset($settings['admin_phone']) ? trim($settings['admin_phone']) : '';
        if ($threshold > 0 && $adminPhone !== ''
            && $balance['success'] && $balance['balance'] !== null && (float) $balance['balance'] < $threshold
        ) {
            $countryCode = isset($settings['country_code']) ? $settings['country_code'] : '255';
            $phone = Sender::normalize($adminPhone, $countryCode);
            if ($phone) {
                Sender::sendRaw(
                    $phone,
                    'Beem SMS balance is low: ' . number_format((float) $balance['balance'], 2) . ' credits left. Top up to keep client notifications flowing.',
                    'low_balance'
                );
            }
            if (function_exists('logActivity')) {
                logActivity('Beem SMS: balance low (' . $balance['balance'] . ' credits)');
            }
        }

        $update = UpdateChecker::check();
        if (UpdateChecker::updateAvailable($update) && function_exists('logActivity')) {
            logActivity('Beem SMS: version ' . $update['latest'] . ' is available - ' . $update['url']);
        }
    } catch (\Throwable $e) {
        Sender::swallow('DailyCronJob', $e);
    }
});
