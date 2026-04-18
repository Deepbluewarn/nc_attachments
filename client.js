/* nextcloud_direct — browser-to-Nextcloud direct uploads for Roundcube.
 *
 * The whole point of this plugin: the file bytes never hit Roundcube PHP.
 * PHP only issues the {loginName, appPassword, davBaseUrl} bundle and later
 * creates the OCS share link. Uploads go browser → nginx-stream-proxy → NC,
 * or (Mode B/C) browser → NC directly with CORS.
 *
 * All references to rcmail.gettext use the "nextcloud_direct" domain.
 */

(function () {
    "use strict";

    const DOMAIN = "nextcloud_direct";
    const t = (k) => rcmail.gettext(k, DOMAIN);

    // Server-side env keys (set by Preferences hook).
    const NC_ENV = {
        softlimit: "nextcloud_direct_softlimit",
        behavior: "nextcloud_direct_behavior",
        maxMessageSize: "nextcloud_direct_max_message_size",
        fileConcurrency: "nextcloud_direct_file_concurrency",
    };

    // --- tiny helpers -------------------------------------------------------

    function humanBytes(n) {
        const units = ["B", "kB", "MB", "GB", "TB"];
        let i = 0;
        while (n > 800 && i < units.length - 1) { n /= 1024; i++; }
        return n.toFixed(i === 0 ? 0 : 1) + " " + units[i];
    }

    // RFC3986 path-segment encoding that preserves slashes.
    function encodePath(p) {
        return p.split("/").map(encodeURIComponent).join("/");
    }

    function basicAuthHeader(user, pass) {
        // btoa can't handle UTF-8 directly.
        const bytes = new TextEncoder().encode(user + ":" + pass);
        let bin = "";
        for (const b of bytes) bin += String.fromCharCode(b);
        return "Basic " + btoa(bin);
    }

    function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

    function uuid() {
        if (crypto && crypto.randomUUID) return crypto.randomUUID();
        // Fallback
        return "xxxxxxxxxxxx4xxxyxxxxxxxxxxxxxxx".replace(/[xy]/g, c => {
            const r = Math.random() * 16 | 0;
            return (c === "x" ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }

    // --- login status / Device Flow ----------------------------------------

    rcmail.addEventListener("plugin.nextcloud_direct_login", function (data) {
        if (data.status === "ok") {
            rcmail.env.nextcloud_direct_login_flow = data.url;
        } else {
            rcmail.display_message(t("login_failed"), "error", 10000);
        }
    });

    rcmail.addEventListener("plugin.nextcloud_direct_login_result", function (event) {
        if (event.status === "ok") {
            rcmail.env.nextcloud_direct_login_available = true;
            rcmail.env.nextcloud_direct_upload_available = true;
        } else if (event.status === "login_required") {
            rcmail.env.nextcloud_direct_login_available = true;
            rcmail.env.nextcloud_direct_upload_available = false;
        } else {
            rcmail.env.nextcloud_direct_login_available = false;
            rcmail.env.nextcloud_direct_upload_available = false;
        }
    });

    rcmail.nextcloud_direct_login_button_click_handler = function (btn_evt, dialog, pendingFiles) {
        rcmail.http_post("plugin.nextcloud_direct_login", "_token=" + rcmail.env.request_token);
        if (btn_evt !== null) {
            btn_evt.target.innerText = " ";
            btn_evt.currentTarget.classList.add("button--loading");
        }

        setTimeout(function (t) {
            if (rcmail.env.nextcloud_direct_login_flow) {
                const hw = screen.availWidth / 2, hh = screen.availHeight / 2;
                const x = window.screenX + hw - 300, y = window.screenY + hh - 400;
                const popup = window.open(rcmail.env.nextcloud_direct_login_flow, "",
                    "noopener,noreferrer,popup,width=600,height=800,screenX=" + x + ",screenY=" + y);
                if (!popup) {
                    t?.append('<p>Click <a href="' + rcmail.env.nextcloud_direct_login_flow + '">here</a> if no window opened</p>');
                } else {
                    t?.dialog('close');
                    rcmail.display_message(rcmail.gettext("logging_in", DOMAIN), "loading", 10000);
                }
                rcmail.env.nextcloud_direct_login_flow = null;
            } else {
                t?.dialog('close');
                rcmail.display_message(rcmail.gettext("no_login_link", DOMAIN), "error", 10000);
            }
        }, 1000, dialog);

        window.nextcloud_direct_poll_interval = setInterval(function (t) {
            rcmail.refresh();
            if (rcmail.env.nextcloud_direct_login_available === true &&
                rcmail.env.nextcloud_direct_upload_available === true) {
                if (rcmail.task === "settings") {
                    rcmail.command('save');
                } else {
                    t?.dialog('close');
                }
                clearInterval(window.nextcloud_direct_poll_interval);
                rcmail.display_message(rcmail.gettext("logged_in", DOMAIN), "confirmation", 10000);
                if (pendingFiles && pendingFiles.length) {
                    uploadAllToCloud(pendingFiles);
                }
            }
        }, 500, dialog);
    };

    // --- credentials fetch (one round-trip, cached per upload batch) --------

    let credsCache = null;
    let credsCacheExpiry = 0;

    function fetchCredentials() {
        return new Promise((resolve, reject) => {
            // Re-use within 60s so a multi-file batch doesn't re-query.
            if (credsCache && Date.now() < credsCacheExpiry) {
                resolve(credsCache);
                return;
            }
            const once = (evt) => {
                rcmail.removeEventListener("plugin.nextcloud_direct_credentials_result", once);
                if (evt.status === "ok") {
                    credsCache = evt;
                    credsCacheExpiry = Date.now() + 60_000;
                    resolve(evt);
                } else {
                    reject(evt);
                }
            };
            rcmail.addEventListener("plugin.nextcloud_direct_credentials_result", once);
            rcmail.http_post("plugin.nextcloud_direct_credentials", "_token=" + rcmail.env.request_token);
        });
    }

    function invalidateCredentials() {
        credsCache = null;
        credsCacheExpiry = 0;
    }

    // --- share link creation via PHP ---------------------------------------

    function createShareLink(filename, filesize, mimetype) {
        return new Promise((resolve, reject) => {
            const once = (evt) => {
                rcmail.removeEventListener("plugin.nextcloud_direct_sharelink_result", once);
                if (evt.status === "ok") resolve(evt);
                else reject(evt);
            };
            rcmail.addEventListener("plugin.nextcloud_direct_sharelink_result", once);
            const params = new URLSearchParams({
                _token: rcmail.env.request_token,
                _filename: filename,
                _filesize: String(filesize),
                _mimetype: mimetype || "application/octet-stream",
            });
            rcmail.http_post("plugin.nextcloud_direct_sharelink", params.toString());
        });
    }

    // --- cleanup (sendBeacon) ----------------------------------------------

    function beaconCleanup({ path, transferId }) {
        try {
            const params = new URLSearchParams({ _token: rcmail.env.request_token });
            if (path) params.set("_path", path);
            if (transferId) params.set("_transferId", transferId);
            const url = rcmail.url("plugin.nextcloud_direct_cleanup");
            const blob = new Blob([params.toString()], { type: "application/x-www-form-urlencoded" });
            navigator.sendBeacon(url, blob);
        } catch (e) { /* best effort */ }
    }

    // --- WebDAV primitives --------------------------------------------------
    // All return a Promise that resolves with {status, headers, response}.
    // Uses XMLHttpRequest for upload.onprogress support.

    function davRequest(method, url, creds, { body, headers, onProgress, signal } = {}) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open(method, url, true);
            xhr.setRequestHeader("Authorization", basicAuthHeader(creds.loginName, creds.appPassword));
            xhr.setRequestHeader("OCS-APIRequest", "true");
            if (headers) for (const [k, v] of Object.entries(headers)) xhr.setRequestHeader(k, v);

            xhr.onload = () => resolve({
                status: xhr.status,
                statusText: xhr.statusText,
                responseText: xhr.responseText,
                getHeader: (n) => xhr.getResponseHeader(n),
            });
            xhr.onerror = () => reject({ kind: "network", xhr });
            xhr.onabort = () => reject({ kind: "abort" });
            if (onProgress && xhr.upload) {
                xhr.upload.onprogress = (e) => {
                    if (e.lengthComputable) onProgress(e.loaded, e.total);
                };
            }
            if (signal) {
                if (signal.aborted) { xhr.abort(); return; }
                signal.addEventListener("abort", () => xhr.abort(), { once: true });
            }

            xhr.send(body ?? null);
        });
    }

    async function mkcolIfMissing(url, creds) {
        // PROPFIND first; only MKCOL on 404 to avoid 405s on existing dirs.
        const p = await davRequest("PROPFIND", url, creds, { headers: { Depth: "0" } });
        if (p.status === 207 || p.status === 200) return;
        if (p.status === 404) {
            const m = await davRequest("MKCOL", url, creds);
            if (m.status >= 400 && m.status !== 405) {
                const err = new Error("mkdir failed: " + m.status);
                err.httpStatus = m.status;
                throw err;
            }
            return;
        }
        const err = new Error("folder probe failed: " + p.status);
        err.httpStatus = p.status;
        throw err;
    }

    async function uniqueFilename(folderUrl, creds, name) {
        // Try "name", then "name 1.ext", "name 2.ext", … up to 100.
        let candidate = name;
        for (let i = 0; i <= 100; i++) {
            const res = await davRequest("PROPFIND", folderUrl + "/" + encodePath(candidate), creds,
                { headers: { Depth: "0" } });
            if (res.status === 404) return candidate;
            if (res.status >= 500) throw new Error("filename probe failed: " + res.status);
            const dot = name.lastIndexOf(".");
            const base = dot > 0 ? name.slice(0, dot) : name;
            const ext = dot > 0 ? name.slice(dot) : "";
            candidate = base + " " + (i + 1) + ext;
        }
        throw new Error("couldn't find unique filename");
    }

    // --- single PUT ---------------------------------------------------------

    async function singlePutUpload(creds, folderUrl, filename, file, progressCb, signal) {
        const url = folderUrl + "/" + encodePath(filename);
        let attempt = 0;
        while (true) {
            attempt++;
            try {
                const res = await davRequest("PUT", url, creds, {
                    body: file,
                    headers: { "Content-Type": file.type || "application/octet-stream" },
                    onProgress: (loaded, total) => progressCb(loaded, file.size),
                    signal,
                });
                if (res.status >= 200 && res.status < 300) return;
                if ((res.status === 423 || res.status === 503) && attempt < 4) {
                    await sleep(500 * Math.pow(2, attempt));
                    continue;
                }
                const err = new Error("PUT failed: " + res.status);
                err.httpStatus = res.status;
                throw err;
            } catch (e) {
                if (e.kind === "abort") throw e;
                if (e.kind === "network" && attempt < 4) {
                    await sleep(500 * Math.pow(2, attempt));
                    continue;
                }
                throw e;
            }
        }
    }

    // --- chunked upload + resume -------------------------------------------

    async function chunkedUpload(creds, folderUrl, filename, file, progressCb, signal, transferId) {
        const user = encodeURIComponent(creds.loginName);
        const uploadsBase = creds.davBaseUrl + "/uploads/" + user + "/" + encodeURIComponent(transferId);
        const finalUrl = folderUrl + "/" + encodePath(filename);

        // MKCOL the chunk dir (idempotent on 405)
        {
            const m = await davRequest("MKCOL", uploadsBase, creds);
            if (m.status >= 400 && m.status !== 405) {
                const err = new Error("chunk dir MKCOL failed: " + m.status);
                err.httpStatus = m.status;
                throw err;
            }
        }

        // Find which chunks (by ordinal) are already complete, for resume.
        const existing = await listExistingChunks(uploadsBase, creds);

        const chunkSize = creds.chunkSize;
        const total = file.size;
        const numChunks = Math.ceil(total / chunkSize);
        // inFlight[i] = bytes currently in-flight for chunk i (for live progress)
        const inFlight = new Array(numChunks + 1).fill(0);
        let committed = 0;

        // Count already-uploaded bytes toward progress.
        for (let i = 1; i <= numChunks; i++) {
            if (existing.has(i)) {
                const size = i === numChunks ? (total - (i - 1) * chunkSize) : chunkSize;
                committed += size;
            }
        }
        progressCb(committed, total);

        const CONCURRENCY = creds.chunkConcurrency || 4;
        const pending = [];
        for (let i = 1; i <= numChunks; i++) {
            if (!existing.has(i)) pending.push(i);
        }

        async function uploadChunk(i) {
            if (signal && signal.aborted) throw { kind: "abort" };
            const start = (i - 1) * chunkSize;
            const end = Math.min(start + chunkSize, total);
            const blob = file.slice(start, end);
            const chunkUrl = uploadsBase + "/" + String(i);

            let attempt = 0;
            while (true) {
                attempt++;
                inFlight[i] = 0;
                try {
                    const res = await davRequest("PUT", chunkUrl, creds, {
                        body: blob,
                        headers: {
                            "Content-Type": "application/octet-stream",
                            "OC-Total-Length": String(total),
                        },
                        onProgress: (loaded) => {
                            inFlight[i] = loaded;
                            const live = inFlight.reduce((a, b) => a + b, 0);
                            progressCb(committed + live, total);
                        },
                        signal,
                    });
                    if (res.status >= 200 && res.status < 300) {
                        committed += (end - start);
                        inFlight[i] = 0;
                        progressCb(committed, total);
                        return;
                    }
                    if ((res.status === 423 || res.status === 503) && attempt < 5) {
                        inFlight[i] = 0;
                        await sleep(1000 * Math.pow(2, attempt));
                        continue;
                    }
                    const err = new Error("chunk PUT failed: " + res.status);
                    err.httpStatus = res.status;
                    throw err;
                } catch (e) {
                    if (e.kind === "abort") throw e;
                    if (e.kind === "network" && attempt < 5) {
                        inFlight[i] = 0;
                        await sleep(1000 * Math.pow(2, attempt));
                        continue;
                    }
                    throw e;
                }
            }
        }

        // Run up to CONCURRENCY chunks in parallel.
        let idx = 0;
        async function worker() {
            while (idx < pending.length) {
                const i = pending[idx++];
                await uploadChunk(i);
            }
        }
        const workers = [];
        for (let w = 0; w < Math.min(CONCURRENCY, pending.length); w++) workers.push(worker());
        await Promise.all(workers);

        // Assemble: MOVE .file → final destination.
        // Destination must be a URL Nextcloud itself can resolve against its own
        // base URI (/remote.php/dav/). When Mode A proxy is used, the browser-
        // facing path (/nc-dav/...) is unknown to Nextcloud, so we use the
        // server-side davDestBase (http://nextcloud:port/remote.php/dav) that
        // PHP passes down via credentials.
        const destBase = creds.davDestBase ||
            (location.origin + creds.davBaseUrl.replace(/^\/nc-dav/, "/remote.php/dav"));
        const destUrl = destBase + "/files/" + user + "/" + encodePath(creds.folder) + "/" + encodePath(filename);
        const moveRes = await davRequest("MOVE", uploadsBase + "/.file", creds, {
            headers: {
                "Destination": destUrl,
                "OC-Total-Length": String(total),
                "Overwrite": "T",
            },
            signal,
        });
        if (moveRes.status >= 400) {
            const err = new Error("MOVE failed: " + moveRes.status);
            err.httpStatus = moveRes.status;
            throw err;
        }
    }

    async function listExistingChunks(uploadsBase, creds) {
        const res = await davRequest("PROPFIND", uploadsBase, creds, { headers: { Depth: "1" } });
        const have = new Set();
        if (res.status !== 207) return have;
        // Parse returned hrefs; chunk names are 5-digit ordinals.
        const parser = new DOMParser();
        const doc = parser.parseFromString(res.responseText, "application/xml");
        const hrefs = doc.querySelectorAll("href, d\\:href, D\\:href");
        hrefs.forEach(h => {
            const m = /\/(\d+)(?:\/)?$/.exec(h.textContent.replace(/\/+$/, ""));
            if (m) have.add(parseInt(m[1], 10));
        });
        return have;
    }

    // --- body insertion (mirrors reference plugin link insertion) ----------

    function insertShareLinkIntoBody(file, shareResult) {
        const size = humanBytes(file.size);
        const url = shareResult.url;
        const password = shareResult.password;
        const expireDate = shareResult.expireDate;

        if (!rcmail.editor || !rcmail.editor.is_html()) {
            let msg = rcmail.editor ? rcmail.editor.get_content() : "";
            let txt = "\n" + file.name + " (" + size + ") <" + url + ">\n";
            if (password) txt += rcmail.gettext("password", DOMAIN) + ": " + password + "\n";
            if (expireDate) {
                const dt = new Date(expireDate);
                txt += rcmail.gettext("valid_until", DOMAIN) + ": " + dt.toLocaleDateString() + "\n";
            }
            const sig = rcmail.env.identity;
            if (sig && rcmail.env.signatures && rcmail.env.signatures[sig]) {
                const sigText = rcmail.env.signatures[sig].text.replace(/\r\n/g, '\n');
                const p = rcmail.env.top_posting ? msg.indexOf(sigText) : msg.lastIndexOf(sigText);
                if (p >= 0) msg = msg.substring(0, p) + txt + msg.substring(p);
                else msg += txt;
            } else {
                msg += txt;
            }
            if (rcmail.editor) rcmail.editor.set_content(msg);
            return;
        }

        // HTML editor: insert a styled <a> box before the signature, same look
        // as the reference plugin.
        const editor = rcmail.editor.editor;
        let sigElem = editor.dom.get("_rc_sig") || editor.dom.get("v1_rc_sig");

        // Build HTML string so all nodes belong to the editor's own document.
        let extraRows = "";
        let row = 3;
        if (password) {
            extraRows += `<span style="grid-area:${row} / 1 / span 1 / span 3;color:rgb(100,100,100);align-self:end;font-size:small;width:fit-content">`
                + rcmail.gettext("password", DOMAIN) + ": "
                + `<code style="font-family:monospace;font-size:13px">${password}</code></span>`;
            row++;
        }
        if (expireDate) {
            extraRows += `<span style="grid-area:${row} / 1 / span 1 / span 3;color:rgb(100,100,100);align-self:end;font-size:small;width:fit-content">`
                + rcmail.gettext("valid_until", DOMAIN) + ": " + new Date(expireDate).toLocaleDateString()
                + "</span>";
        }

        const html = `<p><a href="${url}" style="text-decoration:none;color:black;display:grid;grid-template-columns:auto 1fr auto 0fr;grid-auto-rows:min-content;align-items:baseline;background:rgb(220,220,220);max-width:400px;padding:1em;border-radius:10px;font-family:sans-serif">`
            + `<span style="grid-area:1 / 1;font-size:medium;max-width:280px;text-overflow:ellipsis;overflow:hidden;white-space:nowrap;width:fit-content">${file.name}</span>`
            + `<span style="grid-area:1 / 2;margin-left:1em;color:rgb(100,100,100);font-size:x-small;width:fit-content">${size}</span>`
            + `<span style="grid-area:2 / 1 / span 1 / span 3;color:rgb(100,100,100);align-self:end;font-size:small;white-space:nowrap;width:fit-content">${url}</span>`
            + extraRows
            + "</a></p>";

        if (sigElem) {
            sigElem.insertAdjacentHTML("beforebegin", html);
        } else {
            editor.getBody().insertAdjacentHTML("beforeend", html);
        }
    }

    // --- upload orchestration ----------------------------------------------

    async function uploadAllToCloud(files) {
        files = Array.from(files);
        let creds;
        try {
            creds = await fetchCredentials();
        } catch (evt) {
            if (evt && evt.status === "login_required") {
                promptLoginThen(files);
            } else {
                rcmail.display_message(t("missing_config"), "error", 10000);
            }
            return;
        }

        const user = encodeURIComponent(creds.loginName);
        const folderUrl = creds.davBaseUrl + "/files/" + user + "/" + encodePath(creds.folder);

        try {
            await mkcolIfMissing(folderUrl, creds);
        } catch (e) {
            handleHttpError(e);
            return;
        }

        const concurrency = Math.max(1, rcmail.env[NC_ENV.fileConcurrency] || 1);
        if (concurrency === 1) {
            for (const file of files) {
                await uploadOneToCloud(file, creds, folderUrl);
            }
        } else {
            // Upload up to `concurrency` files in parallel.
            let idx = 0;
            async function fileWorker() {
                while (idx < files.length) {
                    await uploadOneToCloud(files[idx++], creds, folderUrl);
                }
            }
            await Promise.all(Array.from({ length: Math.min(concurrency, files.length) }, fileWorker));
        }
    }

    async function uploadOneToCloud(file, creds, folderUrl) {
        const progressMsg = rcmail.display_message(
            t("upload_progress") + ": " + file.name + " (0%)", "loading", 3600000);

        // Cache the span Roundcube just created; find by file name since the
        // element has no predictable ID in this Roundcube build.
        const progressEl = (() => {
            const spans = document.querySelectorAll(".loading span");
            for (const s of spans) {
                if (s.textContent.includes(file.name)) return s;
            }
            return null;
        })();

        let filename;
        try {
            filename = await uniqueFilename(folderUrl, creds, file.name.normalize("NFC"));
        } catch (e) {
            rcmail.hide_message(progressMsg);
            handleHttpError(e);
            return;
        }

        const controller = new AbortController();
        const finalPath = (creds.folder ? creds.folder + "/" : "") + filename;
        let transferId = null;
        const useChunked = file.size > creds.singlePutThreshold;
        if (useChunked) transferId = uuid();

        // Cleanup hooks in case tab closes mid-upload.
        const onUnload = () => {
            if (useChunked && transferId) beaconCleanup({ transferId });
            else beaconCleanup({ path: finalPath });
        };
        window.addEventListener("beforeunload", onUnload);

        const progressCb = (loaded, total) => {
            if (!progressEl) return;
            const pct = total ? Math.round(loaded / total * 100) : 0;
            progressEl.textContent = t("upload_progress") + ": " + file.name + " (" + pct + "%)";
        };

        try {
            if (useChunked) {
                await chunkedUpload(creds, folderUrl, filename, file, progressCb, controller.signal, transferId);
            } else {
                await singlePutUpload(creds, folderUrl, filename, file, progressCb, controller.signal);
            }
        } catch (e) {
            rcmail.hide_message(progressMsg);
            window.removeEventListener("beforeunload", onUnload);
            // On failure, try to clean orphan in the background.
            if (useChunked && transferId) beaconCleanup({ transferId });
            else beaconCleanup({ path: finalPath });
            handleHttpError(e);
            return;
        }
        window.removeEventListener("beforeunload", onUnload);
        rcmail.hide_message(progressMsg);

        // Finalizing / share link creation.
        const finalizing = rcmail.display_message(t("upload_finalizing"), "loading", 3600000);
        let shareResult;
        try {
            shareResult = await createShareLink(filename, file.size, file.type);
        } catch (evt) {
            rcmail.hide_message(finalizing);
            rcmail.show_popup_dialog(t("cannot_link"), t("upload_warning_title"));
            return;
        }
        rcmail.hide_message(finalizing);

        // Augment shareResult with folder for cleanup path bookkeeping.
        shareResult.folder = creds.folder;

        insertShareLinkIntoBody({ name: filename, size: file.size, type: file.type }, shareResult);
        pendingCleanupPaths.add(finalPath);

        rcmail.display_message(filename + " " + t("upload_success_link_inserted"),
            "confirmation", 5000);
    }

    function handleHttpError(e) {
        if (e && e.kind === "abort") {
            rcmail.display_message(t("upload_cancelled"), "notice", 5000);
            return;
        }
        if (e && e.kind === "network") {
            rcmail.display_message(t("upload_network_error"), "error", 10000);
            return;
        }
        const status = e && e.httpStatus;
        switch (status) {
            case 401:
            case 403:
                invalidateCredentials();
                rcmail.display_message(t("upload_auth_expired"), "error", 10000);
                rcmail.env.nextcloud_direct_upload_available = false;
                break;
            case 507:
                rcmail.display_message(t("upload_quota_exceeded"), "error", 10000);
                break;
            case 0:
                rcmail.display_message(t("upload_network_error"), "error", 10000);
                break;
            default:
                rcmail.display_message(t("upload_failed") + " " + (status || "") +
                    " " + (e && e.message || ""), "error", 10000);
        }
    }

    function promptLoginThen(pendingFiles) {
        const dialog = rcmail.show_popup_dialog(
            t("file_big_not_logged_in_explain").replace("%size%", "?"),
            t("file_big"), [
                {
                    text: t("login_and_link_file"),
                    class: "mainaction login",
                    click: (e) => {
                        rcmail.nextcloud_direct_login_button_click_handler(e, dialog, pendingFiles);
                    },
                },
                {
                    text: rcmail.gettext("cancel"),
                    class: "cancel",
                    click: () => dialog.dialog("close"),
                },
            ]);
    }

    // --- init: intercept rcmail.file_upload --------------------------------

    // Tracks paths of successfully uploaded files so they can be cleaned up
    // if the user cancels the compose window without sending.
    const pendingCleanupPaths = new Set();

    rcmail.addEventListener("init", function () {
        // Kick off a login-status probe immediately.
        rcmail.http_get("plugin.nextcloud_direct_checklogin");

        rcmail.addEventListener("compose-cancel", function () {
            pendingCleanupPaths.forEach(path => beaconCleanup({ path }));
            pendingCleanupPaths.clear();
        });

        rcmail.addEventListener("message_sent", function () {
            pendingCleanupPaths.clear();
        });

        // Keep original as fallback (small files, regular attachments).
        rcmail.__nc_direct_file_upload = rcmail.file_upload;

        rcmail.file_upload = function (files, post_args, props) {
            if (rcmail.env.nextcloud_direct_login_available !== true &&
                rcmail.env.nextcloud_direct_upload_available !== true) {
                return rcmail.__nc_direct_file_upload(files, post_args, props);
            }

            files = Array.from(files);
            const total = files.reduce((s, f) => s + f.size, 0);
            const maxSize = rcmail.env.max_filesize;
            const softlimit = rcmail.env[NC_ENV.softlimit];

            const overLimit = total > maxSize;
            const overSoft = softlimit && total > softlimit;

            if (!overLimit && !overSoft) {
                return rcmail.__nc_direct_file_upload(files, post_args, props);
            }

            if (overLimit && rcmail.env.nextcloud_direct_upload_available !== true) {
                // Need login first.
                const dialog = rcmail.show_popup_dialog(
                    t("file_too_big_not_logged_in_explain").replace("%limit%", humanBytes(maxSize)),
                    t("file_too_big"), [
                        {
                            text: t("login"),
                            class: "mainaction login",
                            click: (e) => rcmail.nextcloud_direct_login_button_click_handler(e, dialog, files),
                        },
                        {
                            text: rcmail.gettext("close"),
                            class: "cancel",
                            click: () => dialog.dialog("close"),
                        },
                    ]);
                return false;
            }

            if (overLimit) {
                // Logged-in. Behavior: "upload" or "prompt".
                if (rcmail.env[NC_ENV.behavior] === "upload") {
                    uploadAllToCloud(files);
                    return false;
                }
                const dialog = rcmail.show_popup_dialog(
                    t("file_too_big_explain").replace("%limit%", humanBytes(maxSize)),
                    t("file_too_big"), [
                        {
                            text: t("link_file"),
                            class: "mainaction",
                            click: () => { uploadAllToCloud(files); dialog.dialog("close"); },
                        },
                        {
                            text: rcmail.gettext("cancel"),
                            class: "cancel",
                            click: () => dialog.dialog("close"),
                        },
                    ]);
                return false;
            }

            // Soft-limit hit.
            if (rcmail.env.nextcloud_direct_upload_available !== true) {
                const dialog = rcmail.show_popup_dialog(
                    t("file_big_not_logged_in_explain").replace("%size%", humanBytes(total)),
                    t("file_big"), [
                        {
                            text: t("login_and_link_file"),
                            class: "mainaction login",
                            click: (e) => rcmail.nextcloud_direct_login_button_click_handler(e, dialog, files),
                        },
                        {
                            text: t("attach"),
                            class: "secondary",
                            click: () => {
                                rcmail.__nc_direct_file_upload(files, post_args, props);
                                dialog.dialog("close");
                            },
                        },
                    ]);
            } else {
                const dialog = rcmail.show_popup_dialog(
                    t("file_big_explain").replace("%size%", humanBytes(total)),
                    t("file_big"), [
                        {
                            text: t("link_file"),
                            class: "mainaction",
                            click: () => { uploadAllToCloud(files); dialog.dialog("close"); },
                        },
                        {
                            text: t("attach"),
                            class: "secondary",
                            click: () => {
                                rcmail.__nc_direct_file_upload(files, post_args, props);
                                dialog.dialog("close");
                            },
                        },
                    ]);
            }
            return false;
        };

    });

})();
