<?php
/**
 * Beem SMS for WHMCS — notification event registry.
 *
 * Merge fields: {companyname} is the CLIENT's company name;
 * {mycompany} is your WHMCS company name. Defaults avoid naming your
 * company — the approved sender ID already identifies you.
 *
 * @license MIT
 * @link    https://github.com/unctom/beemsms
 */

namespace BeemSms;

use WHMCS\Database\Capsule;

class Events
{
    /**
     * Every supported notification event, with white-label default templates.
     * client_can_optout=0 events are always sent while enabled (service-critical).
     * admin_template is used for the "copy admin" SMS; {client} is the client's
     * full name. All defaults are plain ASCII (GSM-7 safe).
     *
     * @return array<string,array>
     */
    public static function all()
    {
        return [
            'invoice_created' => [
                'label' => 'Invoice created',
                'client_label' => 'New invoices',
                'client_description' => 'A text the moment an invoice is ready, with the amount and due date.',
                'template' => 'Hello {firstname}, invoice {invoicenum} of {amount} is ready, due {duedate}. Pay: {link}',
                'admin_template' => 'Invoice {invoicenum} ({amount}) created for {client}, due {duedate}.',
                'enabled' => 1,
                'notify_admin' => 0,
                'client_can_optout' => 1,
            ],
            'invoice_paid' => [
                'label' => 'Payment received',
                'client_label' => 'Payment confirmations',
                'client_description' => 'Instant confirmation whenever a payment reaches us, including part payments.',
                'template' => 'Thank you {firstname}! Your payment of {amount} for invoice {invoicenum} has been received.',
                'admin_template' => 'Payment received: {client} paid {amount} on invoice {invoicenum}. Remaining balance: {balance}.',
                'enabled' => 1,
                'notify_admin' => 1,
                'client_can_optout' => 1,
            ],
            'payment_reminder' => [
                'label' => 'Payment reminder (1st-3rd)',
                'client_label' => 'Payment reminders',
                'client_description' => 'Gentle nudges before and after the due date.',
                'template' => 'Hello {firstname}, a friendly reminder: invoice {invoicenum} of {amount} is due {duedate}. Pay: {link}',
                'admin_template' => '{reminder} payment reminder sent to {client} for invoice {invoicenum} ({amount}).',
                'enabled' => 1,
                'notify_admin' => 1,
                'client_can_optout' => 1,
            ],
            'invoice_late_fee' => [
                'label' => 'Late fee added',
                'client_label' => 'Late fee alerts',
                'client_description' => 'Notice when a late fee is added to an overdue invoice.',
                'template' => 'Hello {firstname}, a late fee has been added to invoice {invoicenum}. The new total is {amount}. Pay: {link}',
                'admin_template' => 'Late fee added to invoice {invoicenum} for {client}. New total: {amount}.',
                'enabled' => 1,
                'notify_admin' => 0,
                'client_can_optout' => 0,
            ],
            'service_created' => [
                'label' => 'Service activated',
                'client_label' => 'Service activation',
                'client_description' => 'Know the moment your order is accepted and your service goes live.',
                'template' => 'Hello {firstname}, your order has been accepted and {service} is now active. Welcome aboard!',
                'admin_template' => 'Order accepted: {service} is now active for {client}.',
                'enabled' => 1,
                'notify_admin' => 0,
                'client_can_optout' => 1,
            ],
            'service_suspended' => [
                'label' => 'Service suspended',
                'client_label' => 'Service alerts',
                'client_description' => 'Suspension and restoration notices for your services.',
                'template' => 'Hello {firstname}, your service {service} has been suspended. Settle the outstanding balance and it will be restored right away.',
                'admin_template' => 'Service suspended: {service} for {client}.',
                'enabled' => 1,
                'notify_admin' => 1,
                'client_can_optout' => 0,
            ],
            'service_unsuspended' => [
                'label' => 'Service restored',
                'client_label' => 'Service alerts',
                'client_description' => 'Suspension and restoration notices for your services.',
                'template' => 'Good news {firstname} - your service {service} is back online. Thank you!',
                'admin_template' => 'Service restored: {service} for {client}.',
                'enabled' => 1,
                'notify_admin' => 0,
                'client_can_optout' => 0,
            ],
            'client_add' => [
                'label' => 'New client welcome',
                'client_label' => 'Welcome message',
                'client_description' => 'A one-time hello when your account is created.',
                'template' => 'Welcome {firstname}! Your account with {mycompany} is ready. We are glad to have you.',
                'admin_template' => 'New client signup: {client}.',
                'enabled' => 0,
                'notify_admin' => 0,
                'client_can_optout' => 1,
            ],
            'ticket_reply' => [
                'label' => 'Support ticket reply',
                'client_label' => 'Support replies',
                'client_description' => 'Know when our team has answered your ticket.',
                'template' => 'Hello {firstname}, we have replied to your ticket #{ticketid}. View it here: {link}',
                'admin_template' => 'Staff reply sent on ticket #{ticketid} for {client}.',
                'enabled' => 0,
                'notify_admin' => 0,
                'client_can_optout' => 1,
            ],
        ];
    }

    /**
     * @return array|null
     */
    public static function get($key)
    {
        $all = self::all();

        return isset($all[$key]) ? $all[$key] : null;
    }

    /**
     * Insert default rows for any events missing from mod_beemsms_events,
     * and backfill admin_template on rows created before it existed.
     */
    public static function seed()
    {
        $hasAdminColumn = false;
        try {
            $hasAdminColumn = Capsule::schema()->hasColumn('mod_beemsms_events', 'admin_template');
        } catch (\Throwable $e) {
            $hasAdminColumn = false;
        }

        foreach (self::all() as $key => $meta) {
            $row = Capsule::table('mod_beemsms_events')->where('event_key', $key)->first();
            if (!$row) {
                $insert = [
                    'event_key' => $key,
                    'enabled' => (int) $meta['enabled'],
                    'notify_admin' => (int) $meta['notify_admin'],
                    'client_can_optout' => (int) $meta['client_can_optout'],
                    'template' => $meta['template'],
                ];
                if ($hasAdminColumn) {
                    $insert['admin_template'] = $meta['admin_template'];
                }
                Capsule::table('mod_beemsms_events')->insert($insert);
            } elseif ($hasAdminColumn && (!isset($row->admin_template) || $row->admin_template === null || trim((string) $row->admin_template) === '')) {
                Capsule::table('mod_beemsms_events')->where('event_key', $key)->update([
                    'admin_template' => $meta['admin_template'],
                ]);
            }
        }
    }
}
