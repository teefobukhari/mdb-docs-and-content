<?php
// /WC2026/api/match_react.php — set/toggle the current user's reaction on a match.

require __DIR__ . '/_fanwall_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fw_fail('POST required.', 405);

$raw = file_get_contents('php://input');
$in  = json_decode((string)$raw, true);
if (!is_array($in)) $in = $_POST;

fw_check_csrf((string)($in['csrf'] ?? ''));

$matchId  = (int)($in['match_id'] ?? $in['match'] ?? 0);
$reaction = (string)($in['reaction'] ?? '');
$allowed  = ['like', 'fire', 'goal', 'heart'];
if ($matchId <= 0 || !in_array($reaction, $allowed, true)) fw_fail('Invalid reaction.');

// What does the user currently have?
$cur = fw_rows($conn, "SELECT reaction FROM WC2026_Match_Reactions WHERE match_id = ? AND user_id = ? LIMIT 1", "ii", [$matchId, $FW_USER_ID]);
$current = $cur ? $cur[0]['reaction'] : null;

if ($current === $reaction) {
    // toggle off
    $stmt = $conn->prepare("DELETE FROM WC2026_Match_Reactions WHERE match_id = ? AND user_id = ?");
    if ($stmt) { $stmt->bind_param("ii", $matchId, $FW_USER_ID); $stmt->execute(); }
    $my = null;
} else {
    $stmt = $conn->prepare(
        "INSERT INTO WC2026_Match_Reactions (match_id, user_id, reaction) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE reaction = VALUES(reaction)"
    );
    if (!$stmt) fw_fail('Database error.');
    $stmt->bind_param("iis", $matchId, $FW_USER_ID, $reaction);
    if (!$stmt->execute()) fw_fail('Unable to save reaction.');
    $my = $reaction;
}

$reactions = ['like' => 0, 'fire' => 0, 'goal' => 0, 'heart' => 0];
foreach (fw_rows($conn, "SELECT reaction, COUNT(*) AS n FROM WC2026_Match_Reactions WHERE match_id = ? GROUP BY reaction", "i", [$matchId]) as $r) {
    if (isset($reactions[$r['reaction']])) $reactions[$r['reaction']] = (int)$r['n'];
}

fw_out(['ok' => true, 'reactions' => $reactions, 'my' => $my]);
