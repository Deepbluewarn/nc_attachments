<?php

$labels['login_failed'] = 'Nextcloud 로그인에 실패했습니다. 잠시 후 다시 시도해 주세요.';
$labels['logging_in'] = 'Nextcloud에 로그인하는 중입니다.';
$labels['no_login_link'] = '로그인 링크를 받지 못했습니다. 잠시 후 다시 시도해 주세요.';
$labels['logged_in'] = 'Nextcloud에 성공적으로 로그인되었습니다.';
$labels['login'] = '로그인';
$labels['login_and_link_file'] = '로그인 후 파일 링크 공유';

$labels['file_too_big'] = '파일 크기 초과';
$labels['file_too_big_not_logged_in_explain'] = '파일이 %limit%보다 커서 첨부할 수 없습니다. 아래에서 Nextcloud 계정에 연결하면 파일 링크로 공유할 수 있습니다.';
$labels['file_too_big_explain'] = '파일이 %limit%보다 커서 첨부할 수 없습니다. Nextcloud 파일 링크로 공유할 수 있습니다.';

$labels['file_big'] = '파일을 어떻게 공유하시겠습니까?';
$labels['file_big_explain'] = '파일 크기가 큽니다: %size%. 파일 링크로 공유하면 메일 용량을 줄일 수 있습니다.';
$labels['file_big_not_logged_in_explain'] = '파일 크기가 큽니다: %size%. 아래에서 Nextcloud 계정에 연결하면 파일 링크로 공유하여 메일 용량을 줄일 수 있습니다.';

$labels['link_file'] = '업로드 후 링크 공유';
$labels['attach'] = '파일 직접 첨부';

$labels['upload_success_link_inserted'] = 'Nextcloud 업로드 완료 및 링크가 삽입되었습니다';
$labels['upload_failed_title'] = 'Nextcloud 업로드 실패';
$labels['upload_warning_title'] = 'Nextcloud 경고';
$labels['upload_failed'] = 'Nextcloud 업로드 실패:';

$labels['upload_progress'] = 'Nextcloud에 업로드 중';
$labels['upload_finalizing'] = '업로드를 마무리하는 중입니다. 잠시 기다려 주세요…';
$labels['upload_resuming'] = '이전 업로드를 재개하는 중…';
$labels['upload_retrying'] = '연결 문제가 발생했습니다. 재시도 중…';
$labels['upload_cancel'] = '업로드 취소';
$labels['upload_cancelled'] = '업로드가 취소되었습니다';
$labels['upload_network_error'] = '네트워크 오류가 발생했습니다. 연결 상태를 확인하고 다시 시도해 주세요.';
$labels['upload_quota_exceeded'] = 'Nextcloud 저장 용량이 초과되었습니다.';
$labels['upload_antivirus_blocked'] = 'Nextcloud의 바이러스 검사에서 파일이 차단되었습니다.';
$labels['upload_auth_expired'] = 'Nextcloud 세션이 만료되었습니다. 다시 로그인해 주세요.';
$labels['upload_cors_error'] = '브라우저가 업로드를 차단했습니다 (CORS). Nextcloud 프록시 설정을 확인해 주세요.';

$labels['delete_ok'] = 'Nextcloud에서 파일이 성공적으로 삭제되었습니다';
$labels['delete_error_explain'] = '파일 삭제에 실패했습니다. 오류 코드: <b>%reason%</b>. 오류를 무시하고 목록에서만 삭제할 수 있습니다.';
$labels['remove_attachment'] = 'Nextcloud 첨부파일 제거';
$labels['remove_from_list'] = '오류 무시하고 목록에서 제거';
$labels['remove_from_nextcloud_question'] = 'Nextcloud 첨부파일입니다. Nextcloud에서도 삭제하시겠습니까? 메시지 본문의 링크는 직접 삭제해야 합니다.';
$labels['remove_from_nextcloud'] = 'Nextcloud에서 삭제';
$labels['keep_in_nextcloud'] = 'Nextcloud에는 유지하고 목록에서만 제거';

$labels['missing_config'] = 'Nextcloud 플러그인 설정이 없습니다. 관리자에게 문의해 주세요.';
$labels['cannot_mkdir'] = '업로드용 하위 폴더를 생성할 수 없습니다:';
$labels['folder_access'] = '업로드 폴더에 접근할 수 없습니다. 권한을 확인하거나 관리자에게 문의해 주세요.';
$labels['cannot_link'] = '파일 업로드는 완료됐지만 공유 링크를 생성할 수 없습니다. 직접 링크를 만들거나 관리자에게 문의해 주세요.';

$labels['cloud_attachments'] = '클라우드 첨부파일';
$labels['cloud_server'] = '클라우드 서버';
$labels['status'] = '연결 상태';
$labels['connected_as'] = '연결됨:';
$labels['disconnect'] = '연결 해제';
$labels['connect'] = '연결';
$labels['not_connected'] = '연결되지 않음';

$labels['not_configured'] = '(설정되지 않음)';
$labels['share_options'] = '공유 링크 옵션';
$labels['server_default'] = '서버 기본값: ';

$labels['password_protected_links'] = '링크에 비밀번호 보호 적용';
$labels['expire_links'] = '링크 만료일 설정';
$labels['expire_links_after'] = '링크 만료 기간 (일)';

$labels['password'] = '비밀번호';
$labels['valid_until'] = '유효 기간';

$labels['filelink_attachment'] = '파일 링크 첨부';
$labels['file_is_filelink_download_below'] = '$filename 파일이 파일 링크로 첨부되었습니다. 아래 링크에서 다운로드할 수 있습니다.';
$labels['size'] = '크기';
$labels['link'] = '링크';
$labels['available_until'] = '사용 가능 기간';
$labels['valid_until_expires'] = '$validuntil까지 유효하며, 이후 다운로드 링크가 만료됩니다';
$labels['attachment_disclaimer'] = '첨부파일은 $serverurl에 저장되며 해당 서비스 제공자의 이용 약관 및 개인정보 처리방침이 적용됩니다. <b>만료일 이후에는 다운로드가 불가능하며, 발신자가 언제든지 파일을 삭제할 수 있습니다. 파일을 보존하려면 로컬에 저장해 두세요.</b> 특히 실행 파일이나 오피스 문서는 신뢰할 수 있는 발신자의 것만 열어보세요.';
$labels['copyright'] = 'Roundcube용 $repolocation($author 제작)으로 생성됨';
$labels['icons'] = '아이콘';
$labels['deletion'] = '삭제';
