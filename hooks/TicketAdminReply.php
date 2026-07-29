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

add_hook('TicketAdminReply', 1, function ($vars) {
    try {
        $ticketId = isset($vars['ticketid']) ? (int) $vars['ticketid'] : 0;
        if (!$ticketId) {
            return;
        }
        $ticket = Capsule::table('tbltickets')->where('id', $ticketId)->first();
        if ($ticket && (int) $ticket->userid > 0) {
            Sender::trigger('ticket_reply', (int) $ticket->userid, [
                'ticketid' => $ticket->tid,
                'subject' => $ticket->title,
                'link' => Sender::systemUrl() . 'viewticket.php?tid=' . $ticket->tid . '&c=' . $ticket->c,
            ]);
        }
    } catch (\Throwable $e) {
        Sender::swallow('TicketAdminReply', $e);
    }
});
