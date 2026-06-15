<?php
// /WC2026/api/_fanwall_common.php
// Shared bootstrap + helpers for the Fan Wall JSON endpoints.

declare(strict_types=1);

require_once __DIR__ . '/../_session.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../connections/config.php';     // provides $conn (mysqli)
require_once __DIR__ . '/../connections/functions.php';  // shared project helpers

/** Emit JSON and stop. */
function fw_out(array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Emit a failure JSON (and HTTP status) and stop. */
function fw_fail(string $msg, int $code = 200): void {
    http_response_code($code);
    fw_out(['ok' => false, 'message' => $msg]);
}

/** Validate the session CSRF token. */
function fw_check_csrf(string $token): void {
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)$token)) {
        fw_fail('Invalid request token. Please refresh and try again.', 419);
    }
}

/** Human-friendly relative time. */
function fw_time_ago(?string $dt): string {
    if (!$dt) return 'just now';
    $t = strtotime($dt);
    if ($t === false) return 'just now';
    $diff = time() - $t;
    if ($diff < 45)    return 'just now';
    if ($diff < 3600)  return max(1, (int)floor($diff / 60)) . 'm ago';
    if ($diff < 86400) return (int)floor($diff / 3600) . 'h ago';
    return date('d M', $t);
}

/** Prepared SELECT helper -> array of assoc rows. */
function fw_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types && $params) $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) return [];
    $res = $stmt->get_result();
    return $res ? ($res->fetch_all(MYSQLI_ASSOC) ?: []) : [];
}

$FW_USER_ID = (int)($_SESSION['USER_ID'] ?? 0);
$FW_NAME    = trim((string)($_SESSION['FULL_NAME'] ?? 'Participant'));
if ($FW_NAME === '') $FW_NAME = 'Participant';

if ($FW_USER_ID <= 0) {
    fw_fail('Not authenticated.', 401);
}
