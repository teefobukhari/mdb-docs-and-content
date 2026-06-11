<?php
// /WC2026/api/social_like.php
// Toggle a like on a Fan Wall post. Accepts JSON or form body.

require __DIR__ . '/_fanwall_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fw_fail('POST required.', 405);

$raw = file_get_contents('php://input');
$in  = json_decode((string)$raw, true);
if (!is_array($in)) $in = $_POST;

fw_check_csrf((string)($in['csrf'] ?? ''));

$postId = (int)($in['post_id'] ?? 0);
$liked  = !empty($in['liked']) && $in['liked'] !== 'false' && $in['liked'] !== '0';
if ($postId <= 0) fw_fail('Invalid post.');

if ($liked) {
    $stmt = $conn->prepare("INSERT IGNORE INTO WC2026_Fan_Wall_Likes (post_id, user_id) VALUES (?, ?)");
} else {
    $stmt = $conn->prepare("DELETE FROM WC2026_Fan_Wall_Likes WHERE post_id = ? AND user_id = ?");
}
if ($stmt) {
    $stmt->bind_param("ii", $postId, $FW_USER_ID);
    $stmt->execute();
}

// Keep the denormalised counter in sync.
$upd = $conn->prepare(
    "UPDATE WC2026_Fan_Wall p
     SET likes_count = (SELECT COUNT(*) FROM WC2026_Fan_Wall_Likes l WHERE l.post_id = p.id)
     WHERE p.id = ?"
);
if ($upd) { $upd->bind_param("i", $postId); $upd->execute(); }

$count = 0;
$r = fw_rows($conn, "SELECT likes_count FROM WC2026_Fan_Wall WHERE id = ? LIMIT 1", "i", [$postId]);
if ($r) $count = (int)$r[0]['likes_count'];

fw_out(['ok' => true, 'likes' => $count, 'liked' => $liked]);
