<?php
/**
 * Beem SMS for WHMCS — one-click, admin-initiated updater.
 *
 * Downloads the latest GitHub release asset, verifies it, and swaps it in
 * atomically with a retained backup and automatic rollback if the new code
 * fails to load. This is deliberately ADMIN-INITIATED only (a human clicks
 * "Update now") — there is no unattended/silent auto-update. Self-overwriting
 * code on third-party billing servers is a supply-chain risk (decision D7);
 * a human in the loop is the safeguard, and unattended mode is intentionally
 * not offered because it would need offline release signing to be defensible.
 *
 * Every method is defensive and returns a structured result — it must never
 * throw into WHMCS, and a half-finished update must never leave a broken
 * module on disk (the live folder only ever changes via atomic rename).
 *
 * @license MIT
 * @link    https://github.com/unctom/beemsms
 */

namespace BeemSms;

class Updater
{
    const DOWNLOAD_TIMEOUT = 60;
    const HEALTH_TIMEOUT = 20;
    const MIN_FREE_BYTES = 20971520; // 20 MB headroom before we attempt anything
    const MAX_DOWNLOAD_BYTES = 33554432; // 32 MB ceiling - the module zip is tiny

    /**
     * Live module directory (…/modules/addons/beemsms).
     */
    public static function moduleDir()
    {
        return dirname(__DIR__);
    }

    /**
     * Parent addons directory (…/modules/addons) — where the backup and the
     * staged copy live so the swap is a same-filesystem atomic rename.
     */
    public static function addonsDir()
    {
        return dirname(self::moduleDir());
    }

    private static function name()
    {
        return basename(self::moduleDir());
    }

    private static function stagingDir()
    {
        return self::moduleDir() . DIRECTORY_SEPARATOR . '.update';
    }

    private static function newDir()
    {
        return self::addonsDir() . DIRECTORY_SEPARATOR . self::name() . '.new';
    }

    private static function backupDir()
    {
        return self::addonsDir() . DIRECTORY_SEPARATOR . self::name() . '.bak';
    }

    /**
     * Dashboard-facing status: can we offer the "Update now" button, is a
     * backup available to roll back to, and why not if not.
     *
     * @return array{updatable:bool,reason:string,has_backup:bool,latest:?string,current:string}
     */
    public static function status()
    {
        $current = UpdateChecker::VERSION;
        $cached = UpdateChecker::cached();
        $latest = is_array($cached) && !empty($cached['latest']) ? (string) $cached['latest'] : null;

        $pre = self::preflight();
        $updateAvailable = UpdateChecker::updateAvailable($cached);

        return [
            'updatable' => $updateAvailable && $pre['ok'],
            'reason' => $updateAvailable ? ($pre['ok'] ? '' : $pre['error']) : '',
            'has_backup' => is_dir(self::backupDir()),
            'latest' => $latest,
            'current' => $current,
        ];
    }

    /**
     * Environment gate. Cheap checks only — never mutates anything.
     *
     * @return array{ok:bool,error:string}
     */
    public static function preflight()
    {
        if (!class_exists('\\ZipArchive')) {
            return ['ok' => false, 'error' => 'PHP Zip extension (ZipArchive) is not available on this server, so one-click updates are disabled. Update by uploading the release folder manually.'];
        }
        if (!is_writable(self::addonsDir())) {
            return ['ok' => false, 'error' => 'The modules/addons directory is not writable by PHP, so files cannot be swapped. Update manually, or ask your host to allow PHP to write there.'];
        }
        if (!is_writable(self::moduleDir())) {
            return ['ok' => false, 'error' => 'The module directory is not writable by PHP. Update manually or adjust permissions.'];
        }
        if (function_exists('disk_free_space')) {
            $free = @disk_free_space(self::addonsDir());
            if ($free !== false && $free < self::MIN_FREE_BYTES) {
                return ['ok' => false, 'error' => 'Not enough free disk space to update safely.'];
            }
        }

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Download, verify, and swap in the latest release. Admin-initiated only.
     *
     * @return array{success:bool,message:string,rolled_back:bool}
     */
    public static function applyLatest()
    {
        $pre = self::preflight();
        if (!$pre['ok']) {
            return ['success' => false, 'message' => $pre['error'], 'rolled_back' => false];
        }

        // Clean any leftovers from a previous interrupted run before staging.
        self::rrmdir(self::stagingDir());
        self::rrmdir(self::newDir());

        try {
            $release = self::fetchLatestRelease();
            if (!$release) {
                return ['success' => false, 'message' => 'Could not read the latest release from GitHub. Try again later.', 'rolled_back' => false];
            }
            if (version_compare($release['version'], UpdateChecker::VERSION, '<=')) {
                return ['success' => false, 'message' => 'You are already on the latest version (' . UpdateChecker::VERSION . ').', 'rolled_back' => false];
            }
            if ($release['asset_url'] === '') {
                return ['success' => false, 'message' => 'The latest release has no downloadable package attached. Update manually from the releases page.', 'rolled_back' => false];
            }

            if (!@mkdir(self::stagingDir(), 0755, true) && !is_dir(self::stagingDir())) {
                return ['success' => false, 'message' => 'Could not create a staging folder to download into.', 'rolled_back' => false];
            }

            $zipPath = self::stagingDir() . DIRECTORY_SEPARATOR . 'download.zip';
            $body = self::httpGet($release['asset_url'], self::DOWNLOAD_TIMEOUT, '', self::MAX_DOWNLOAD_BYTES);
            if ($body === null) {
                self::rrmdir(self::stagingDir());

                return ['success' => false, 'message' => 'The release download failed or was empty.', 'rolled_back' => false];
            }
            if ($release['asset_size'] > 0 && strlen($body) !== $release['asset_size']) {
                self::rrmdir(self::stagingDir());

                return ['success' => false, 'message' => 'The downloaded package was incomplete (size mismatch) — not applying.', 'rolled_back' => false];
            }
            if (@file_put_contents($zipPath, $body) === false) {
                self::rrmdir(self::stagingDir());

                return ['success' => false, 'message' => 'Could not write the downloaded package to disk.', 'rolled_back' => false];
            }
            unset($body);

            $verify = self::verifyAndExtract($zipPath);
            if (!$verify['ok']) {
                self::rrmdir(self::stagingDir());

                return ['success' => false, 'message' => $verify['error'], 'rolled_back' => false];
            }

            // Move the extracted module out to a sibling of the live folder so
            // the promote is a same-filesystem atomic rename.
            $extracted = self::stagingDir() . DIRECTORY_SEPARATOR . 'staged' . DIRECTORY_SEPARATOR . self::name();
            if (!is_dir($extracted)) {
                self::rrmdir(self::stagingDir());

                return ['success' => false, 'message' => 'The package did not contain the expected module folder.', 'rolled_back' => false];
            }
            self::rrmdir(self::newDir());
            if (!@rename($extracted, self::newDir())) {
                self::rrmdir(self::stagingDir());

                return ['success' => false, 'message' => 'Could not stage the new version for install.', 'rolled_back' => false];
            }

            $from = UpdateChecker::VERSION;
            $to = $release['version'];

            // Back up current, then promote. This two-rename window is the only
            // dangerous moment — keep it to consecutive rename() calls and
            // self-heal on failure.
            self::rrmdir(self::backupDir());
            if (!@rename(self::moduleDir(), self::backupDir())) {
                self::rrmdir(self::newDir());

                return ['success' => false, 'message' => 'Could not back up the current version before updating — nothing was changed.', 'rolled_back' => false];
            }
            if (!@rename(self::newDir(), self::moduleDir())) {
                // Promote failed. Restore the backup; if that also fails, try
                // the staged copy as a last resort so the live folder is never
                // left missing. Only claim "restored" when a rename succeeded.
                if (@rename(self::backupDir(), self::moduleDir())) {
                    self::rrmdir(self::newDir());

                    return ['success' => false, 'message' => 'Could not install the new version - the previous version was restored.', 'rolled_back' => true];
                }
                if (@rename(self::newDir(), self::moduleDir())) {
                    return ['success' => false, 'message' => 'The update did not complete cleanly, but the module was kept in place. Reload and check the version chip.', 'rolled_back' => false];
                }

                return ['success' => false, 'message' => 'Update failed and the module folder is missing. In modules/addons, rename ' . basename(self::backupDir()) . ' back to ' . self::name() . ' to restore it.', 'rolled_back' => false];
            }

            self::invalidateOpcache();
            self::cleanupBackupStaging();

            // Health check on a fresh request that loads the NEW code.
            $health = self::healthCheck();
            if ($health === 'broken') {
                $reverted = self::restoreBackup();
                self::logActivity('Beem SMS: update ' . $from . ' -> ' . $to . ' failed health check; ' . ($reverted ? 'rolled back to ' . $from : 'ROLLBACK FAILED'));

                return [
                    'success' => false,
                    'message' => $reverted
                        ? 'The new version failed to load, so it was rolled back to ' . $from . '. No harm done — please report this.'
                        : 'The new version failed to load AND rollback failed. Restore ' . self::name() . ' from ' . basename(self::backupDir()) . ' manually.',
                    'rolled_back' => $reverted,
                ];
            }

            self::logActivity('Beem SMS: updated ' . $from . ' -> ' . $to . ($health === 'inconclusive' ? ' (health check inconclusive)' : ''));

            $note = 'Updated to v' . $to . '. Reload this page to run on the new version.'
                . ($health === 'inconclusive' ? ' (Could not self-verify the new code over HTTP — if anything looks wrong, use Roll back.)' : '');

            return ['success' => true, 'message' => $note, 'rolled_back' => false];
        } catch (\Throwable $e) {
            self::rrmdir(self::stagingDir());
            self::rrmdir(self::newDir());
            // If the live folder went missing mid-swap, try to self-heal.
            if (!is_dir(self::moduleDir()) && is_dir(self::backupDir())) {
                @rename(self::backupDir(), self::moduleDir());
            }
            Sender::swallow('updater', $e);

            return ['success' => false, 'message' => 'The update stopped on an unexpected error and was not applied.', 'rolled_back' => false];
        }
    }

    /**
     * Restore the retained backup over the current version (manual rollback).
     *
     * @return array{success:bool,message:string}
     */
    public static function rollback()
    {
        try {
            if (!is_dir(self::backupDir())) {
                return ['success' => false, 'message' => 'There is no backup to roll back to.'];
            }
            if (!is_writable(self::addonsDir())) {
                return ['success' => false, 'message' => 'The modules/addons directory is not writable, so rollback is not possible here.'];
            }
            if (self::restoreBackup()) {
                self::invalidateOpcache();
                self::logActivity('Beem SMS: rolled back to the previous version via the dashboard');

                return ['success' => true, 'message' => 'Rolled back to the previous version. Reload this page.'];
            }

            return ['success' => false, 'message' => 'Rollback failed. Restore the folder manually from ' . basename(self::backupDir()) . '.'];
        } catch (\Throwable $e) {
            Sender::swallow('updater_rollback', $e);

            return ['success' => false, 'message' => 'Rollback stopped on an unexpected error.'];
        }
    }

    /**
     * Swap the retained backup back into place. The current (bad) folder is
     * moved aside first so a failed promote never leaves nothing on disk.
     *
     * @return bool
     */
    private static function restoreBackup()
    {
        if (!is_dir(self::backupDir())) {
            return false;
        }
        $aside = self::addonsDir() . DIRECTORY_SEPARATOR . self::name() . '.failed';
        self::rrmdir($aside);
        if (is_dir(self::moduleDir()) && !@rename(self::moduleDir(), $aside)) {
            return false;
        }
        if (!@rename(self::backupDir(), self::moduleDir())) {
            // Put the bad one back so the module still loads.
            @rename($aside, self::moduleDir());

            return false;
        }
        self::rrmdir($aside);

        return true;
    }

    /**
     * @return array{version:string,tag:string,asset_url:string,asset_size:int}|null
     */
    private static function fetchLatestRelease()
    {
        $body = self::httpGet(UpdateChecker::RELEASES_API, 15, 'application/vnd.github+json');
        if ($body === null) {
            return null;
        }
        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['tag_name'])) {
            return null;
        }

        $assetUrl = '';
        $assetSize = 0;
        if (!empty($json['assets']) && is_array($json['assets'])) {
            foreach ($json['assets'] as $asset) {
                $assetName = isset($asset['name']) ? (string) $asset['name'] : '';
                if (substr($assetName, -4) === '.zip'
                    && stripos($assetName, self::name()) !== false
                    && !empty($asset['browser_download_url'])
                ) {
                    $assetUrl = (string) $asset['browser_download_url'];
                    $assetSize = isset($asset['size']) ? (int) $asset['size'] : 0;
                    break;
                }
            }
        }

        return [
            'version' => ltrim((string) $json['tag_name'], 'vV'),
            'tag' => (string) $json['tag_name'],
            'asset_url' => $assetUrl,
            'asset_size' => $assetSize,
        ];
    }

    /**
     * Validate the downloaded zip (structure, no path traversal, sentinels)
     * and extract it to staging/staged. Never extracts a suspicious archive.
     *
     * @return array{ok:bool,error:string}
     */
    private static function verifyAndExtract($zipPath)
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'error' => 'The downloaded package is not a valid zip archive.'];
        }

        $prefix = self::name() . '/';
        $haveMain = false;
        $haveLib = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false) {
                continue;
            }
            $entry = str_replace('\\', '/', $entry);
            // Reject anything that could escape the target (Zip-Slip).
            if (strpos($entry, '../') !== false || strpos($entry, './') === 0 || substr($entry, 0, 1) === '/') {
                $zip->close();

                return ['ok' => false, 'error' => 'The package contained an unsafe file path and was rejected.'];
            }
            // Every entry must live under the expected top-level module folder
            // (the bare folder entry itself is fine).
            if ($entry !== '' && $entry !== self::name() && strpos($entry, $prefix) !== 0) {
                $zip->close();

                return ['ok' => false, 'error' => 'The package layout was unexpected (missing top-level ' . self::name() . '/ folder).'];
            }
            if ($entry === $prefix . 'beemsms.php') {
                $haveMain = true;
            }
            if ($entry === $prefix . 'lib/UpdateChecker.php') {
                $haveLib = true;
            }
        }
        if (!$haveMain || !$haveLib) {
            $zip->close();

            return ['ok' => false, 'error' => 'The package did not look like this module (expected files missing) — not applying.'];
        }

        $target = self::stagingDir() . DIRECTORY_SEPARATOR . 'staged';
        self::rrmdir($target);
        if (!@mkdir($target, 0755, true) && !is_dir($target)) {
            $zip->close();

            return ['ok' => false, 'error' => 'Could not create an extraction folder.'];
        }
        $ok = $zip->extractTo($target);
        $zip->close();
        if (!$ok) {
            return ['ok' => false, 'error' => 'Extracting the package failed (disk space or permissions).'];
        }

        return ['ok' => true, 'error' => ''];
    }

    /**
     * Load the module over HTTP in a fresh request so the new code is exercised
     * by opcache. Distinguishes a broken app (5xx) from an unreachable one.
     *
     * @return string 'ok' | 'broken' | 'inconclusive'
     */
    private static function healthCheck()
    {
        $base = Sender::systemUrl();
        if ($base === '') {
            return 'inconclusive';
        }
        $url = $base . 'index.php?m=' . rawurlencode(self::name());

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::HEALTH_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::HEALTH_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'beem-sms-whmcs-selftest',
        ]);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $status === 0) {
            return 'inconclusive'; // could not reach ourselves - not evidence of breakage
        }
        // Only a bare 500 is the signature of a PHP fatal in the swapped-in
        // code. Maintenance mode (503), gateways (502/504) and WAF blocks (4xx)
        // are infrastructure, not our code - keep the update and warn rather
        // than roll back a good release on an unrelated error.
        if ($status === 500) {
            return 'broken';
        }
        if ($status >= 200 && $status < 400) {
            // Reached WHMCS and it rendered/redirected with no fatal. Guard
            // against a suppressed fatal that returns a blank 200.
            if ($status === 200 && strlen((string) $resp) < 100) {
                return 'inconclusive';
            }

            return 'ok';
        }

        return 'inconclusive';
    }

    /**
     * @param int $maxBytes Hard size ceiling (0 = no limit).
     *
     * @return string|null Response body, or null on any failure.
     */
    private static function httpGet($url, $timeout, $accept = '', $maxBytes = 0)
    {
        $ch = curl_init();
        $headers = ['User-Agent: beem-sms-whmcs'];
        if ($accept !== '') {
            $headers[] = 'Accept: ' . $accept;
        }
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true, // release assets 302 to a CDN
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($maxBytes > 0) {
            curl_setopt($ch, CURLOPT_MAXFILESIZE, $maxBytes);
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $status < 200 || $status >= 300 || !is_string($body) || $body === '') {
            return null;
        }
        if ($maxBytes > 0 && strlen($body) > $maxBytes) {
            return null; // CURLOPT_MAXFILESIZE only checks Content-Length; enforce for real
        }

        return $body;
    }

    private static function invalidateOpcache()
    {
        try {
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
        } catch (\Throwable $e) {
            // best-effort; opcache re-stats on its own within a couple of seconds
        }
    }

    /**
     * Trim the staging leftovers that rode along inside the backup, so the
     * retained backup stays small.
     */
    private static function cleanupBackupStaging()
    {
        self::rrmdir(self::backupDir() . DIRECTORY_SEPARATOR . '.update');
    }

    private static function logActivity($message)
    {
        if (function_exists('logActivity')) {
            try {
                logActivity($message);
            } catch (\Throwable $e) {
                // never block on logging
            }
        }
    }

    /**
     * Recursively remove a directory (or file). Silent and defensive.
     */
    private static function rrmdir($path)
    {
        if ($path === '' || $path === false) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $items = @scandir($path);
        if ($items !== false) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                self::rrmdir($path . DIRECTORY_SEPARATOR . $item);
            }
        }
        @rmdir($path);
    }
}
