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

add_hook('InvoicePaymentReminder', 1, function ($vars) {
    try {
        $fields = Sender::invoiceFields((int) $vars['invoiceid']);
        if ($fields) {
            $labels = ['first' => '1st', 'second' => '2nd', 'third' => '3rd'];
            $type = isset($vars['type']) ? $vars['type'] : 'first';
            $fields['reminder'] = isset($labels[$type]) ? $labels[$type] : $type;
            Sender::trigger('payment_reminder', $fields['clientid'], $fields);
        }
    } catch (\Throwable $e) {
        Sender::swallow('InvoicePaymentReminder', $e);
    }
});
