<?php

namespace NextcloudDirect;

use GuzzleHttp\Exception\GuzzleException;
use rcube_utils;

trait Cleanup
{
    /**
     * AJAX (sendBeacon target): delete an orphan upload on the user's Nextcloud.
     *
     * Called by the browser when the user aborts an upload, closes the tab
     * mid-upload, or a chunked upload fails after MKCOL but before MOVE. We
     * accept either:
     *   _path: full WebDAV sub-path under /files/{user}/ (e.g. "Mail Attachments/foo.bin")
     *   _transferId: uploads/{user}/{transferId}/ to wipe the chunk dir
     *
     * Silent on success — sendBeacon ignores the response body anyway.
     */
    public function cleanup(): void
    {
        if (!$this->check_csrf()) {
            return;
        }
        if ($this->is_disabled()) {
            return;
        }

        $prefs = $this->rcmail->user->get_prefs();
        if (!isset($prefs["nextcloud_direct_login"])) {
            return;
        }

        $username = $prefs["nextcloud_direct_login"]["loginName"];
        $password = $prefs["nextcloud_direct_login"]["appPassword"];
        $server = rtrim($this->rcmail->config->get(__("server"), ""), "/");
        if (empty($server)) {
            return;
        }

        $path = rcube_utils::get_input_string('_path', rcube_utils::INPUT_POST);
        $transfer_id = rcube_utils::get_input_string('_transferId', rcube_utils::INPUT_POST);

        $targets = [];
        if (!empty($path)) {
            $segments = explode('/', $path);
            foreach ($segments as $seg) {
                if ($seg === '' || $seg === '.' || $seg === '..') {
                    return;
                }
            }
            $encoded = array_map('rawurlencode', $segments);
            $targets[] = $server . "/remote.php/dav/files/" . rawurlencode($username) . "/" . implode('/', $encoded);
        }
        if (!empty($transfer_id) && preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $transfer_id)) {
            $targets[] = $server . "/remote.php/dav/uploads/" . rawurlencode($username) . "/" . rawurlencode($transfer_id);
        }

        foreach ($targets as $url) {
            try {
                $this->client->delete($url, ['auth' => [$username, $password]]);
            } catch (GuzzleException $e) {
                self::log("cleanup DELETE failed: " . $e->getMessage());
            }
        }
    }
}
