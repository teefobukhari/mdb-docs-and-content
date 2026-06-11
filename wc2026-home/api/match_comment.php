<?php
// /WC2026/api/match_comment.php — add a comment to a match.

require __DIR__ . '/_fanwall_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fw_fail('POST required.', 405);
fw_check_csrf($_POST['csrf'] ?? '');

$matchId = (int)($_POST['match_id'] ?? $_POST['match'] ?? 0);
$body    = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 500);
if ($matchId <= 0 || $body === '') fw_fail('Invalid comment.');

$stmt = $conn->prepare(
    "INSERT INTO WC2026_Match_Comments (match_id, user_id, author_name, body) VALUES (?, ?, ?, ?)"
);
if (!$stmt) fw_fail('Database error.');
$stmt->bind_param("iiss", $matchId, $FW_USER_ID, $FW_NAME, $body);
if (!$stmt->execute()) fw_fail('Unable to save your comment.');

fw_out(['ok' => true, 'comment' => ['name' => $FW_NAME, 'body' => $body, 'created_at' => 'just now']]);
