<?php
// /WC2026/api/social_post.php
// Create a Fan Wall post (text + optional photo).

require __DIR__ . '/_fanwall_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fw_fail('POST required.', 405);
fw_check_csrf($_POST['csrf'] ?? '');

$body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 1000);

$photoPath = null;
if (!empty($_FILES['photo']) && (int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $f = $_FILES['photo'];

    if (!is_uploaded_file($f['tmp_name'])) fw_fail('Invalid upload.');
    if ((int)$f['size'] > 5 * 1024 * 1024) fw_fail('Image too large (max 5MB).');

    $info = @getimagesize($f['tmp_name']);
    if ($info === false) fw_fail('Invalid image file.');

    $extByMime = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    $ext = $extByMime[$info['mime']] ?? null;
    if ($ext === null) fw_fail('Unsupported image type.');

    // Resolve a writable destination. Prefer the public document-root path so the
    // saved web URL matches; fall back to a path relative to this script.
    $webRel  = '/WC2026/uploads/fanwall';
    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');

    $candidates = [];
    if ($docRoot !== '') $candidates[] = $docRoot . $webRel;
    $candidates[] = realpath(__DIR__ . '/..') . '/uploads/fanwall';
    $candidates[] = __DIR__ . '/../uploads/fanwall';

    $dir = null;
    foreach ($candidates as $cand) {
        if ($cand === '' ) continue;
        if (!is_dir($cand)) { @mkdir($cand, 0775, true); }
        if (is_dir($cand) && is_writable($cand)) { $dir = $cand; break; }
    }
    if ($dir === null) {
        fw_fail('Upload folder is not writable. Create "' . $webRel . '" under the web root and chmod it to 775.');
    }

    $fileName = 'fw_' . $FW_USER_ID . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $dest = rtrim($dir, '/') . '/' . $fileName;

    if (!@move_uploaded_file($f['tmp_name'], $dest) && !@copy($f['tmp_name'], $dest)) {
        $err = error_get_last();
        fw_fail('Unable to save the uploaded image to ' . $dir . '. ' . ($err['message'] ?? 'Check folder permissions.'));
    }
    @chmod($dest, 0644);
    $photoPath = $webRel . '/' . $fileName;
}

if ($body === '' && $photoPath === null) fw_fail('Nothing to post.');

// Optional author location (ignored if the column/table differs).
$loc = null;
$locRows = fw_rows($conn, "SELECT location FROM WC2026_Users WHERE id = ? LIMIT 1", "i", [$FW_USER_ID]);
if ($locRows) $loc = $locRows[0]['location'] ?? null;

$stmt = $conn->prepare(
    "INSERT INTO WC2026_Fan_Wall (user_id, author_name, author_location, body, photo_path)
     VALUES (?, ?, ?, ?, ?)"
);
if (!$stmt) fw_fail('Database error.');
$stmt->bind_param("issss", $FW_USER_ID, $FW_NAME, $loc, $body, $photoPath);
if (!$stmt->execute()) fw_fail('Unable to save your post.');

$id = (int)$conn->insert_id;

fw_out(['ok' => true, 'post' => [
    'id'         => $id,
    'name'       => $FW_NAME,
    'body'       => $body,
    'photo'      => $photoPath ?: '',
    'likes'      => 0,
    'liked'      => false,
    'comments'   => [],
    'created_at' => 'just now',
]]);
