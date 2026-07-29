<?php
if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;
use BeemSms\Sender;

if (!function_exists('beemsms_once')) {
    
    function beemsms_once($key)
    {
        try {
            Capsule::table('mod_beemsms_meta')->insert([
                'meta_key' => substr((string) $key, 0, 50),
                'meta_value' => (string) time(),
            ]);

            return true;
        } catch (\Throwable $e) {
            return false; // duplicate key (or DB trouble) - err on not sending twice
        }
    }
}

if (!function_exists('beemsms_payment_request_guard')) {

    function beemsms_payment_request_guard($invoiceId, $markOnly = false)
    {
        static $seen = [];
        if ($markOnly) {
            $seen[$invoiceId] = true;

            return true;
        }
        if (isset($seen[$invoiceId])) {
            return false;
        }
        $seen[$invoiceId] = true;

        return true;
    }
}

if (!function_exists('beemsms_service_hook')) {
    function beemsms_service_hook($eventKey)
    {
        return function ($vars) use ($eventKey) {
        try {
            $params = isset($vars['params']) ? $vars['params'] : [];
            $details = isset($params['clientsdetails']) ? $params['clientsdetails'] : [];
            $clientId = 0;
            if (isset($details['userid'])) {
                $clientId = (int) $details['userid'];
            } elseif (isset($details['id'])) {
                $clientId = (int) $details['id'];
            }

            $service = '';
            $serviceId = isset($params['serviceid']) ? (int) $params['serviceid'] : 0;
            if ($serviceId) {
                $row = Capsule::table('tblhosting')
                    ->join('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
                    ->where('tblhosting.id', $serviceId)
                    ->select('tblproducts.name', 'tblhosting.domain')
                    ->first();
                if ($row) {
                    $domain = isset($row->domain) ? trim((string) $row->domain) : '';
                    $service = $row->name . ($domain !== '' ? ' (' . $domain . ')' : '');
                }
                if ($clientId === 0) {
                    $hosting = Capsule::table('tblhosting')->where('id', $serviceId)->first();
                    $clientId = $hosting ? (int) $hosting->userid : 0;
                }
            }

            if ($clientId > 0) {
                Sender::trigger($eventKey, $clientId, ['service' => $service]);
            }
        } catch (\Throwable $e) {
            Sender::swallow($eventKey, $e);
        }
        };
    }
}
