<?php

namespace NextcloudDirect;

use GuzzleHttp\Exception\GuzzleException;

trait NextcloudDirectLogin
{
    /**
     * AJAX: report current login status to the browser.
     */
    public function check_login(): void
    {
        $this->rcmail->output->command('plugin.nextcloud_direct_login_result', $this->login_status());
    }

    /**
     * AJAX: start a Nextcloud Device-Flow login. Returns the URL the browser
     * should open in a popup; the poll() hook picks up the result later.
     */
    public function login(): void
    {
        $server = $this->rcmail->config->get(__("server"));
        if (empty($server)) {
            return;
        }

        try {
            $res = $this->client->post($server . "/index.php/login/v2");
            $body = $res->getBody()->getContents();
            $data = json_decode($body, true);

            if ($res->getStatusCode() !== 200) {
                self::log($this->rcmail->get_user_name() . " device flow start failed: " . print_r($data, true));
                $this->rcmail->output->command('plugin.nextcloud_direct_login', [
                    'status' => null, "message" => $res->getReasonPhrase(), "response" => $data
                ]);
                return;
            }

            $_SESSION['plugins']['nextcloud_direct'] = $data['poll'];
            unset($_SESSION['plugins']['nextcloud_direct']['login_result']);

            $this->rcmail->output->command('plugin.nextcloud_direct_login', [
                'status' => "ok", "url" => $data["login"]
            ]);
        } catch (GuzzleException $e) {
            self::log($this->rcmail->get_user_name() . " device flow request failed: " . print_r($e, true));
            $this->rcmail->output->command('plugin.nextcloud_direct_login', ['status' => null]);
        }
    }

    /**
     * Probe the Nextcloud WebDAV endpoint with the stored app password.
     * Unlike nextcloud_attachments, this plugin never falls back to the
     * Roundcube/IMAP password — app password is mandatory.
     */
    private function login_status(): array
    {
        if (isset($_SESSION['plugins']['nextcloud_direct']) &&
            isset($_SESSION['plugins']['nextcloud_direct']['login_result'])) {
            return $_SESSION['plugins']['nextcloud_direct']['login_result'];
        }

        $prefs = $this->rcmail->user->get_prefs();
        $server = $this->rcmail->config->get(__("server"));
        $username = $this->resolve_username();

        if (empty($server) || empty($username)) {
            return ['status' => null, 'message' => 'missing_config'];
        }

        if (!isset($prefs["nextcloud_direct_login"]) ||
            empty($prefs["nextcloud_direct_login"]["loginName"]) ||
            empty($prefs["nextcloud_direct_login"]["appPassword"])) {
            return ['status' => 'login_required'];
        }

        $nc_user = $prefs["nextcloud_direct_login"]["loginName"];
        $nc_pass = $prefs["nextcloud_direct_login"]["appPassword"];

        try {
            $res = $this->client->request("PROPFIND", $server . "/remote.php/dav/files/" . rawurlencode($nc_user), [
                'auth' => [$nc_user, $nc_pass],
                'headers' => ['Depth' => '0'],
            ]);
            $scode = $res->getStatusCode();
            switch ($scode) {
                case 401:
                case 403:
                    unset($prefs["nextcloud_direct_login"]);
                    $this->rcmail->user->save_prefs($prefs);
                    $_SESSION['plugins']['nextcloud_direct']['login_result'] = ['status' => 'login_required'];
                    return ['status' => 'login_required'];
                case 404:
                    unset($prefs["nextcloud_direct_login"]);
                    $this->rcmail->user->save_prefs($prefs);
                    $_SESSION['plugins']['nextcloud_direct']['login_result'] = ['status' => 'invalid_user'];
                    return ['status' => 'invalid_user'];
                case 200:
                case 207:
                    return ['status' => 'ok', 'user' => $nc_user];
                default:
                    if ($scode < 500) {
                        $_SESSION['plugins']['nextcloud_direct']['login_result'] =
                            ['status' => null, 'code' => $scode, 'message' => $res->getReasonPhrase()];
                    }
                    return ['status' => null, 'code' => $scode, 'message' => $res->getReasonPhrase()];
            }
        } catch (GuzzleException $e) {
            self::log($this->rcmail->get_user_name() . " login probe failed: " . print_r($e, true));
            return ['status' => null];
        }
    }

    /**
     * AJAX: revoke the stored Nextcloud app password.
     */
    public function logout(): void
    {
        $prefs = $this->rcmail->user->get_prefs();
        if (!isset($prefs["nextcloud_direct_login"])) {
            return;
        }

        $username = $prefs["nextcloud_direct_login"]["loginName"] ?? null;
        $password = $prefs["nextcloud_direct_login"]["appPassword"] ?? null;

        if ($username && $password) {
            $server = $this->rcmail->config->get(__("server"));
            if (!empty($server)) {
                try {
                    $this->client->delete($server . "/ocs/v2.php/core/apppassword", [
                        'headers' => ['OCS-APIRequest' => 'true'],
                        'auth' => [$username, $password],
                    ]);
                } catch (GuzzleException) {
                }
            }
        }

        $prefs["nextcloud_direct_login"] = null;
        unset($_SESSION['plugins']['nextcloud_direct']);
        $this->rcmail->user->save_prefs($prefs);
        $this->rcmail->output->command('command', 'save');
    }

    /**
     * refresh-hook: poll the Device-Flow endpoint for completion and persist
     * {loginName, appPassword} to user preferences when the user finishes.
     */
    public function poll($param): void
    {
        if (isset($_SESSION['plugins']['nextcloud_direct']['endpoint']) &&
            isset($_SESSION['plugins']['nextcloud_direct']['token'])) {
            try {
                $res = $this->client->post(
                    $_SESSION['plugins']['nextcloud_direct']['endpoint'] .
                    "?token=" . $_SESSION['plugins']['nextcloud_direct']['token']
                );

                if ($res->getStatusCode() == 200) {
                    $data = json_decode($res->getBody()->getContents(), true);
                    if (isset($data['appPassword']) && isset($data['loginName'])) {
                        $prefs = $this->rcmail->user->get_prefs();
                        $prefs["nextcloud_direct_login"] = $data;
                        $this->rcmail->user->save_prefs($prefs);
                        unset($_SESSION['plugins']['nextcloud_direct']);
                        if (@$param["task"] == "settings") {
                            $this->rcmail->output->command('command', 'save');
                        }
                        $this->rcmail->output->command('plugin.nextcloud_direct_login_result', ['status' => "ok"]);
                    }
                } else if ($res->getStatusCode() != 404) {
                    unset($_SESSION['plugins']['nextcloud_direct']);
                }
            } catch (GuzzleException $e) {
                self::log("poll failed: " . print_r($e, true));
            }
        }
    }
}
