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

add_hook('InvoicePaid', 1, function ($vars) {
    try {
        $invoiceId = (int) $vars['invoiceid'];
        if (!$invoiceId || !beemsms_payment_request_guard($invoiceId)) {
            return;
        }

        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if (!$invoice || (float) $invoice->total <= 0) {
            return; // 0.00 invoices are auto-marked paid - no SMS
        }
        $sums = Capsule::table('tblaccounts')
            ->where('invoiceid', $invoiceId)
            ->selectRaw('COALESCE(SUM(amountin),0) - COALESCE(SUM(amountout),0) as paid')
            ->first();
        $uncovered = round((float) $invoice->total - ($sums ? (float) $sums->paid : 0.0), 2);
        if ($uncovered <= 0) {
            return; // fully transaction-backed - already texted per payment
        }
        if (!beemsms_once('payinv_' . $invoiceId)) {
            return;
        }

        $fields = Sender::invoiceFields($invoiceId, $uncovered);
        if ($fields) {
            Sender::trigger('invoice_paid', $fields['clientid'], $fields);
        }
    } catch (\Throwable $e) {
        Sender::swallow('InvoicePaid', $e);
    }
});
