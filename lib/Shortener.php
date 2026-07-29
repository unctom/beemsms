<?php
/**
 * Beem SMS for WHMCS — optional link shortening for SMS bodies.
 *
 * Long WHMCS URLs (viewinvoice.php?id=..., viewticket.php?tid=...) eat SMS
 * characters. When enabled in the module configuration, {link} values are
 * shortened before the message is built, via one of:
 *   - TinyURL or da.gd  — free, keyless public services (if the chosen one
 *     fails, the other is tried);
 *   - YOURLS            — your own self-hosted shortener on a branded domain
 *     (best SMS deliverability; no public fallback — a branded link should
 *     never silently become a tinyurl.com link).
 * Results are cached in mod_beemsms_meta so each URL is only shortened
 * once. Any failure — timeout, error response, odd body — falls back to
 * the original URL: shortening must never block a send.
 *
 * @license MIT
 * @link    https://github.com/unctom/beemsms
 */

namespace BeemSms;

use WHMCS\Database\Capsule;

class Shortener
{
    const TIMEOUT = 5;
    const FAIL_RETRY_SECONDS = 900;

    /** @var bool In-request latch once both providers have failed, so a cron
     *            run pays the timeout cost at most once. */
    private static $unavailable = false;

    /**
     * Shorten a URL per the configured provider. Returns the original URL
     * when shortening is disabled, unnecessary, or fails. Ticket links are
     * never shortened: they carry an access code (c=...) that opens the
     * ticket without login, and public short URLs are enumerable.
     *
     * @param string $url
     *
     * @return string
     */
    public static function shorten($url)
    {
        try {
            $url = trim((string) $url);
            if ($url === '' || stripos($url, 'http') !== 0) {
                return $url;
            }
            if (stripos($url, 'viewticket.php') !== false) {
                return $url;
            }
            if (self::$unavailable) {
                return $url;
            }

            $settings = Sender::moduleSettings();
            $provider = isset($settings['shorten_links']) ? strtolower(trim($settings['shorten_links'])) : '';
            if ($provider === '' || $provider === 'disabled') {
                return $url;
            }

            $cacheKey = 'short_' . sha1($url);
            $cached = self::cacheGet($cacheKey, $url);
            if ($cached === false) {
                return $url; // recent failure cached - do not retry yet
            }
            if ($cached !== null) {
                return $cached;
            }

            $short = null;
            if ($provider === 'yourls') {
                // Your own shortener only - never fall through to a public one.
                $short = self::yourls($url, $settings);
            } else {
                $order = ($provider === 'da.gd' || $provider === 'dagd')
                    ? ['dagd', 'tinyurl']
                    : ['tinyurl', 'dagd'];
                foreach ($order as $name) {
                    $candidate = ($name === 'tinyurl')
                        ? self::fetch('https://tinyurl.com/api-create.php?url=' . rawurlencode($url), '#^https://tinyurl\.com/\S+$#')
                        : self::fetch('https://da.gd/shorten?url=' . rawurlencode($url), '#^https://da\.gd/\S+$#');
                    if ($candidate !== null && strlen($candidate) < strlen($url)) {
                        $short = $candidate;
                        break;
                    }
                }
            }

            if ($short !== null && strlen($short) < strlen($url)) {
                self::cachePut($cacheKey, $url, $short);

                return $short;
            }

            // Provider failed: latch for this request and negative-cache
            // briefly so later requests do not re-pay the timeouts either.
            self::$unavailable = true;
            self::cachePut($cacheKey, $url, '');
        } catch (\Throwable $e) {
            Sender::swallow('shortener', $e);
        }

        return $url;
    }

    /**
     * Shorten via a self-hosted YOURLS install using the passwordless
     * signature token. Returns a short URL on the configured YOURLS host, or
     * null on any failure (missing config, bad signature, unreachable, or a
     * response that is not a URL on that exact host).
     *
     * API: GET {yourls_url}/yourls-api.php?signature=..&action=shorturl&format=simple&url=..
     *
     * @return string|null
     */
    private static function yourls($url, array $settings)
    {
        $base = isset($settings['yourls_url']) ? trim((string) $settings['yourls_url']) : '';
        $sig = isset($settings['yourls_signature']) ? trim((string) $settings['yourls_signature']) : '';
        if ($base === '' || $sig === '' || stripos($base, 'http') !== 0) {
            return null;
        }
        $base = rtrim($base, '/');
        $host = parse_url($base, PHP_URL_HOST);
        if (!$host) {
            return null;
        }

        $api = $base . '/yourls-api.php?signature=' . rawurlencode($sig)
            . '&action=shorturl&format=simple&url=' . rawurlencode($url);

        // Pin the accepted response to the configured YOURLS host so a bad
        // signature (which returns an error string, not a URL) is rejected.
        $expect = '#^https?://' . preg_quote((string) $host, '#') . '/\S+$#i';

        return self::fetch($api, $expect);
    }

    /**
     * @param string $apiUrl
     * @param string $expect Regex the (trimmed) response body must match —
     *                       pinned to the provider's own domain so an error
     *                       page can never end up inside an SMS.
     *
     * @return string|null A valid short URL, or null on any failure.
     */
    private static function fetch($apiUrl, $expect)
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'beem-sms-whmcs',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status !== 200 || !is_string($body)) {
            return null;
        }

        $body = trim($body);
        if ($body === '' || strlen($body) > 100 || !preg_match($expect, $body)) {
            return null;
        }

        return $body;
    }

    /**
     * @return string|false|null Short URL on a hit, false when a failure was
     *                           cached recently (skip retry), null when
     *                           uncached (or the failure entry expired).
     */
    private static function cacheGet($key, $url)
    {
        $row = Capsule::table('mod_beemsms_meta')->where('meta_key', $key)->first();
        if (!$row) {
            return null;
        }
        $data = json_decode($row->meta_value, true);
        if (!is_array($data) || !isset($data['u'], $data['s']) || $data['u'] !== $url) {
            return null;
        }
        if ($data['s'] === '') {
            $t = isset($data['t']) ? (int) $data['t'] : 0;

            return (time() - $t < self::FAIL_RETRY_SECONDS) ? false : null;
        }

        return (string) $data['s'];
    }

    private static function cachePut($key, $url, $short)
    {
        Capsule::table('mod_beemsms_meta')->updateOrInsert(
            ['meta_key' => $key],
            ['meta_value' => json_encode(['u' => $url, 's' => $short, 't' => time()])]
        );
    }

    /**
     * Remove cached short links older than $days. Called by the daily cron.
     */
    public static function prune($days = 180)
    {
        try {
            $cutoff = time() - ($days * 86400);
            $rows = Capsule::table('mod_beemsms_meta')->where('meta_key', 'like', 'short\_%')->get();
            foreach ($rows as $row) {
                $data = json_decode($row->meta_value, true);
                if (!is_array($data) || !isset($data['t']) || (int) $data['t'] < $cutoff) {
                    Capsule::table('mod_beemsms_meta')->where('meta_key', $row->meta_key)->delete();
                }
            }
        } catch (\Throwable $e) {
            Sender::swallow('shortener_prune', $e);
        }
    }
}
