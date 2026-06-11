<?php
// /WC2026/api/match_feed.php?match=ID
// Returns comments + reaction counts (+ the current user's reaction) for a match.

require __DIR__ . '/_fanwall_common.php';

$matchId = (int)($_GET['match'] ?? $_GET['match_id'] ?? 0);
if ($matchId <= 0) fw_fail('Invalid match.');

$comments = [];
foreach (fw_rows(
    $conn,
    "SELECT author_name, body, created_at
     FROM WC2026_Match_Comments
     WHERE match_id = ? AND status = 'Active'
     ORDER BY created_at DESC
     LIMIT 60",
    "i",
    [$matchId]
) as $c) {
    $comments[] = ['name' => $c['author_name'], 'body' => $c['body'], 'created_at' => fw_time_ago($c['created_at'])];
}

$reactions = ['like' => 0, 'fire' => 0, 'goal' => 0, 'heart' => 0];
foreach (fw_rows(
    $conn,
    "SELECT reaction, COUNT(*) AS n FROM WC2026_Match_Reactions WHERE match_id = ? GROUP BY reaction",
    "i",
    [$matchId]
) as $r) {
    if (isset($reactions[$r['reaction']])) $reactions[$r['reaction']] = (int)$r['n'];
}

$my = null;
$mine = fw_rows($conn, "SELECT reaction FROM WC2026_Match_Reactions WHERE match_id = ? AND user_id = ? LIMIT 1", "ii", [$matchId, $FW_USER_ID]);
if ($mine) $my = $mine[0]['reaction'];

fw_out(['ok' => true, 'comments' => $comments, 'reactions' => $reactions, 'my' => $my]);
