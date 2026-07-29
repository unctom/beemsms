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

add_hook('ClientAreaSecondaryNavbar', 1, function ($navbar) {
    try {
        if (empty($_SESSION['uid']) || is_null($navbar)) {
            return;
        }
        $account = $navbar->getChild('Account');
        if (!is_null($account)) {
            $account->addChild('beem-sms-notifications', [
                'label' => 'SMS notifications',
                'uri' => 'index.php?m=beemsms',
                'order' => 70,
            ]);
        }
    } catch (\Throwable $e) {
        // navigation must never break
    }
});
