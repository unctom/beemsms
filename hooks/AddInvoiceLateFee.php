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

add_hook('AddInvoiceLateFee', 1, function ($vars){
    try {
        $invoiceId = isset($vars['invoiceid']) ? (int) $vars['invoiceid'] : 0;
        if (!$invoiceId) {
            return;
        }

        $fields = Sender::invoiceFields($invoiceId);
        if ($fields) {
            Sender::trigger('invoice_late_fee', $fields['clientid'], $fields);
        }
    } catch (\Throwable $e) {
        Sender::swallow('AddInvoiceLateFee', $e);
    }
});
