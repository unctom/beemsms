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

add_hook('AddTransaction', 1, function ($vars) {
    try {
        $invoiceId = 0;
        foreach (['invoiceid', 'invocieid'] as $key) {
            if (!empty($vars[$key])) {
                $invoiceId = (int) $vars[$key];
                break;
            }
        }
        if (!$invoiceId) {
            return; // transaction not tied to an invoice
        }

        $amountIn = isset($vars['amountin']) ? (float) $vars['amountin'] : 0.0;
        if ($amountIn <= 0 || !empty($vars['refundid'])) {
            return; // refunds and zero transactions are not payments
        }

        $txnId = isset($vars['id']) ? (int) $vars['id'] : 0;
        if ($txnId && !beemsms_once('paytx_' . $txnId)) {
            return;
        }

        $fields = Sender::invoiceFields($invoiceId, $amountIn);
        if (!$fields || $fields['raw_total'] <= 0) {
            return; // zero-total invoices owe nothing - no payment SMS
        }

        beemsms_payment_request_guard($invoiceId, true); // suppress the InvoicePaid fallback this request
        Sender::trigger('invoice_paid', $fields['clientid'], $fields);
    } catch (\Throwable $e) {
        Sender::swallow('AddTransaction', $e);
    }
});
