<?php

use NextcloudDirect\Cleanup;
use NextcloudDirect\Credentials;
use NextcloudDirect\NextcloudDirectLogin;
use NextcloudDirect\Preferences;
use NextcloudDirect\ShareLink;
use NextcloudDirect\Utilities;
use function NextcloudDirect\__;

const NC_DIRECT_PREFIX = "nextcloud_direct";
const NC_DIRECT_LOG_FILE = "ncdirect";
const NC_DIRECT_VERSION = "0.1.0";

require_once dirname(__FILE__) . "/traits/Utilities.php";
require_once dirname(__FILE__) . "/traits/NextcloudDirectLogin.php";
require_once dirname(__FILE__) . "/traits/Preferences.php";
require_once dirname(__FILE__) . "/traits/Credentials.php";
require_once dirname(__FILE__) . "/traits/ShareLink.php";
require_once dirname(__FILE__) . "/traits/Cleanup.php";

/**
 * Roundcube plugin: nextcloud_direct
 *
 * Browser-to-Nextcloud direct uploads. Roundcube PHP only issues auth
 * credentials and creates share links — the file bytes never transit PHP.
 *
 * See README.md for the recommended nginx stream-proxy deployment (Mode A).
 */
class nextcloud_direct extends rcube_plugin
{
    use Utilities, NextcloudDirectLogin, Preferences, Credentials, ShareLink, Cleanup;

    public $task = 'mail|settings';

    private rcmail $rcmail;
    private GuzzleHttp\Client $client;

    public function init(): void
    {
        $this->rcmail = rcmail::get_instance();
        $this->load_config();

        if (empty($this->rcmail->config->get(__("server")))) {
            self::log("invalid config: server is empty");
            return;
        }

        if ($this->is_disabled()) {
            return;
        }

        if (!class_exists("GuzzleHttp\\Client")) {
            self::log("GuzzleHttp\\Client not found. Needs a Roundcube version that bundles Guzzle, or `composer require guzzlehttp/guzzle` in the plugin directory.");
            return;
        }

        $this->client = new GuzzleHttp\Client([
            'headers' => [
                'User-Agent' => 'Roundcube nextcloud_direct/' . NC_DIRECT_VERSION,
            ],
            'http_errors' => false,
            'verify' => $this->rcmail->config->get(__("verify_https"), true),
        ]);

        $this->add_texts("l10n/", true);

        $this->register_action('plugin.nextcloud_direct_checklogin', function () { $this->check_login(); });
        $this->register_action('plugin.nextcloud_direct_login', function () { $this->login(); });
        $this->register_action('plugin.nextcloud_direct_disconnect', function () {
            if ($this->check_csrf()) { $this->logout(); }
        });
        $this->register_action('plugin.nextcloud_direct_credentials', function () { $this->credentials(); });
        $this->register_action('plugin.nextcloud_direct_sharelink', function () { $this->sharelink(); });
        $this->register_action('plugin.nextcloud_direct_cleanup', function () { $this->cleanup(); });

        $this->add_hook("refresh", function ($param) { $this->poll($param); });
        $this->add_hook("ready", function ($param) { $this->insert_client_code($param); });
        $this->add_hook("preferences_list", function ($param) { return $this->add_preferences($param); });
        $this->add_hook("preferences_save", function ($param) { return $this->save_preferences($param); });
    }

    /**
     * ready-hook: load client JS/CSS on the compose page and on the compose
     * preferences pane. Also expose env vars that the browser needs before
     * it touches any AJAX endpoint.
     */
    public function insert_client_code(mixed $param): void
    {
        $section = rcube_utils::get_input_string('_section', rcube_utils::INPUT_GPC);

        $on_compose = (@$param["task"] == "mail" && @$param["action"] == "compose");
        $on_prefs   = (@$param["task"] == "settings" && @$param["action"] == "edit-prefs"
                       && $section == "compose");

        if (!($on_compose || $on_prefs)) {
            return;
        }
        if ($this->is_disabled()) {
            return;
        }

        $this->include_script("client.js");
        $this->include_stylesheet("client.css");

        $softlimit = parse_bytes($this->rcmail->config->get(__("softlimit"), null));
        $max_message = parse_bytes($this->rcmail->config->get('max_message_size'));

        $this->rcmail->output->set_env(__("softlimit"),
            ($softlimit && $max_message && $softlimit > $max_message) ? null : $softlimit);
        $this->rcmail->output->set_env(__("max_message_size"), $max_message);
        $this->rcmail->output->set_env(__("behavior"),
            $this->rcmail->config->get(__("behavior"), "prompt"));
    }
}
