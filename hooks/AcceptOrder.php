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

add_hook('AcceptOrder', 1, function ($vars) {
    try {
        $orderId = isset($vars['orderid']) ? (int) $vars['orderid'] : 0;
        if (!$orderId || !beemsms_once('ordacc_' . $orderId)) {
            return;
        }

        $order = Capsule::table('tblorders')->where('id', $orderId)->first();
        if (!$order || (int) $order->userid <= 0) {
            return;
        }

        $services = [];
        $rows = Capsule::table('tblhosting')
            ->join('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
            ->where('tblhosting.orderid', $orderId)
            ->select('tblproducts.name', 'tblhosting.domain')
            ->get();
        foreach ($rows as $row) {
            $domain = trim((string) $row->domain);
            $services[] = $row->name . ($domain !== '' ? ' (' . $domain . ')' : '');
        }
        if (!$services) {
            return; // domain-only orders have no service to announce
        }

        Sender::trigger('service_created', (int) $order->userid, ['service' => implode(', ', $services)]);
    } catch (\Throwable $e) {
        Sender::swallow('AcceptOrder', $e);
    }
});
