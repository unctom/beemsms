<?php
/**
 * Beem SMS for WHMCS — Beem Africa API client.
 *
 * @license MIT
 * @link    https://github.com/unctom/beemsms
 */

namespace BeemSms;

class BeemClient
{
    const SEND_URL = 'https://apisms.beem.africa/v1/send';
    const BALANCE_URL = 'https://apisms.beem.africa/public/v1/vendors/balance';
    const TIMEOUT = 15;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $secretKey;

    /** @var string */
    private $senderId;

    public function __construct($apiKey, $secretKey, $senderId)
    {
        $this->apiKey = trim((string) $apiKey);
        $this->secretKey = trim((string) $secretKey);
        $this->senderId = trim((string) $senderId);
    }

    public function isConfigured()
    {
        return $this->apiKey !== '' && $this->secretKey !== '' && $this->senderId !== '';
    }

    /**
     * Send one message to one or more numbers (international format, digits only).
     *
     * @param string   $message
     * @param string[] $numbers
     *
     * @return array{success:bool,http_status:int,code:mixed,request_id:mixed,message:string,raw:string}
     */
    public function send($message, array $numbers)
    {
        $recipients = [];
        $i = 1;
        foreach ($numbers as $number) {
            $recipients[] = ['recipient_id' => $i++, 'dest_addr' => (string) $number];
        }

        $payload = [
            'source_addr' => $this->senderId,
            'schedule_time' => '',
            'encoding' => 0,
            'message' => self::sanitize($message),
            'recipients' => $recipients,
        ];

        list($status, $body, $error) = $this->request('POST', self::SEND_URL, $payload);
        $data = json_decode((string) $body, true);
        $data = is_array($data) ? $data : [];

        $success = $status >= 200 && $status < 300 && (int) ($data['code'] ?? 0) === 100;

        return [
            'success' => $success,
            'http_status' => $status,
            'code' => $data['code'] ?? null,
            'request_id' => $data['request_id'] ?? null,
            'message' => (string) ($data['message'] ?? ($error ?: $body)),
            'raw' => (string) ($body !== '' ? $body : $error),
        ];
    }

    /**
     * Fetch remaining SMS credit balance.
     *
     * @return array{success:bool,credit_balance:?float,raw:string}
     */
    public function balance()
    {
        list($status, $body, $error) = $this->request('GET', self::BALANCE_URL);
        $data = json_decode((string) $body, true);
        $balance = null;

        if (is_array($data)) {
            if (isset($data['data']['credit_balance'])) {
                $balance = (float) $data['data']['credit_balance'];
            } elseif (isset($data['credit_balance'])) {
                $balance = (float) $data['credit_balance'];
            }
        }

        return [
            'success' => $status >= 200 && $status < 300 && $balance !== null,
            'credit_balance' => $balance,
            'raw' => (string) ($body !== '' ? $body : $error),
        ];
    }

    /**
     * Replace typographic characters that fall outside the GSM-7 charset
     * (em dashes, curly quotes, ellipsis) with SMS-safe equivalents.
     * Prevents gateway rejections when sending with encoding 0.
     *
     * @param string $message
     *
     * @return string
     */
    public static function sanitize($message)
    {
        $map = [
            "\u{2014}" => '-',
            "\u{2013}" => '-',
            "\u{2212}" => '-',
            "\u{2018}" => "'",
            "\u{2019}" => "'",
            "\u{201A}" => "'",
            "\u{2032}" => "'",
            "\u{201C}" => '"',
            "\u{201D}" => '"',
            "\u{201E}" => '"',
            "\u{2026}" => '...',
            "\u{00A0}" => ' ',
            "\u{2022}" => '-',
        ];

        return strtr((string) $message, $map);
    }

    /**
     * @return array{0:int,1:string,2:string} [http status, body, curl error]
     */
    private function request($method, $url, ?array $payload = null)
    {
        $ch = curl_init();
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($this->apiKey . ':' . $this->secretKey),
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, is_string($body) ? $body : '', (string) $error];
    }
}
