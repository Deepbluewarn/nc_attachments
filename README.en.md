# nextcloud_direct

Roundcube plugin for **browser → Nextcloud direct file uploads**. Roundcube PHP
issues auth credentials and creates share links; the file bytes never transit
PHP. Solves the classic "nginx `client_max_body_size` + PHP `post_max_size` +
PHP memory/timeout" problem that the server-side
[`nextcloud_attachments`](https://github.com/bnnt/nextcloud_attachments) plugin
hits on multi-GB files.

Key features

- Direct XHR upload to Nextcloud WebDAV with progress + cancel
- Chunked upload (default 10 MB/chunk) with **resume** on network failure
- Device-Flow app-password auth only — no IMAP password fallback
- `navigator.sendBeacon`-based orphan cleanup on tab close / upload abort
- CSRF-protected AJAX endpoints
- Drop-in composer integration: same file-picker / drag-drop / paste UX as
  `nextcloud_attachments`

---

## Deployment modes

### Mode A (recommended) — Roundcube nginx stream proxy

The browser sees same-origin `https://mail.example.com/nc-dav/…`. nginx
stream-proxies to Nextcloud **without buffering**, so PHP memory/timeout limits
never apply to upload bytes.

```nginx
server {
    server_name mail.example.com;

    # WebDAV (upload/download) — stream proxy, no size limit
    location /nc-dav/ {
        proxy_pass https://nextcloud.example.com/remote.php/dav/;
        proxy_http_version 1.1;
        proxy_request_buffering off;        # ← the essential bit
        client_max_body_size 0;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
        proxy_set_header Host nextcloud.example.com;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_pass_request_headers on;      # forward Authorization
    }

    # OCS (share-link creation) — small requests, server-side only
    location /nc-ocs/ {
        proxy_pass https://nextcloud.example.com/ocs/;
        proxy_set_header Host nextcloud.example.com;
        proxy_pass_request_headers on;
    }

    # … existing Roundcube location / { … } …
}
```

Config:

```php
$config["nextcloud_direct_dav_base_url"] = "/nc-dav";
$config["nextcloud_direct_ocs_base_url"] = "/nc-ocs";
$config["nextcloud_direct_server"] = "https://nextcloud.example.com";
```

Pros: No CORS. No Nextcloud-side changes. PHP bypassed.
Trade-off: Upload bytes traverse Roundcube's NIC (usually fine if in the same
IDC/VPC). Roundcube nginx becomes the throughput ceiling (still ~10× PHP).

### Mode B — CORS on Nextcloud's nginx

Browser → Nextcloud directly. Requires injecting CORS headers on
`/remote.php/dav/` at Nextcloud's reverse proxy (Nextcloud core doesn't set
them — `cors_allowed_domains` doesn't apply to WebDAV). Complex but no
Roundcube-side proxy.

```php
$config["nextcloud_direct_dav_base_url"] = "https://nextcloud.example.com/remote.php/dav";
$config["nextcloud_direct_ocs_base_url"] = "https://nextcloud.example.com/ocs";
```

### Mode C — third-party Nextcloud app

A Nextcloud-side app (e.g. `webappassword`) can publish a CORS-friendly
upload endpoint. Same browser-facing config as Mode B.

---

## Install

```sh
cd plugins/
git clone … nextcloud_direct
```

Roundcube 1.6+ bundles Guzzle, so no `composer require` is needed in the plugin
directory.

Enable in `config/config.inc.php`:

```php
$config['plugins'] = ['nextcloud_direct', /* … */];
```

Copy `config.inc.php.dist` → `config.inc.php` and edit.

### First-time user flow

1. User opens compose.
2. They pick a file > softlimit (or > max_message_size).
3. Dialog prompts them to log in to Nextcloud; popup opens Device Flow URL.
4. User approves on Nextcloud (2FA etc.); app password is stored in their RC
   preferences.
5. Upload proceeds directly browser → WebDAV.

Users can revoke the app password any time from Nextcloud Security settings
*or* via the "disconnect" link on the RC Compose preferences page.

---

## Security model

- **App password only.** No IMAP/SSO password is ever sent to the browser.
  App passwords are WebDAV-scoped tokens the user can revoke individually.
- The `plugin.nextcloud_direct_credentials` AJAX endpoint returns the password
  only to the authenticated RC session owner, guarded by RC's `_token` CSRF
  check.
- The browser keeps the password in a closure variable for the duration of a
  single upload batch, then drops it.
- HTTPS is assumed. Running this plugin over plain HTTP is unsafe — don't.

---

## Configuration reference

See [`config.inc.php.dist`](config.inc.php.dist). Highlights:

| Key | Default | Notes |
|-----|---------|-------|
| `server` | (required) | Absolute NC URL used by PHP for Device Flow |
| `dav_base_url` | `/nc-dav` | Browser-facing WebDAV base. Relative = Mode A |
| `ocs_base_url` | `/nc-ocs` | Browser-facing OCS base |
| `folder` | `Mail Attachments` | Destination folder inside user's NC |
| `chunk_size_mb` | `10` | Chunk size in MB |
| `single_put_threshold_mb` | `10` | Files ≤ this use a single PUT |
| `softlimit` | `25M` | Prompt user above this size |
| `behavior` | `prompt` | `prompt` or `upload` |
| `password_protected_links` | `false` | Generate share link password |
| `expire_links` | `false` | Number of days, or `false` |

---

## Troubleshooting

**"login_required" even after login:** the app-password probe returned
401/403. Check the `ncdirect` log (`logs/ncdirect`) for the exact status code.

**CORS errors in the browser console:** Mode A proxy isn't set up or the
browser is hitting an absolute Nextcloud URL. Verify `dav_base_url` starts
with `/` and that `/nc-dav/` actually proxies correctly
(`curl -u user:pass https://mail.example.com/nc-dav/files/user/`).

**Uploads stall at 100% then fail:** the final `MOVE` on a chunked upload
takes time proportional to file size on older Nextcloud (<28). Increase
`proxy_read_timeout` if you routinely upload multi-GB files.

**Upload aborted when tab stayed open:** check your reverse-proxy
`client_body_timeout` / `proxy_send_timeout`.

---

## Not implemented yet

- Folder layout (hash/date subfolders) — the reference plugin supports this;
  this plugin doesn't, folders are flat under `folder`.
- HTML attachment language override — always uses the user's RC display
  language.
- Attachment checksum in the body HTML.

---

Based on [`nextcloud_attachments`](https://github.com/bnnt/nextcloud_attachments)
by Bennet Becker (MIT).
