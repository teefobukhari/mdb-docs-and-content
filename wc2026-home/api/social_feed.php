<?php
// /WC2026/api/social_feed.php
// Returns the Fan Wall feed + a "recent" list (last 15 minutes, capped at 30)
// for the notification bar.

require __DIR__ . '/_fanwall_common.php';

$PER_PAGE     = 15;   // posts per page
$NOTIFY_MINS  = 15;   // notification window
$NOTIFY_LIMIT = 30;   // notification cap

$page   = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $PER_PAGE; // ints -> safe to inline

$total = 0;
$tr = fw_rows($conn, "SELECT COUNT(*) AS c FROM WC2026_Fan_Wall WHERE status = 'Active'");
if ($tr) $total = (int)$tr[0]['c'];

$posts = fw_rows(
    $conn,
    "SELECT id, user_id, author_name, author_location, body, photo_path,
            likes_count, comments_count, created_at
     FROM WC2026_Fan_Wall
     WHERE status = 'Active'
     ORDER BY created_at DESC
     LIMIT {$PER_PAGE} OFFSET {$offset}"
);

$ids = [];
foreach ($posts as $p) $ids[] = (int)$p['id'];

$likedSet = [];
$commentsByPost = [];

if ($ids) {
    $in = implode(',', $ids); // ids are integers cast above -> safe to inline

    foreach (fw_rows(
        $conn,
        "SELECT post_id FROM WC2026_Fan_Wall_Likes WHERE user_id = ? AND post_id IN ($in)",
        "i",
        [$FW_USER_ID]
    ) as $r) {
        $likedSet[(int)$r['post_id']] = true;
    }

    foreach (fw_rows(
        $conn,
        "SELECT post_id, author_name, body
         FROM WC2026_Fan_Wall_Comments
         WHERE status = 'Active' AND post_id IN ($in)
         ORDER BY created_at ASC"
    ) as $c) {
        $commentsByPost[(int)$c['post_id']][] = [
            'name' => $c['author_name'],
            'body' => $c['body'],
        ];
    }
}

$out = [];
$recent = [];

foreach ($posts as $p) {
    $pid = (int)$p['id'];
    $out[] = [
        'id'         => $pid,
        'name'       => $p['author_name'],
        'body'       => $p['body'],
        'photo'      => $p['photo_path'] ?: '',
        'likes'      => (int)$p['likes_count'],
        'liked'      => isset($likedSet[$pid]),
        'comments'   => $commentsByPost[$pid] ?? [],
        'created_at' => fw_time_ago($p['created_at']),
    ];
}

/* Notification "recent" list (last N minutes) — only needed on the first page. */
if ($page === 1) {
    $cutoff = date('Y-m-d H:i:s', time() - ($NOTIFY_MINS * 60));
    foreach (fw_rows(
        $conn,
        "SELECT author_name, body, created_at FROM WC2026_Fan_Wall
         WHERE status = 'Active' AND created_at >= ?
         ORDER BY created_at DESC LIMIT {$NOTIFY_LIMIT}",
        "s",
        [$cutoff]
    ) as $r) {
        $recent[] = ['name' => $r['author_name'], 'body' => $r['body'], 'created_at' => fw_time_ago($r['created_at'])];
    }
}

$hasMore = ($offset + count($out)) < $total;
fw_out([
    'ok'      => true,
    'posts'   => $out,
    'recent'  => $recent,
    'page'    => $page,
    'perPage' => $PER_PAGE,
    'total'   => $total,
    'hasMore' => $hasMore,
]);
