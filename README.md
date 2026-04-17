> ⚠️ **AI 활용 알림**: 이 플러그인은 AI (Claude)의 도움을 받아 개발되었습니다. 프로덕션 환경에서 사용하기 전에 충분한 테스트가 필요합니다.

> **English version**: [README.en.md](README.en.md)

# nextcloud_direct

**브라우저 → Nextcloud 직접 업로드** Roundcube 플러그인입니다. Roundcube PHP는 인증 credential을 발급하고 공유 링크를 생성하기만 하며, 파일 바이트는 PHP를 거치지 않습니다. nginx `client_max_body_size`, PHP `post_max_size`, PHP 메모리/타임아웃 제한으로 인한 대용량 파일 업로드 문제를 해결합니다.

## 주요 기능

- Nextcloud WebDAV로의 직접 XHR 업로드 (진행률 표시 + 취소 기능)
- 청크 업로드 (기본 10 MB/청크) **네트워크 단절 시 재개 가능**
- Device Flow 앱 패스워드 인증 전용 (IMAP 비밀번호 폴백 없음)
- `navigator.sendBeacon` 기반 고아 파일 정리 (탭 닫기/업로드 중단 시)
- CSRF 보호된 AJAX 엔드포인트
- Drop-in 통합: `nextcloud_attachments`와 동일한 파일 선택기 / 드래그앤드롭 / 붙여넣기 UX

---

## 배포 모드

### Mode A (권장) — Roundcube nginx 스트림 프록시

브라우저는 같은 출처의 `https://mail.example.com/nc-dav/…` 경로를 봅니다. nginx가 **버퍼링 없이** Nextcloud로 스트림 프록시하므로, PHP 메모리/타임아웃 제한이 업로드 바이트에 적용되지 않습니다.

```nginx
server {
    server_name mail.example.com;

    # WebDAV (업로드/다운로드) — 스트림 프록시, 크기 제한 없음
    location /nc-dav/ {
        proxy_pass https://nextcloud.example.com/remote.php/dav/;
        proxy_http_version 1.1;
        proxy_request_buffering off;        # ← 핵심: 버퍼링 비활성화
        client_max_body_size 0;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
        proxy_set_header Host nextcloud.example.com;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto https;
        proxy_pass_request_headers on;      # Authorization 헤더 전달
    }

    # OCS (공유 링크 생성) — 작은 요청만, 서버 측에서만 사용
    location /nc-ocs/ {
        proxy_pass https://nextcloud.example.com/ocs/;
        proxy_set_header Host nextcloud.example.com;
        proxy_pass_request_headers on;
    }

    # … 기존 Roundcube location / { … } …
}
```

설정:

```php
$config["nextcloud_direct_dav_base_url"] = "/nc-dav";
$config["nextcloud_direct_ocs_base_url"] = "/nc-ocs";
$config["nextcloud_direct_server"] = "https://nextcloud.example.com";
```

**장점:** CORS 불필요, Nextcloud 수정 불필요, PHP 우회
**트레이드오프:** 업로드 바이트가 Roundcube NIC을 경유합니다(같은 IDC/VPC면 문제없음). Roundcube nginx가 처리량의 상한입니다(PHP 대비 ~10배 빠름).

### Mode B — Nextcloud nginx의 CORS 헤더 주입

브라우저 → Nextcloud 직접 연결. Nextcloud 역프록시의 `/remote.php/dav/`에 CORS 헤더를 추가해야 합니다(Nextcloud 코어는 CORS를 설정하지 않음 — `cors_allowed_domains`가 WebDAV에 적용되지 않음). 복잡하지만 Roundcube nginx 수정이 필요 없습니다.

```php
$config["nextcloud_direct_dav_base_url"] = "https://nextcloud.example.com/remote.php/dav";
$config["nextcloud_direct_ocs_base_url"] = "https://nextcloud.example.com/ocs";
```

### Mode C — 서드파티 Nextcloud 앱

Nextcloud 측의 앱(예: `webappassword`)이 CORS 친화적인 업로드 엔드포인트를 제공할 수 있습니다. Mode B와 동일한 브라우저 측 설정을 사용합니다.

---

## 설치

```sh
cd plugins/
git clone … nextcloud_direct
```

Roundcube 1.6+ 이상은 Guzzle을 번들로 포함하므로 플러그인 디렉토리에서 `composer require`를 실행할 필요가 없습니다.

`config/config.inc.php`에서 활성화합니다:

```php
$config['plugins'] = ['nextcloud_direct', /* … */];
```

`config.inc.php.dist` → `config.inc.php`로 복사하고 편집합니다.

### 첫 사용자 흐름

1. 사용자가 작성 메시지를 열기
2. 소프트리밋(또는 max_message_size)보다 큰 파일 선택
3. 대화 상자에서 Nextcloud 로그인을 요청하며 Device Flow URL을 팝업으로 열기
4. 사용자가 Nextcloud에서 승인(2FA 등), 앱 패스워드가 RC 설정에 저장됨
5. 업로드가 브라우저 → WebDAV로 직접 진행

사용자는 Nextcloud 보안 설정의 "Devices & sessions"에서 또는 RC 작성 설정 페이지의 "연결 해제" 링크를 통해 앱 패스워드를 언제든 폐기할 수 있습니다.

---

## 보안 모델

- **앱 패스워드만 사용합니다.** IMAP/SSO 비밀번호는 브라우저로 전송되지 않습니다. 앱 패스워드는 WebDAV 스코프의 토큰이며 사용자가 개별적으로 폐기할 수 있습니다.
- `plugin.nextcloud_direct_credentials` AJAX 엔드포인트는 RC의 `_token` CSRF 검사로 보호되며, 인증된 RC 세션 소유자에게만 비밀번호를 반환합니다.
- 브라우저는 비밀번호를 단일 업로드 배치 동안만 클로저 변수에 보관한 후 삭제합니다.
- HTTPS를 가정합니다. 이 플러그인을 평문 HTTP로 실행하는 것은 안전하지 않습니다.

---

## 설정 참고

[`config.inc.php.dist`](config.inc.php.dist)를 참고하세요. 주요 설정:

| 키 | 기본값 | 설명 |
|-----|---------|-------|
| `server` | (필수) | Device Flow를 위해 PHP에서 사용할 NC 절대 URL |
| `dav_base_url` | `/nc-dav` | 브라우저가 보는 WebDAV 베이스. 상대 = Mode A |
| `ocs_base_url` | `/nc-ocs` | 브라우저가 보는 OCS 베이스 |
| `folder` | `Mail Attachments` | NC 사용자 내 대상 폴더 |
| `chunk_size_mb` | `10` | 청크 크기(MB) |
| `single_put_threshold_mb` | `10` | 이 크기 이하의 파일은 단일 PUT 사용 |
| `softlimit` | `25M` | 이 크기 이상에서 사용자 프롬프트 표시 |
| `behavior` | `prompt` | `prompt` 또는 `upload` |
| `password_protected_links` | `false` | 비밀번호 보호 공유 링크 생성 |
| `expire_links` | `false` | 만료 일수 또는 `false` |

---

## 문제 해결

**"login_required" (로그인 후에도):** 앱 패스워드 프로브가 401/403을 반환했습니다. `ncdirect` 로그(`logs/ncdirect`)에서 정확한 상태 코드를 확인하세요.

**브라우저 콘솔의 CORS 오류:** Mode A 프록시가 설정되지 않았거나 브라우저가 절대 Nextcloud URL을 사용하고 있습니다. `dav_base_url`이 `/`로 시작하는지 확인하고 `/nc-dav/`가 실제로 올바르게 프록시되는지 테스트하세요(`curl -u user:pass https://mail.example.com/nc-dav/files/user/`).

**업로드가 100%에서 멈춘 후 실패:** 청크 업로드의 최종 `MOVE`는 파일 크기에 비례하는 시간이 걸립니다(특히 Nextcloud <28). 대용량 파일을 정기적으로 업로드하는 경우 `proxy_read_timeout`을 늘리세요.

**탭이 열린 상태로 업로드 중단됨:** 역프록시의 `client_body_timeout` / `proxy_send_timeout`을 확인하세요.

---

## 미구현 기능

- 폴더 레이아웃(해시/날짜 서브폴더) — 참고 플러그인이 지원하지만 이 플러그인은 `folder` 아래의 평면 구조만 지원합니다.
- HTML 첨부 파일 언어 재정의 — 항상 사용자의 RC 표시 언어를 사용합니다.
- 본문 HTML의 첨부 파일 체크섬.

---

기반: [`nextcloud_attachments`](https://github.com/bnnt/nextcloud_attachments) (Bennet Becker, MIT)
