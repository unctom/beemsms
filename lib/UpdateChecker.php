<?php
/**
 * Beem SMS for WHMCS — update notifications via GitHub releases.
 *
 * Checks the public releases feed at most once per cache window and never
 * blocks page loads: the dashboard reads the cached result only, the daily
 * cron (or the "Check for updates" button) refreshes it.
 *
 * @license MIT
 * @link    https://github.com/unctom/beemsms
 */

namespace BeemSms;

use WHMCS\Database\Capsule;

class UpdateChecker
{
    const VERSION = '0.2.1';
    const RELEASES_API = 'https://api.github.com/repos/unctom/beemsms/releases/latest';
    const RELEASES_PAGE = 'https://github.com/unctom/beemsms/releases';
    const CACHE_SECONDS = 72000;

    /**
     * @return array|null Cached check result {checked_at, latest, url} or null.
     */
    public static function cached()
    {
        try {
            $row = Capsule::table('mod_beemsms_meta')->where('meta_key', 'update_check')->first();
            if (!$row) {
                return null;
            }
            $data = json_decode($row->meta_value, true);

            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Fetch the latest release from GitHub (respecting the cache unless forced).
     *
     * @param bool $force
     *
     * @return array {checked_at, latest, url}
     */
    public static function check($force = false)
    {
        $cached = self::cached();
        if (!$force && $cached && isset($cached['checked_at']) && (time() - (int) $cached['checked_at']) < self::CACHE_SECONDS) {
            return $cached;
        }

        $data = ['checked_at' => time(), 'latest' => null, 'url' => self::RELEASES_PAGE];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::RELEASES_API,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'User-Agent: beem-sms-whmcs',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status === 200 && is_string($body)) {
            $json = json_decode($body, true);
            if (is_array($json) && !empty($json['tag_name'])) {
                $data['latest'] = ltrim((string) $json['tag_name'], 'vV');
                if (!empty($json['html_url'])) {
                    $data['url'] = (string) $json['html_url'];
                }
            }
        }

        self::store($data);

        return $data;
    }

    /**
     * @param array|null $data Result from check()/cached(); reads cache when null.
     *
     * @return bool
     */
    public static function updateAvailable($data = null)
    {
        $data = $data !== null ? $data : self::cached();

        return is_array($data)
            && !empty($data['latest'])
            && version_compare($data['latest'], self::VERSION, '>');
    }

    private static function store(array $data)
    {
        try {
            Capsule::table('mod_beemsms_meta')->updateOrInsert(
                ['meta_key' => 'update_check'],
                ['meta_value' => json_encode($data)]
            );
        } catch (\Throwable $e) {
            // cache is best-effort; never break the caller
        }
    }
}
