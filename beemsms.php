<?php
/**
 * Beem SMS for WHMCS
 *
 * Client SMS notifications for WHMCS via the Beem Africa SMS API
 * (https://beem.africa). Mirrors WHMCS email events — invoices, payments,
 * reminders, service and support events — as text messages, with per-client
 * opt-out preferences in the client area.
 *
 * White-label and open source (MIT).
 *
 * @license MIT
 * @link    https://github.com/unctom/beemsms
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/BeemClient.php';
require_once __DIR__ . '/lib/Events.php';
require_once __DIR__ . '/lib/Sender.php';
require_once __DIR__ . '/lib/Shortener.php';
require_once __DIR__ . '/lib/UpdateChecker.php';
require_once __DIR__ . '/lib/Updater.php';

use BeemSms\BeemClient;
use BeemSms\Events;
use BeemSms\Sender;
use BeemSms\UpdateChecker;
use BeemSms\Updater;

function beemsms_config()
{
    return [
        'name' => 'BeemSms',
        'description' => 'Client SMS notifications via the Beem Africa SMS API. Invoices, payments, reminders, service and support events — with client-area preferences.',
        'version' => UpdateChecker::VERSION,
        'author' => 'Unctom',
        'language' => 'english',
        'fields' => [
            'api_key' => [
                'FriendlyName' => 'Beem API key',
                'Type' => 'text',
                'Size' => '50',
                'Description' => 'From your Beem dashboard (beem.africa).',
            ],
            'secret_key' => [
                'FriendlyName' => 'Beem secret key',
                'Type' => 'password',
                'Size' => '50',
                'Description' => 'Stored in WHMCS only. Never committed anywhere.',
            ],
            'sender_id' => [
                'FriendlyName' => 'Sender ID',
                'Type' => 'text',
                'Size' => '20',
                'Description' => 'Your approved Beem sender name (max 11 characters).',
            ],

            'admin_phone' => [
                'FriendlyName' => 'Admin mobile',
                'Type' => 'text',
                'Size' => '20',
                'Description' => 'Receives copies of events marked "copy admin" and low-balance alerts.',
            ],
            'low_balance_threshold' => [
                'FriendlyName' => 'Low balance alert',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '30',
                'Description' => 'SMS the admin via daily cron when Beem credits fall below this number. 0 disables.',
            ],
            'log_retention_days' => [
                'FriendlyName' => 'Log retention (days)',
                'Type' => 'text',
                'Size' => '6',
                'Default' => '90',
                'Description' => 'Sent-message log entries older than this are pruned by the daily cron.',
            ],
            'shorten_links' => [
                'FriendlyName' => 'Shorten links in SMS',
                'Type' => 'dropdown',
                'Options' => 'Disabled,TinyURL,da.gd,YOURLS',
                'Default' => 'Disabled',
                'Description' => 'Shorten invoice links before sending, saving SMS characters. TinyURL/da.gd are free public services (URLs are shared with them, and some carriers filter public shortener domains). YOURLS is your own shortener on a branded domain - best deliverability; set the two YOURLS fields below. If shortening fails, the full link is sent.',
            ],
            'yourls_url' => [
                'FriendlyName' => 'YOURLS URL',
                'Type' => 'text',
                'Size' => '40',
                'Description' => 'Only for YOURLS. The base URL of your YOURLS install, e.g. https://s.yourdomain.com (no trailing slash).',
            ],
            'yourls_signature' => [
                'FriendlyName' => 'YOURLS signature token',
                'Type' => 'password',
                'Size' => '40',
                'Description' => 'Only for YOURLS. The passwordless API signature from YOURLS admin > Tools > "Secure passwordless API call".',
            ],
            'homepage_panel' => [
                'FriendlyName' => 'Client homepage panel',
                'Type' => 'yesno',
                'Description' => 'Show an "SMS notifications" panel on the client area homepage.',
            ],
        ],
    ];
}

function beemsms_activate()
{
    try {
        if (!Capsule::schema()->hasTable('mod_beemsms_events')) {
            Capsule::schema()->create('mod_beemsms_events', function ($table) {
                $table->string('event_key', 50)->primary();
                $table->boolean('enabled')->default(0);
                $table->boolean('notify_admin')->default(0);
                $table->boolean('client_can_optout')->default(1);
                $table->text('template');
                $table->text('admin_template')->nullable();
            });
        } elseif (!Capsule::schema()->hasColumn('mod_beemsms_events', 'admin_template')) {
            Capsule::schema()->table('mod_beemsms_events', function ($table) {
                $table->text('admin_template')->nullable();
            });
        }

        if (!Capsule::schema()->hasTable('mod_beemsms_prefs')) {
            Capsule::schema()->create('mod_beemsms_prefs', function ($table) {
                $table->increments('id');
                $table->integer('clientid')->index();
                $table->string('event_key', 50);
                $table->boolean('enabled')->default(1);
                $table->timestamp('updated_at')->nullable();
                $table->unique(['clientid', 'event_key']);
            });
        }

        if (!Capsule::schema()->hasTable('mod_beemsms_log')) {
            Capsule::schema()->create('mod_beemsms_log', function ($table) {
                $table->increments('id');
                $table->integer('clientid')->default(0)->index();
                $table->string('event_key', 50);
                $table->string('phone', 20);
                $table->text('message');
                $table->string('status', 20);
                $table->string('request_id', 40)->nullable();
                $table->text('response')->nullable();
                $table->timestamp('created_at')->nullable()->index();
            });
        }

        if (!Capsule::schema()->hasTable('mod_beemsms_meta')) {
            Capsule::schema()->create('mod_beemsms_meta', function ($table) {
                $table->string('meta_key', 50)->primary();
                $table->text('meta_value');
            });
        }

        Events::seed();

        return [
            'status' => 'success',
            'description' => 'Beem SMS activated. Enter your Beem API credentials in the module configuration, then review events and templates under Addons > Beem SMS.',
        ];
    } catch (\Throwable $e) {
        return [
            'status' => 'error',
            'description' => 'Could not create Beem SMS tables: ' . $e->getMessage(),
        ];
    }
}

function beemsms_deactivate()
{
    return [
        'status' => 'success',
        'description' => 'Beem SMS deactivated. Tables and logs were kept; re-activate to continue where you left off.',
    ];
}

function beemsms_upgrade($vars)
{
    try {
        if (!Capsule::schema()->hasTable('mod_beemsms_meta')) {
            Capsule::schema()->create('mod_beemsms_meta', function ($table) {
                $table->string('meta_key', 50)->primary();
                $table->text('meta_value');
            });
        }
        if (Capsule::schema()->hasTable('mod_beemsms_events')
            && !Capsule::schema()->hasColumn('mod_beemsms_events', 'admin_template')
        ) {
            Capsule::schema()->table('mod_beemsms_events', function ($table) {
                $table->text('admin_template')->nullable();
            });
        }
        Events::seed();
    } catch (\Throwable $e) {
        // non-fatal
    }
}

function beemsms_output($vars)
{
    $modulelink = $vars['modulelink'];
    $e = function ($value) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    };

    $notice = '';
    $noticeClass = 'success';
    $action = isset($_POST['beem_action']) ? $_POST['beem_action'] : '';
    $activeTab = isset($_POST['tab']) ? $_POST['tab'] : 'logs';
    $justSwapped = false;
    $hasAdminColumn = false;
    try {
        $hasAdminColumn = Capsule::schema()->hasColumn('mod_beemsms_events', 'admin_template');
    } catch (\Throwable $e) {
        $hasAdminColumn = false;
    }

    if ($action === 'save_events') {
        foreach (Events::all() as $key => $meta) {
            Capsule::table('mod_beemsms_events')->where('event_key', $key)->update([
                'enabled' => isset($_POST['enabled'][$key]) ? 1 : 0,
                'notify_admin' => isset($_POST['notify_admin'][$key]) ? 1 : 0,
                'client_can_optout' => isset($_POST['client_can_optout'][$key]) ? 1 : 0,
            ]);
        }
        $notice = 'Event settings saved.';
        $activeTab = 'events';
    }

    if ($action === 'save_templates') {
        foreach (Events::all() as $key => $meta) {
            $update = [];
            if (isset($_POST['template'][$key]) && trim($_POST['template'][$key]) !== '') {
                $update['template'] = trim($_POST['template'][$key]);
            }
            if ($hasAdminColumn && isset($_POST['admin_template'][$key]) && trim($_POST['admin_template'][$key]) !== '') {
                $update['admin_template'] = trim($_POST['admin_template'][$key]);
            }
            if ($update) {
                Capsule::table('mod_beemsms_events')->where('event_key', $key)->update($update);
            }
        }
        $notice = 'Templates saved.';
        $activeTab = 'templates';
    }

    if ($action === 'reset_templates') {
        foreach (Events::all() as $key => $meta) {
            $update = ['template' => $meta['template']];
            if ($hasAdminColumn) {
                $update['admin_template'] = $meta['admin_template'];
            }
            Capsule::table('mod_beemsms_events')->where('event_key', $key)->update($update);
        }
        $notice = 'All templates restored to defaults.';
        $activeTab = 'templates';
    }

    if ($action === 'send_test') {
        $settings = Sender::moduleSettings();
        $phone = Sender::normalize(isset($_POST['test_phone']) ? $_POST['test_phone'] : '', '255');
        $message = trim(isset($_POST['test_message']) ? $_POST['test_message'] : '');
        if (!$phone) {
            $notice = 'That phone number does not look valid after normalization.';
            $noticeClass = 'danger';
        } elseif ($message === '') {
            $notice = 'Enter a message to send.';
            $noticeClass = 'danger';
        } else {
            $result = Sender::sendRaw($phone, $message, 'manual');
            if ($result['success']) {
                $notice = 'Message submitted to Beem for ' . $phone . ' (request ' . $e(isset($result['request_id']) ? $result['request_id'] : '-') . ').';
            } else {
                $notice = 'Beem rejected the message: ' . $e(isset($result['message']) ? $result['message'] : 'unknown error');
                $noticeClass = 'danger';
            }
        }
        $activeTab = 'logs';
    }

    if ($action === 'resend') {
        $logId = isset($_POST['log_id']) ? (int) $_POST['log_id'] : 0;
        $row = $logId ? Capsule::table('mod_beemsms_log')->where('id', $logId)->first() : null;
        if ($row && $row->phone !== '') {
            $result = Sender::sendRaw($row->phone, $row->message, 'resend:' . $row->event_key, (int) $row->clientid);
            if ($result['success']) {
                $notice = 'Message resent to ' . $e($row->phone) . '.';
            } else {
                $notice = 'Resend failed: ' . $e(isset($result['message']) ? $result['message'] : 'unknown error');
                $noticeClass = 'danger';
            }
        } else {
            $notice = 'Log entry not found or has no phone number.';
            $noticeClass = 'danger';
        }
        $activeTab = 'logs';
    }

    if ($action === 'check_update') {
        $info = UpdateChecker::check(true);
        if (UpdateChecker::updateAvailable($info)) {
            $pre = Updater::preflight();
            $notice = 'Version ' . $e($info['latest']) . ' is available. '
                . ($pre['ok']
                    ? 'Use the <strong>Update now</strong> button below to install it, or <a href="' . $e($info['url']) . '" target="_blank">download it</a> to apply manually.'
                    : $e($pre['error']) . ' <a href="' . $e($info['url']) . '" target="_blank">Download it here</a> to apply manually.');
            $noticeClass = 'warning';
        } elseif (!empty($info['latest'])) {
            $notice = 'You are running the latest version (' . $e(UpdateChecker::VERSION) . ').';
        } else {
            $notice = 'Could not reach GitHub to check for updates. Try again later.';
            $noticeClass = 'warning';
        }
    }

    if ($action === 'apply_update') {
        $result = Updater::applyLatest();
        $notice = $e($result['message']);
        $noticeClass = $result['success'] ? 'success' : ($result['rolled_back'] ? 'warning' : 'danger');
        $justSwapped = $result['success'];
    }

    if ($action === 'rollback_update') {
        $result = Updater::rollback();
        $notice = $e($result['message']);
        $noticeClass = $result['success'] ? 'success' : 'danger';
        $justSwapped = $result['success'];
    }

    if ($action === 'check_balance') {
        $fresh = Sender::cachedBalance(true);
        if ($fresh['balance'] !== null && $fresh['success']) {
            $notice = 'Beem balance refreshed: ' . $e(number_format((float) $fresh['balance'], 2)) . ' credits.';
        } else {
            $notice = 'Could not fetch the balance from Beem - check your API credentials.';
            $noticeClass = 'warning';
        }
    }

    $validTabs = ['events', 'templates', 'logs', 'api'];
    if (!in_array($activeTab, $validTabs, true)) {
        $activeTab = 'logs';
    }

    $settings = Sender::moduleSettings();
    $configured = Sender::client() !== null;
    $eventRows = [];
    foreach (Capsule::table('mod_beemsms_events')->get() as $row) {
        $eventRows[$row->event_key] = $row;
    }
    $logs = Capsule::table('mod_beemsms_log')->orderBy('id', 'desc')->limit(50)->get();

    if ($notice !== '') {
        echo '<div id="beem-notice" class="alert alert-' . $noticeClass . '" style="margin-top:10px;">' . $notice . '</div>'
            . '<script>setTimeout(function(){ var el = document.getElementById("beem-notice"); if (el) el.style.display = "none"; }, 5000);</script>';
    }

    $updateStatus = Updater::status();
    $updateHtml = '';
    if (UpdateChecker::updateAvailable(UpdateChecker::cached())) {
        $updateInfo = UpdateChecker::cached();
        $updateHtml = ' <a href="' . $e($updateInfo['url']) . '" target="_blank" class="label label-warning">Update v' . $e($updateInfo['latest']) . ' available</a>';
    }

    $updateActionHtml = '';
    if ($updateStatus['updatable'] && !$justSwapped) {
        $updateActionHtml .= ' <form method="post" action="' . $e($modulelink) . '" style="display:inline;" '
            . 'onsubmit="return confirm(\'Download and install v' . $e($updateStatus['latest']) . '? The current version is backed up and restored automatically if the update fails.\');">'
            . '<input type="hidden" name="beem_action" value="apply_update">'
            . '<input type="hidden" name="tab" value="' . $e($activeTab) . '">'
            . '<button type="submit" class="btn btn-primary btn-xs">Update now &rarr; v' . $e($updateStatus['latest']) . '</button>'
            . '</form>';
    }
    if ($updateStatus['has_backup']) {
        $updateActionHtml .= ' <form method="post" action="' . $e($modulelink) . '" style="display:inline;" '
            . 'onsubmit="return confirm(\'Roll back to the previously installed version?\');">'
            . '<input type="hidden" name="beem_action" value="rollback_update">'
            . '<input type="hidden" name="tab" value="' . $e($activeTab) . '">'
            . '<button type="submit" class="btn btn-default btn-xs">Roll back</button>'
            . '</form>';
    }

    $balanceInfo = $configured ? Sender::cachedBalance() : ['balance' => null, 'success' => false, 'checked_at' => null];
    $balanceChip = '';
    if ($configured) {
        if ($balanceInfo['balance'] !== null) {
            $chipLabel = '<span class="label label-' . ($balanceInfo['success'] ? 'info' : 'warning') . '" style="vertical-align:middle;" title="Last checked '
                . $e($balanceInfo['checked_at'] ? date('j M H:i', (int) $balanceInfo['checked_at']) : 'never') . '">'
                . 'Balance: ' . $e(number_format((float) $balanceInfo['balance'], 2)) . '</span>';
        } else {
            $chipLabel = '<span class="label label-warning" style="vertical-align:middle;">Balance unavailable</span>';
        }
        $balanceChip = '<span style="white-space:nowrap;">'
            . $chipLabel
            . '<form method="post" action="' . $e($modulelink) . '" style="display:inline;margin:0;">'
            . '<input type="hidden" name="beem_action" value="check_balance">'
            . '<input type="hidden" name="tab" value="' . $e($activeTab) . '">'
            . '<button type="submit" class="btn btn-default btn-xs" style="vertical-align:middle;margin-left:3px;padding:1px 6px;line-height:1.4;" title="Refresh balance">&#8635;</button>'
            . '</form>'
            . '</span>';
    }

    echo '<p style="margin-top:10px;">'
        . ($configured
            ? '<span class="label label-success">API configured</span>'
            : '<span class="label label-danger">Not configured</span> — set your API key, secret key and sender ID in System Settings &gt; Addon Modules &gt; Beem SMS &gt; Configure.')
        . ' <span class="label label-default">Sender: ' . $e(isset($settings['sender_id']) ? $settings['sender_id'] : '-') . '</span> '
        . $balanceChip
        . ' <span class="label label-default">v' . $e(UpdateChecker::VERSION) . '</span>'
        . $updateHtml
        . ' <form method="post" action="' . $e($modulelink) . '" style="display:inline;">'
        . '<input type="hidden" name="beem_action" value="check_update">'
        . '<input type="hidden" name="tab" value="' . $e($activeTab) . '">'
        . '<button type="submit" class="btn btn-default btn-xs">Check for updates</button>'
        . '</form>'
        . $updateActionHtml
        . ' <span class="text-muted" style="margin-left:8px;">Clients manage preferences at <code>index.php?m=beemsms</code></span>'
        . '</p>';

    echo '<script>
function beemShowTab(k) {
    var panes = document.querySelectorAll(".beem-pane");
    for (var i = 0; i < panes.length; i++) { panes[i].style.display = "none"; }
    var tabs = document.querySelectorAll(".beem-tabs > li");
    for (var j = 0; j < tabs.length; j++) { tabs[j].className = ""; }
    document.getElementById("beem-pane-" + k).style.display = "block";
    document.getElementById("beem-tab-" + k).className = "active";
    return false;
}
</script>';

    $tabs = [
        'events' => 'Events',
        'templates' => 'Templates',
        'logs' => 'Logs & sending',
        'api' => 'API responses',
    ];

    echo '<ul class="nav nav-tabs beem-tabs" style="margin-top:6px;">';
    foreach ($tabs as $key => $label) {
        echo '<li id="beem-tab-' . $key . '"' . ($activeTab === $key ? ' class="active"' : '') . '>'
            . '<a href="#" onclick="return beemShowTab(\'' . $key . '\');">' . $label . '</a></li>';
    }
    echo '</ul>';

    echo '<div class="beem-pane" id="beem-pane-events" style="padding-top:15px;' . ($activeTab === 'events' ? '' : 'display:none;') . '">'
        . '<form method="post" action="' . $e($modulelink) . '">'
        . '<input type="hidden" name="beem_action" value="save_events">'
        . '<table class="datatable" width="100%" cellspacing="1"><tr>'
        . '<th width="70">On</th><th>Event</th><th width="110">Copy admin</th><th width="140">Client can opt out</th></tr>';

    foreach (Events::all() as $key => $meta) {
        $row = isset($eventRows[$key]) ? $eventRows[$key] : null;
        $enabled = $row ? (bool) $row->enabled : (bool) $meta['enabled'];
        $notifyAdmin = $row ? (bool) $row->notify_admin : (bool) $meta['notify_admin'];
        $optout = $row ? (bool) $row->client_can_optout : (bool) $meta['client_can_optout'];

        echo '<tr>'
            . '<td align="center"><input type="checkbox" name="enabled[' . $e($key) . ']"' . ($enabled ? ' checked' : '') . '></td>'
            . '<td><strong>' . $e($meta['label']) . '</strong><br><span class="text-muted">' . $e($meta['client_description']) . '</span></td>'
            . '<td align="center"><input type="checkbox" name="notify_admin[' . $e($key) . ']"' . ($notifyAdmin ? ' checked' : '') . '></td>'
            . '<td align="center"><input type="checkbox" name="client_can_optout[' . $e($key) . ']"' . ($optout ? ' checked' : '') . '></td>'
            . '</tr>';
    }

    echo '</table>'
        . '<p class="text-muted" style="margin-top:8px;">"Copy admin" also texts the admin mobile. Untick "client can opt out" for service-critical alerts you always want delivered.</p>'
        . '<button type="submit" class="btn btn-primary">Save event settings</button>'
        . '</form></div>';

    // ---- Templates tab ----------------------------------------------------
    echo '<div class="beem-pane" id="beem-pane-templates" style="padding-top:15px;' . ($activeTab === 'templates' ? '' : 'display:none;') . '">'
        . '<form method="post" action="' . $e($modulelink) . '">'
        . '<input type="hidden" name="beem_action" value="save_templates">'
        . '<table class="datatable" width="100%" cellspacing="1"><tr>'
        . '<th width="160">Event</th><th width="42%">Client SMS</th><th>Admin copy SMS (sent when "Copy admin" is on)</th></tr>';

    foreach (Events::all() as $key => $meta) {
        $row = isset($eventRows[$key]) ? $eventRows[$key] : null;
        $template = $row ? $row->template : $meta['template'];
        $adminTemplate = ($row && isset($row->admin_template) && trim((string) $row->admin_template) !== '')
            ? $row->admin_template
            : $meta['admin_template'];
        echo '<tr>'
            . '<td><strong>' . $e($meta['label']) . '</strong></td>'
            . '<td><textarea name="template[' . $e($key) . ']" rows="3" style="width:100%;">' . $e($template) . '</textarea></td>'
            . '<td><textarea name="admin_template[' . $e($key) . ']" rows="3" style="width:100%;">' . $e($adminTemplate) . '</textarea></td>'
            . '</tr>';
    }

    echo '</table>'
        . '<p style="margin-top:8px;">Merge fields: <code>{firstname}</code> <code>{lastname}</code> <code>{client}</code> (full name) <code>{companyname}</code> (client\'s company) <code>{mycompany}</code> (your company) <code>{invoicenum}</code> <code>{amount}</code> <code>{total}</code> (invoice total) <code>{balance}</code> (amount still owed) <code>{duedate}</code> <code>{link}</code> <code>{service}</code> <code>{ticketid}</code> <code>{reminder}</code>.<br>'
        . 'On payment events {amount} is the amount just paid. Keep messages near 160 characters — longer texts are billed as multiple segments. Special characters are automatically converted to SMS-safe ones.</p>'
        . '<button type="submit" class="btn btn-primary">Save templates</button> '
        . '</form>'
        . '<form method="post" action="' . $e($modulelink) . '" style="display:inline;" onsubmit="return confirm(\'Replace ALL templates with the defaults?\');">'
        . '<input type="hidden" name="beem_action" value="reset_templates">'
        . '<button type="submit" class="btn btn-default">Restore default templates</button>'
        . '</form></div>';

    echo '<div class="beem-pane" id="beem-pane-logs" style="padding-top:15px;' . ($activeTab === 'logs' ? '' : 'display:none;') . '">'
        . '<form method="post" action="' . $e($modulelink) . '" class="form-inline" style="margin-bottom:12px;">'
        . '<input type="hidden" name="beem_action" value="send_test">'
        . '<div class="input-group" style="width:200px; display:inline-table; vertical-align:middle; margin-right:4px;">'
        . '<span class="input-group-addon">+255</span>'
        . '<input type="text" name="test_phone" class="form-control" placeholder="746 789 890">'
        . '</div>'
        . '<input type="text" name="test_message" class="form-control" placeholder="Message" style="width:420px;"> '
        . '<button type="submit" class="btn btn-default">Send SMS</button>'
        . '</form>'
        . '<table class="datatable" width="100%" cellspacing="1"><tr>'
        . '<th width="125">Time</th><th width="140">Event</th><th width="65">Client</th><th width="110">Number</th><th width="85">Status</th><th>Message</th><th width="75"></th></tr>';

    foreach ($logs as $log) {
        $statusLabel = $log->status === 'submitted' ? 'success' : ($log->status === 'failed' ? 'danger' : 'default');
        $resend = '';
        if ($log->phone !== '') {
            $resend = '<form method="post" action="' . $e($modulelink) . '" style="margin:0;">'
                . '<input type="hidden" name="beem_action" value="resend">'
                . '<input type="hidden" name="log_id" value="' . (int) $log->id . '">'
                . '<button type="submit" class="btn btn-default btn-xs">Resend</button>'
                . '</form>';
        }
        echo '<tr>'
            . '<td>' . $e($log->created_at) . '</td>'
            . '<td>' . $e($log->event_key) . '</td>'
            . '<td>' . ($log->clientid ? '<a href="clientssummary.php?userid=' . (int) $log->clientid . '">#' . (int) $log->clientid . '</a>' : '-') . '</td>'
            . '<td>' . $e($log->phone) . '</td>'
            . '<td><span class="label label-' . $statusLabel . '">' . $e($log->status) . '</span></td>'
            . '<td>' . $e(mb_substr($log->message, 0, 80)) . '</td>'
            . '<td>' . $resend . '</td>'
            . '</tr>';
    }
    if (count($logs) === 0) {
        echo '<tr><td colspan="7">Nothing sent yet.</td></tr>';
    }
    echo '</table></div>';

    echo '<div class="beem-pane" id="beem-pane-api" style="padding-top:15px;' . ($activeTab === 'api' ? '' : 'display:none;') . '">'
        . '<p class="text-muted">Raw Beem responses for the last 50 messages — the first place to look when a send fails. Full request/response pairs are also in System Logs &gt; Module Log (module <code>beemsms</code>, requires debug logging enabled).</p>'
        . '<table class="datatable" width="100%" cellspacing="1"><tr>'
        . '<th width="125">Time</th><th width="140">Event</th><th width="110">Number</th><th width="85">Status</th><th width="100">Request ID</th><th>API response</th></tr>';

    foreach ($logs as $log) {
        $statusLabel = $log->status === 'submitted' ? 'success' : ($log->status === 'failed' ? 'danger' : 'default');
        echo '<tr>'
            . '<td>' . $e($log->created_at) . '</td>'
            . '<td>' . $e($log->event_key) . '</td>'
            . '<td>' . $e($log->phone) . '</td>'
            . '<td><span class="label label-' . $statusLabel . '">' . $e($log->status) . '</span></td>'
            . '<td>' . $e($log->request_id !== null ? $log->request_id : '-') . '</td>'
            . '<td><code style="font-size:11px;white-space:normal;word-break:break-all;">' . $e(mb_substr((string) $log->response, 0, 300)) . '</code></td>'
            . '</tr>';
    }
    if (count($logs) === 0) {
        echo '<tr><td colspan="6">Nothing sent yet.</td></tr>';
    }
    echo '</table></div>';
}

function beemsms_clientarea($vars)
{
    $clientId = isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : 0;

    if (empty($_SESSION['beemsms_token'])) {
        $_SESSION['beemsms_token'] = bin2hex(random_bytes(16));
    }
    $token = $_SESSION['beemsms_token'];

    $saved = false;
    if ($clientId > 0
        && $_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['beem_token'])
        && hash_equals($token, (string) $_POST['beem_token'])
    ) {
        Sender::setClientPref($clientId, '*', isset($_POST['sms_master']));
        foreach (Events::all() as $key => $meta) {
            $row = Capsule::table('mod_beemsms_events')->where('event_key', $key)->first();
            if ($row && $row->enabled && $row->client_can_optout) {
                Sender::setClientPref($clientId, $key, isset($_POST['evt'][$key]));
            }
        }
        $saved = true;
    }

    $events = [];
    $seenClientLabels = [];
    foreach (Events::all() as $key => $meta) {
        $row = Capsule::table('mod_beemsms_events')->where('event_key', $key)->first();
        if (!$row || !$row->enabled) {
            continue;
        }
        $label = $meta['client_label'];
        if (isset($seenClientLabels[$label])) {
            continue;
        }
        $seenClientLabels[$label] = true;
        $events[] = [
            'key' => $key,
            'label' => $label,
            'description' => $meta['client_description'],
            'client_can_optout' => (bool) $row->client_can_optout,
            'enabled' => $clientId > 0 ? Sender::clientAllows($clientId, $key) : true,
        ];
    }

    $phone = '';
    if ($clientId > 0) {
        $client = Capsule::table('tblclients')->where('id', $clientId)->first();
        $phone = $client ? $client->phonenumber : '';
    }

    return [
        'pagetitle' => 'SMS notifications',
        'breadcrumb' => ['index.php?m=beemsms' => 'SMS notifications'],
        'templatefile' => 'clientarea',
        'requirelogin' => true,
        'vars' => [
            'modulelink' => $vars['modulelink'],
            'csrf' => $token,
            'saved' => $saved,
            'master' => $clientId > 0 ? Sender::clientAllows($clientId, '*') : true,
            'events' => $events,
            'phone' => $phone,
        ],
    ];
}
