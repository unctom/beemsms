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

add_hook('InvoiceCreationPreEmail', 1, function ($vars) {
    try {
        $invoiceId = 0;
        foreach (['invoiceid', 'relid', 'id'] as $key) {
            if (!empty($vars[$key])) {
                $invoiceId = (int) $vars[$key];
                break;
            }
        }
        $fields = $invoiceId ? Sender::invoiceFields($invoiceId) : null;
        if ($fields) {
            Sender::trigger('invoice_created', $fields['clientid'], $fields);
        }
    } catch (\Throwable $e) {
        Sender::swallow('InvoiceCreationPreEmail', $e);
    }
});
