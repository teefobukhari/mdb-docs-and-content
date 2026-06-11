<?php
// /WC2026/api/social_comment.php
// Add a comment to a Fan Wall post.

require __DIR__ . '/_fanwall_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fw_fail('POST required.', 405);
fw_check_csrf($_POST['csrf'] ?? '');

$postId = (int)($_POST['post_id'] ?? 0);
$body   = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 500);
if ($postId <= 0 || $body === '') fw_fail('Invalid comment.');

// Make sure the post exists and is active.
$exists = fw_rows($conn, "SELECT id FROM WC2026_Fan_Wall WHERE id = ? AND status = 'Active' LIMIT 1", "i", [$postId]);
if (!$exists) fw_fail('Post not found.');

$stmt = $conn->prepare(
    "INSERT INTO WC2026_Fan_Wall_Comments (post_id, user_id, author_name, body)
     VALUES (?, ?, ?, ?)"
);
if (!$stmt) fw_fail('Database error.');
$stmt->bind_param("iiss", $postId, $FW_USER_ID, $FW_NAME, $body);
if (!$stmt->execute()) fw_fail('Unable to save your comment.');

$upd = $conn->prepare(
    "UPDATE WC2026_Fan_Wall p
     SET comments_count = (SELECT COUNT(*) FROM WC2026_Fan_Wall_Comments c WHERE c.post_id = p.id AND c.status = 'Active')
     WHERE p.id = ?"
);
if ($upd) { $upd->bind_param("i", $postId); $upd->execute(); }

fw_out(['ok' => true, 'comment' => ['name' => $FW_NAME, 'body' => $body]]);
