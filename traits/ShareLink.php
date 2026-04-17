<?php

namespace NextcloudDirect;

use DateInterval;
use DateTime;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use rcube_utils;
use SimpleXMLElement;

trait ShareLink
{
    /**
     * AJAX: create an OCS public-share link for a file the browser just uploaded
     * to "{folder}/{filename}" inside the user's Nextcloud. Returns the share
     * URL, optional password and expiry date, and a ready-made HTML block the
     * composer can insert as an attachment.
     *
     * Input (POST):
     *   _filename, _filesize, _mimetype
     *
     * CSRF-protected.
     */
    public function sharelink(): void
    {
        if (!$this->check_csrf()) {
            $this->rcmail->output->command('plugin.nextcloud_direct_sharelink_result',
                ['status' => 'csrf']);
            return;
        }

        if ($this->is_disabled()) {
            $this->rcmail->output->command('plugin.nextcloud_direct_sharelink_result',
                ['status' => 'disabled']);
            return;
        }

        $prefs = $this->rcmail->user->get_prefs();
        if (!isset($prefs["nextcloud_direct_login"]) ||
            empty($prefs["nextcloud_direct_login"]["loginName"]) ||
            empty($prefs["nextcloud_direct_login"]["appPassword"])) {
            $this->rcmail->output->command('plugin.nextcloud_direct_sharelink_result',
                ['status' => 'login_required']);
            return;
        }

        $username = $prefs["nextcloud_direct_login"]["loginName"];
        $password = $prefs["nextcloud_direct_login"]["appPassword"];
        $server = rtrim($this->rcmail->config->get(__("server"), ""), "/");

        $filename = rcube_utils::get_input_string('_filename', rcube_utils::INPUT_POST);
        $filesize = (int)rcube_utils::get_input_string('_filesize', rcube_utils::INPUT_POST);
        $mimetype = rcube_utils::get_input_string('_mimetype', rcube_utils::INPUT_POST);

        if (empty($filename) || empty($server)) {
            $this->rcmail->output->command('plugin.nextcloud_direct_sharelink_result',
                ['status' => 'bad_request']);
            return;
        }

        $folder = $this->resolve_folder_name();
        $path = $folder . "/" . $filename;

        // Build form_params: expiry + optional password
        $form_params = [];
        $expiry_days = $this->rcmail->config->get(__("expire_links"), false);
        if (is_numeric($expiry_days) && $expiry_days > 0) {
            $expire_date = new DateTime();
            $expire_date->add(DateInterval::createFromDateString($expiry_days . " days"));
            $form_params["expireDate"] = $expire_date->format(DATE_ATOM);
        }

        $gen_password = null;
        if ($this->rcmail->config->get(__("password_protected_links"), false)) {
            try {
                $gen_password = $this->generate_password();
                $form_params["password"] = $gen_password;
            } catch (Exception $e) {
                self::log("password gen failed: " . $e->getMessage());
            }
        }

        try {
            $res = $this->client->post($server . "/ocs/v2.php/apps/files_sharing/api/v1/shares", [
                'headers' => ['OCS-APIRequest' => 'true'],
                'form_params' => [
                    'path' => $path,
                    'shareType' => 3,
                    'publicUpload' => 'false',
                    ...$form_params,
                ],
                'auth' => [$username, $password],
            ]);

            $body = $res->getBody()->getContents();
            if ($res->getStatusCode() != 200) {
                self::log("share create failed " . $res->getStatusCode() . ": " . $body);
                $this->rcmail->output->command('plugin.nextcloud_direct_sharelink_result', [
                    'status' => 'link_error',
                    'code' => $res->getStatusCode(),
                    'message' => $res->getReasonPhrase(),
                ]);
                return;
            }

            $ocs = new SimpleXMLElement($body);
            $url = (string)$ocs->data->url;

            $html_block = $this->render_attachment_html([
                'name' => $filename,
                'size' => $filesize,
                'mimetype' => $mimetype,
            ], $url, $gen_password, $form_params["expireDate"] ?? null);

            $this->rcmail->output->command('plugin.nextcloud_direct_sharelink_result', [
                'status' => 'ok',
                'url' => $url,
                'password' => $gen_password,
                'expireDate' => $form_params["expireDate"] ?? null,
                'html' => $html_block,
                'file' => [
                    'name' => $filename,
                    'size' => $filesize,
                    'mimetype' => $mimetype,
                ],
            ]);
        } catch (GuzzleException $e) {
            self::log("share create exception: " . $e->getMessage());
            $this->rcmail->output->command('plugin.nextcloud_direct_sharelink_result',
                ['status' => 'link_error', 'message' => $e->getMessage()]);
        } catch (Exception $e) {
            self::log("share xml parse failed: " . $e->getMessage());
            $this->rcmail->output->command('plugin.nextcloud_direct_sharelink_result',
                ['status' => 'link_error', 'message' => 'xml_parse']);
        }
    }

    /**
     * Render a self-contained HTML attachment block to be inserted into the
     * message body. Kept deliberately simple and style-inlined so recipient
     * mail clients render it consistently.
     */
    private function render_attachment_html(array $file, string $url, ?string $password, ?string $expireDate): string
    {
        $server = rtrim($this->rcmail->config->get(__("server"), ""), "/");
        $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8");

        $mime = $file['mimetype'] ?? "application/octet-stream";
        $mime_name = str_replace("/", "-", $mime);
        $mime_generic = str_replace("/", "-", explode("/", $mime)[0]) . "-x-generic";
        $icon_dir = $this->home . "/icons/Yaru-mimetypes/";
        $icon_path = file_exists($icon_dir . $mime_name . ".png") ? $icon_dir . $mime_name . ".png"
            : (file_exists($icon_dir . $mime_generic . ".png") ? $icon_dir . $mime_generic . ".png"
            : $icon_dir . "unknown.png");
        $icon_data_uri = file_exists($icon_path)
            ? 'data:image/png;base64,' . base64_encode(file_get_contents($icon_path))
            : '';

        $size = function_exists('\rcube_utils::show_bytes')
            ? \rcube_utils::show_bytes((int)($file['size'] ?? 0))
            : number_format((int)($file['size'] ?? 0)) . ' bytes';

        $preamble = $this->gettext([
            'name' => 'file_is_filelink_download_below',
            'vars' => ['filename' => $file['name']],
        ]);
        $disclaimer = $this->gettext([
            'name' => 'attachment_disclaimer',
            'vars' => ['serverurl' => $server],
        ]);

        $out  = '<div style="border:1px solid #ccc;border-radius:6px;padding:16px;font-family:sans-serif;max-width:560px;">';
        $out .= '<p>' . $esc($preamble) . '</p>';
        $out .= '<table style="border-collapse:collapse;margin:8px 0;"><tr>';
        if ($icon_data_uri) {
            $out .= '<td style="vertical-align:top;padding-right:12px;"><img src="' . $esc($icon_data_uri) . '" alt="" style="width:48px;height:48px;"></td>';
        }
        $out .= '<td style="vertical-align:top;">';
        $out .= '<p style="margin:0 0 4px 0;"><b>' . $esc($file['name']) . '</b></p>';
        $out .= '<p style="margin:0 0 4px 0;">' . $esc($this->gettext('size')) . ': ' . $esc($size) . '</p>';
        $out .= '<p style="margin:0 0 4px 0;">' . $esc($this->gettext('link')) . ': <a href="' . $esc($url) . '">' . $esc($url) . '</a></p>';
        if ($password) {
            $out .= '<p style="margin:0 0 4px 0;">' . $esc($this->gettext('password')) . ': <code>' . $esc($password) . '</code></p>';
        }
        if ($expireDate) {
            $out .= '<p style="margin:0 0 4px 0;">' . $esc($this->gettext('valid_until')) . ': ' . $esc($expireDate) . '</p>';
        }
        $out .= '</td></tr></table>';
        $out .= '<p style="font-size:0.85em;color:#666;">' . $disclaimer . '</p>';
        $out .= '</div>';
        return $out;
    }
}
