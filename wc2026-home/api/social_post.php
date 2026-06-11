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

    $dir = __DIR__ . '/../uploads/fanwall';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        fw_fail('Upload directory is not available.');
    }

    $fileName = 'fw_' . $FW_USER_ID . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $fileName)) {
        fw_fail('Unable to save the uploaded image.');
    }
    $photoPath = '/WC2026/uploads/fanwall/' . $fileName;
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
