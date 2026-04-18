<?php

namespace NextcloudDirect;

use html_checkbox;
use html_inputfield;

trait Preferences
{
    /**
     * preferences_list hook: inject Nextcloud block into the "compose" section.
     */
    public function add_preferences(array $param): array
    {
        if ($param["current"] != "compose") {
            return ["blocks" => $param["blocks"]];
        }

        $prefs      = $this->rcmail->user->get_prefs();
        $server     = rtrim($this->rcmail->config->get(__("server"), ""), "/");
        $public_url = rtrim($this->rcmail->config->get(__("public_url"), ""), "/");
        $blocks = $param["blocks"];

        $login_result   = $this->login_status();
        $can_disconnect = isset($prefs["nextcloud_direct_login"]);
        $username = $can_disconnect
            ? $prefs["nextcloud_direct_login"]["loginName"]
            : $this->resolve_username($this->rcmail->get_user_name());

        $server_host = $server ? parse_url($server, PHP_URL_HOST) : $this->gettext("not_configured");

        $disconnect_link = "<a href='#' onclick=\"rcmail.http_post('plugin.nextcloud_direct_disconnect','_token='+rcmail.env.request_token);return false\">"
            . htmlentities($this->gettext("disconnect")) . "</a>";
        $connect_link = "<a href='#' onclick=\"rcmail.http_post('plugin.nextcloud_direct_login');return false\">"
            . htmlentities($this->gettext("connect")) . "</a>";

        $server_row = [];
        if ($public_url) {
            $server_row["server"] = [
                "title"   => htmlentities($this->gettext("cloud_server")),
                "content" => "<a href='" . htmlentities($public_url) . "' target='_blank'>"
                    . htmlentities(parse_url($public_url, PHP_URL_HOST)) . "</a>",
            ];
        }

        $blocks["plugin.nextcloud_direct"] = [
            "name"    => htmlentities($this->gettext("cloud_attachments")),
            "options" => $server_row + [
                "connection" => [
                    "title"   => htmlentities($this->gettext("status")),
                    "content" => $login_result["status"] == "ok"
                        ? htmlentities($this->gettext("connected_as")) . " <strong>" . htmlentities($username) . "</strong>"
                          . ($can_disconnect ? " &nbsp;(" . $disconnect_link . ")" : "")
                        : "<span class='ncdirect-not-connected'>" . htmlentities($this->gettext("not_connected")) . "</span>"
                          . " &nbsp;(" . $connect_link . ")",
                ],
            ],
        ];

        if (!$this->rcmail->config->get(__("password_protected_links_locked"), true)) {
            $def = $this->rcmail->config->get(__("password_protected_links"), false) ? "1" : "0";
            $pp  = new html_checkbox([
                'id'    => __("password_protected_links"),
                'value' => '1',
                'name'  => '_' . __("password_protected_links"),
            ]);
            $blocks["plugin.nextcloud_direct"]["options"]["password_protected_links"] = [
                "title"   => htmlentities($this->gettext("password_protected_links")),
                "content" => $pp->show($prefs[__("user_password_protected_links")] ?? $def),
            ];
        }

        if (!$this->rcmail->config->get(__("expire_links_locked"), true)) {
            $def = $this->rcmail->config->get(__("expire_links"), false);
            $ex  = new html_checkbox([
                'id'    => __("expire_links"),
                'value' => '1',
                'name'  => '_' . __("expire_links"),
            ]);
            $blocks["plugin.nextcloud_direct"]["options"]["expire_links"] = [
                "title"   => htmlentities($this->gettext("expire_links")),
                "content" => $ex->show($prefs[__("user_expire_links")] ?? ($def === false ? "0" : "1")),
            ];
            $blocks["plugin.nextcloud_direct"]["options"]["expire_links_after"] = [
                "title"   => htmlentities($this->gettext("expire_links_after")),
                "content" => (new html_inputfield([
                    'type'  => 'number',
                    'min'   => '1',
                    'id'    => __("expire_links_after"),
                    'name'  => '_' . __("expire_links_after"),
                    'value' => $prefs[__("user_expire_links_after")] ?? ($def !== false ? $def : 7),
                ]))->show(),
            ];
        }

        return ["blocks" => $blocks];
    }

    /**
     * preferences_save hook.
     */
    public function save_preferences(array $param): array
    {
        if ($param["section"] != "compose") {
            return $param;
        }

        if (!$this->rcmail->config->get(__("password_protected_links_locked"), true)) {
            $v = $_POST["_" . __("password_protected_links")] ?? null;
            $param["prefs"][__("user_password_protected_links")] = ($v == 1);
        }

        if (!$this->rcmail->config->get(__("expire_links_locked"), true)) {
            $expire = $_POST["_" . __("expire_links")] ?? null;
            $days   = intval($_POST["_" . __("expire_links_after")] ?? 0);
            $param["prefs"][__("user_expire_links")] = ($expire == 1 && $days > 0);
            if ($days > 0) {
                $param["prefs"][__("user_expire_links_after")] = $days;
            }
        }

        return $param;
    }
}
