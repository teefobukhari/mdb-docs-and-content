<?php
// /WC2026/api/social_feed.php
// Returns the Fan Wall feed + a "recent" list (last 15 minutes, capped at 30)
// for the notification bar.

require __DIR__ . '/_fanwall_common.php';

$WALL_LIMIT   = 40;   // posts shown on the wall
$NOTIFY_MINS  = 15;   // notification window
$NOTIFY_LIMIT = 30;   // notification cap

$posts = fw_rows(
    $conn,
    "SELECT id, user_id, author_name, author_location, body, photo_path,
            likes_count, comments_count, created_at
     FROM WC2026_Fan_Wall
     WHERE status = 'Active'
     ORDER BY created_at DESC
     LIMIT {$WALL_LIMIT}"
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
$cutoff = time() - ($NOTIFY_MINS * 60);

foreach ($posts as $p) {
    $pid = (int)$p['id'];
    $ago = fw_time_ago($p['created_at']);

    $out[] = [
        'id'         => $pid,
        'name'       => $p['author_name'],
        'body'       => $p['body'],
        'photo'      => $p['photo_path'] ?: '',
        'likes'      => (int)$p['likes_count'],
        'liked'      => isset($likedSet[$pid]),
        'comments'   => $commentsByPost[$pid] ?? [],
        'created_at' => $ago,
    ];

    if (strtotime((string)$p['created_at']) >= $cutoff && count($recent) < $NOTIFY_LIMIT) {
        $recent[] = [
            'name'       => $p['author_name'],
            'body'       => $p['body'],
            'created_at' => $ago,
        ];
    }
}

fw_out(['ok' => true, 'posts' => $out, 'recent' => $recent]);
