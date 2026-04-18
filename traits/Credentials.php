<?php

namespace NextcloudDirect;

trait Credentials
{
    /**
     * AJAX: hand the browser everything it needs for a direct upload —
     * Nextcloud username, app password, browser-facing WebDAV/OCS URLs,
     * destination folder, and chunking thresholds.
     *
     * CSRF-protected. Returns {status:'login_required'} if the user has not
     * completed the Device Flow.
     */
    public function credentials(): void
    {
        if (!$this->check_csrf()) {
            $this->rcmail->output->command('plugin.nextcloud_direct_credentials_result',
                ['status' => 'csrf']);
            return;
        }

        if ($this->is_disabled()) {
            $this->rcmail->output->command('plugin.nextcloud_direct_credentials_result',
                ['status' => 'disabled']);
            return;
        }

        $prefs = $this->rcmail->user->get_prefs();
        if (!isset($prefs["nextcloud_direct_login"]) ||
            empty($prefs["nextcloud_direct_login"]["loginName"]) ||
            empty($prefs["nextcloud_direct_login"]["appPassword"])) {
            $this->rcmail->output->command('plugin.nextcloud_direct_credentials_result',
                ['status' => 'login_required']);
            return;
        }

        $dav_base = rtrim($this->rcmail->config->get(__("dav_base_url"), "/nc-dav"), "/");
        $ocs_base = rtrim($this->rcmail->config->get(__("ocs_base_url"), "/nc-ocs"), "/");
        $folder = $this->resolve_folder_name();

        $chunk_size_mb = (int)$this->rcmail->config->get(__("chunk_size_mb"), 10);
        $single_put_mb = (int)$this->rcmail->config->get(__("single_put_threshold_mb"), 10);

        // Server-side Nextcloud DAV base for MOVE Destination header.
        // The browser-facing dav_base_url may be a nginx proxy path (/nc-dav),
        // but Nextcloud validates Destination against its own base URI
        // (/remote.php/dav/). PHP knows the real server URL.
        $server = rtrim($this->rcmail->config->get(__("server"), ""), "/");
        $dav_dest_base = $server ? $server . "/remote.php/dav" : null;

        $this->rcmail->output->command('plugin.nextcloud_direct_credentials_result', [
            'status' => 'ok',
            'loginName' => $prefs["nextcloud_direct_login"]["loginName"],
            'appPassword' => $prefs["nextcloud_direct_login"]["appPassword"],
            'davBaseUrl' => $dav_base,
            'davDestBase' => $dav_dest_base,
            'ocsBaseUrl' => $ocs_base,
            'folder' => $folder,
            'chunkSize' => $chunk_size_mb * 1024 * 1024,
            'singlePutThreshold' => $single_put_mb * 1024 * 1024,
        ]);
    }
}
