<?php

namespace NextcloudDirect;

use Exception;
use InvalidArgumentException;
use rcmail;
use rcube_utils;

function __(string $val): string
{
    return NC_DIRECT_PREFIX . "_" . $val;
}

trait Utilities
{
    private static function log(...$lines): void
    {
        foreach ($lines as $line) {
            $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1];
            $func = $bt["function"] ?? "?";
            $cls = $bt["class"] ?? "?";
            if (!is_string($line)) {
                $line = print_r($line, true);
            }
            $llines = explode(PHP_EOL, $line);
            rcmail::write_log(NC_DIRECT_LOG_FILE, "[" . NC_DIRECT_PREFIX . "] {" . $cls . "::" . $func . "} " . $llines[0]);
            unset($llines[0]);
            if (count($llines) > 0) {
                foreach ($llines as $l) {
                    rcmail::write_log(NC_DIRECT_LOG_FILE, str_pad("...", strlen("[" . NC_DIRECT_PREFIX . "] "), " ", STR_PAD_BOTH) . "{" . $cls . "::" . $func . "} " . $l);
                }
            }
        }
    }

    /**
     * Whether the current user is excluded from the plugin.
     */
    private function is_disabled(): bool
    {
        $ex = $this->rcmail->config->get(__("exclude_users"), []);
        $exg = $this->rcmail->config->get(__("exclude_users_in_addr_books"), []);
        $exa = $this->rcmail->config->get(__("exclude_users_with_addr_book_value"), []);
        $exag = $this->rcmail->config->get(__("exclude_users_in_addr_book_group"), []);

        if (is_array($ex) && (in_array($this->rcmail->get_user_name(), $ex) || in_array($this->resolve_username(), $ex) || in_array($this->rcmail->get_user_email(), $ex))) {
            self::log("access for " . $this->resolve_username() . " disabled via direct deny list");
            return true;
        }

        if (is_array($exg) && count($exg) > 0) {
            foreach ($exg as $book) {
                $abook = $this->rcmail->get_address_book($book);
                if ($abook) {
                    if (array_key_exists("uid", $abook->coltypes)) {
                        $entries = $abook->search(["email", "uid"], [$this->rcmail->get_user_email(), $this->resolve_username()]);
                    } else {
                        $entries = $abook->search("email", $this->rcmail->get_user_email());
                    }
                    if ($entries) {
                        self::log("access for " . $this->resolve_username() . " disabled in " . $abook->get_name());
                        return true;
                    }
                }
            }
        }

        if (is_array($exa) && count($exa) > 0) {
            if (!is_array($exa[0])) {
                $exa = [$exa];
            }
            foreach ($exa as $val) {
                if (count($val) == 3) {
                    $book = $this->rcmail->get_address_book($val[0]);
                    $attr = $val[1];
                    $match = $val[2];
                    if (!$book) {
                        continue;
                    }
                    if (array_key_exists("uid", $book->coltypes)) {
                        $entries = $book->search(["email", "uid"], [$this->rcmail->get_user_email(), $this->resolve_username()]);
                    } else {
                        $entries = $book->search("email", $this->rcmail->get_user_email());
                    }
                    if ($entries) {
                        while ($e = $entries->iterate()) {
                            if (array_key_exists($attr, $e) && ($e[$attr] == $match ||
                                    (is_array($e[$attr]) && in_array($match, $e[$attr])))) {
                                self::log("access for " . $this->resolve_username() . " disabled in " . $book->get_name() . " because of " . $attr . "=" . $match);
                                return true;
                            }
                        }
                    }
                }
            }
        }

        if (is_array($exag) && count($exag) > 0) {
            if (!is_array($exag[0])) {
                $exag = [$exag];
            }
            foreach ($exag as $val) {
                if (count($val) == 2) {
                    $book = $this->rcmail->get_address_book($val[0]);
                    if (!$book) {
                        continue;
                    }
                    $group = $val[1];
                    $groups = $book->get_record_groups(base64_encode($this->resolve_username()));
                    if (in_array($group, $groups)) {
                        self::log("access for " . $this->resolve_username() . " disabled in " . $book->get_name() . " because of group " . $group);
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Resolve the Roundcube username to a Nextcloud username using the
     * configured template (placeholders %s %i %e %l %u %d %h).
     */
    private function resolve_username(string $user = ""): string
    {
        if (empty($user)) {
            $user = $this->rcmail->user->get_username();
        }

        $username_tmpl = $this->rcmail->config->get(__("username"), "%u");

        $mail = $this->rcmail->user->get_username("mail");
        $mail_local = $this->rcmail->user->get_username("local");
        $mail_domain = $this->rcmail->user->get_username("domain");

        $imap_user = empty($_SESSION['username']) ? $mail_local : @$_SESSION['username'];

        return str_replace(
            ["%s", "%i", "%e", "%l", "%u", "%d", "%h"],
            [$user, $imap_user, $mail, $mail_local, $mail_local, $mail_domain, $_SESSION['storage_host'] ?? ""],
            $username_tmpl
        );
    }

    /**
     * Resolve the destination folder name from config. Supports either a plain
     * string or a locale-keyed array ["en_US" => "...", ...].
     */
    private function resolve_folder_name(): string
    {
        $folder = $this->rcmail->config->get(__("folder"), "Mail Attachments");
        if (is_array($folder)) {
            $lang = $this->rcmail->get_user_language();
            if (isset($folder[$lang])) {
                return $folder[$lang];
            }
            if (isset($folder["en_US"])) {
                return $folder["en_US"];
            }
            return reset($folder) ?: "Mail Attachments";
        }
        return (string)$folder;
    }

    /**
     * @throws Exception
     */
    private function random_from_alphabet(int $len, string|array $alphabet): string
    {
        if ($len < 1) {
            throw new InvalidArgumentException("$len is less than or equal to 0");
        }
        if (is_string($alphabet)) {
            $alphabet = str_split($alphabet);
        }
        $random_bytes = random_bytes($len);

        return implode(array_map(function ($byte) use ($alphabet) {
            $asize = count($alphabet);
            $i = intval(round(ord($byte) / ((2.0 ** 8.0) / floatval($asize)))) % $asize;
            return $alphabet[$i];
        }, str_split($random_bytes)));
    }

    /**
     * Generate a password drawing at least one char from each alphabet bucket.
     */
    private function generate_password(): string
    {
        $length = (int)$this->rcmail->config->get(__("password_length"), 12);
        $alphabets = $this->rcmail->config->get(__("password_alphabets"), [
            "123456789",
            "ABCDEFGHJKLMNPQRSTUVWXYZ",
            "abcdefghijkmnopqrstuvwxyz",
        ]);

        $pw = [];
        foreach ($alphabets as $bucket) {
            $pw[] = $this->random_from_alphabet(1, $bucket);
        }
        $all = implode("", $alphabets);
        $pw[] = $this->random_from_alphabet(max(0, $length - count($alphabets)), $all);
        $out = str_split(implode("", $pw));
        shuffle($out);
        return implode("", $out);
    }

    /**
     * Verify the Roundcube request token for an AJAX action.
     */
    private function check_csrf(): bool
    {
        $token = rcube_utils::get_input_string('_token', rcube_utils::INPUT_GPC);
        return !empty($token) && $token === $this->rcmail->get_request_token();
    }
}
