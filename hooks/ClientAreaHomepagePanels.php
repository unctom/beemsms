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

add_hook('ClientAreaHomepagePanels', 1, function ($panels) {
    try {
        if (empty($_SESSION['uid'])) {
            return;
        }
        $settings = Sender::moduleSettings();
        if (!isset($settings['homepage_panel']) || $settings['homepage_panel'] !== 'on') {
            return;
        }
        $master = Sender::clientAllows((int) $_SESSION['uid'], '*');
        $panel = $panels->addChild('beem-sms-panel', [
            'name' => 'SMS notifications',
            'label' => 'SMS notifications',
            'icon' => 'fa-mobile-alt',
            'order' => 30,
        ]);
        $panel->addChild('beem-sms-manage', [
            'label' => 'SMS alerts are ' . ($master ? 'on' : 'off') . ' — manage preferences',
            'uri' => 'index.php?m=beemsms',
            'order' => 1,
        ]);
    } catch (\Throwable $e) {
        // panels must never break
    }
});
