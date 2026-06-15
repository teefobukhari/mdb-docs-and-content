<?php
require_once __DIR__ . '/_session.php';

require_once __DIR__ . '/connections/config.php';
require_once __DIR__ . '/connections/functions.php';

// redirect_if_logged_in();

$error = "";
$success = "";
$step = $_SESSION['wc_step'] ?? "mobile";
$lang = $_GET['lang'] ?? $_POST['lang'] ?? ($_SESSION['wc_lang'] ?? 'en');
$lang = in_array($lang, ['en', 'ar'], true) ? $lang : 'en';
$_SESSION['wc_lang'] = $lang;
$isArabic = ($lang === 'ar');

function wc_t(string $en, string $ar): string {
    global $isArabic;
    return $isArabic ? $ar : $en;
}

function wc_client_ip(): string {
    $keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];

    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $value = (string)$_SERVER[$key];
            $parts = explode(',', $value);
            return trim($parts[0]);
        }
    }

    return '';
}

function wc_user_snapshot_by_id(mysqli $conn, int $userId): ?array {
    if ($userId <= 0) return null;

    $stmt = $conn->prepare("
        SELECT
            id,
            source_type,
            source_id,
            prn,
            full_name,
            mobile,
            email,
            department,
            location,
            status,
            last_login_at,
            created_at
        FROM WC2026_Users
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) return null;

    $stmt->bind_param("i", $userId);
    if (!$stmt->execute()) return null;

    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;

    return $row ?: null;
}

function wc_user_snapshot_by_mobile(mysqli $conn, string $mobile): ?array {
    $mobile = trim($mobile);
    if ($mobile === '') return null;

    $stmt = $conn->prepare("
        SELECT
            id,
            source_type,
            source_id,
            prn,
            full_name,
            mobile,
            email,
            department,
            location,
            status,
            last_login_at,
            created_at
        FROM WC2026_Users
        WHERE mobile = ?
        LIMIT 1
    ");

    if (!$stmt) return null;

    $stmt->bind_param("s", $mobile);
    if (!$stmt->execute()) return null;

    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;

    return $row ?: null;
}

function wc_insert_user_audit(
    mysqli $conn,
    ?int $userId,
    string $actionType,
    ?array $oldData = null,
    ?array $newData = null,
    ?string $changedBy = null,
    ?int $changedById = null
): void {
    $allowedActions = ['INSERT', 'UPDATE', 'DELETE', 'LOGIN', 'STATUS_CHANGE'];
    if (!in_array($actionType, $allowedActions, true)) {
        $actionType = 'UPDATE';
    }

    $oldJson = $oldData !== null ? json_encode($oldData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $newJson = $newData !== null ? json_encode($newData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $ipAddress = wc_client_ip();
    $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
    $changedBy = $changedBy ?: ($_SESSION['FULL_NAME'] ?? 'System');

    $stmt = $conn->prepare("
        INSERT INTO WC2026_Users_Audit_Log
            (
                user_id,
                action_type,
                old_data,
                new_data,
                changed_by,
                changed_by_id,
                ip_address,
                user_agent,
                created_at
            )
        VALUES
            (?, ?, CAST(? AS JSON), CAST(? AS JSON), ?, ?, ?, ?, NOW())
    ");

    if (!$stmt) {
        error_log('WC2026 AUDIT PREPARE ERROR: ' . $conn->error);
        return;
    }

    $stmt->bind_param(
        "issssiss",
        $userId,
        $actionType,
        $oldJson,
        $newJson,
        $changedBy,
        $changedById,
        $ipAddress,
        $userAgent
    );

    if (!$stmt->execute()) {
        error_log('WC2026 AUDIT INSERT ERROR: ' . $stmt->error);
    }
}

function wc_update_last_login(mysqli $conn, int $userId): void {
    if ($userId <= 0) return;

    $stmt = $conn->prepare("UPDATE WC2026_Users SET last_login_at = NOW() WHERE id = ? LIMIT 1");
    if (!$stmt) return;

    $stmt->bind_param("i", $userId);
    $stmt->execute();
}


if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$_SESSION['otp_last'] = $_SESSION['otp_last'] ?? 0;

/* ===========================================================
   Single URL Mode:
   If logged in, show home.php internally while URL remains /WC2026/
   Also handle logout through POST from home.php.
=========================================================== */
if ($_SERVER["REQUEST_METHOD"] === "POST" && (($_POST['action'] ?? '') === 'logout')) {
    $postedCsrf = $_POST['csrf'] ?? '';

    if (hash_equals($_SESSION['csrf'] ?? '', $postedCsrf)) {
        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"] ?? '',
                $params["secure"] ?? false,
                $params["httponly"] ?? true
            );
        }

        session_destroy();
    }

    header("Location: /WC2026/");
    exit;
}

if (!empty($_SESSION['AUTHENTICATED'])) {
    require __DIR__ . '/home.php';
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $postedCsrf = $_POST['csrf'] ?? '';

    if (!hash_equals($_SESSION['csrf'], $postedCsrf)) {
        $error = "Invalid request. Please refresh and try again.";
        $step = "mobile";
        $_SESSION['wc_step'] = "mobile";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === "check_mobile") {
            $mobile = normalizeMobile($_POST['mobile'] ?? '');

            if (!$mobile) {
                $error = "Please enter a valid mobile number.";
                $step = "mobile";
                $_SESSION['wc_step'] = "mobile";
            } else {
                $user = findUserByMobile($conn, $mobile);

                if ($user) {
                    if (time() - (int)$_SESSION['otp_last'] < 30) {
                        $error = "Please wait a few seconds before requesting another OTP.";
                        $_SESSION['login_mobile'] = $mobile;
                        $step = "otp";
                        $_SESSION['wc_step'] = "otp";
                    } else {
                        if (sendLoginOtp($conn, $user)) {
                            $_SESSION['login_mobile'] = $mobile;
                            $_SESSION['otp_last'] = time();
                            $_SESSION['wc_step'] = "otp";

                            $success = "OTP has been sent to your mobile number.";
                            $step = "otp";
                        } else {
                            $error = "Unable to send OTP. Please try again.";
                            $step = "mobile";
                            $_SESSION['wc_step'] = "mobile";
                        }
                    }
                } else {
                    $_SESSION['guest_mobile'] = $mobile;
                    $_SESSION['wc_step'] = "register";
                    $step = "register";
                }
            }
        }

        if ($action === "register_guest") {
            $fullName = trim($_POST['full_name'] ?? '');
            $mobile = normalizeMobile($_POST['mobile'] ?? '');

            if ($fullName === '' || !$mobile) {
                $error = "Please enter your full name and valid mobile number.";
                $step = "register";
                $_SESSION['wc_step'] = "register";
            } else {
                registerGuestUser($conn, $fullName, $mobile);
                $user = findUserByMobile($conn, $mobile);

                if ($user && sendLoginOtp($conn, $user)) {
                    $_SESSION['login_mobile'] = $mobile;
                    $_SESSION['otp_last'] = time();
                    $_SESSION['wc_step'] = "otp";

                    $success = "Registration completed. OTP has been sent.";
                    $step = "otp";
                } else {
                    $error = "Unable to complete registration. Please try again.";
                    $step = "register";
                    $_SESSION['wc_step'] = "register";
                }
            }
        }

        if ($action === "verify_otp") {
            $otp = $_POST['otp'] ?? '';
            $mobile = $_SESSION['login_mobile'] ?? '';
            $normalizedMobile = normalizeMobile($mobile);
            $oldUserSnapshot = $normalizedMobile ? wc_user_snapshot_by_mobile($conn, $normalizedMobile) : null;

            $user = verifyLoginOtp($conn, $mobile, $otp);

            if ($user) {
                $userId = upsertWC2026User($conn, $user);

                if (!$userId) {
                    $error = "Unable to create your participant profile. Please try again.";
                    $step = "otp";
                    $_SESSION['wc_step'] = "otp";
                } else {
                    wc_update_last_login($conn, (int)$userId);
                    $newUserSnapshot = wc_user_snapshot_by_id($conn, (int)$userId);
                    $changedByName = $newUserSnapshot['full_name'] ?? ($user['FULL_NAME'] ?? 'Participant');

                    if ($oldUserSnapshot === null) {
                        wc_insert_user_audit(
                            $conn,
                            (int)$userId,
                            'INSERT',
                            null,
                            $newUserSnapshot,
                            $changedByName,
                            (int)$userId
                        );
                    } else {
                        $oldComparable = $oldUserSnapshot;
                        $newComparable = $newUserSnapshot ?: [];
                        unset($oldComparable['last_login_at'], $newComparable['last_login_at']);

                        if ($oldComparable != $newComparable) {
                            wc_insert_user_audit(
                                $conn,
                                (int)$userId,
                                'UPDATE',
                                $oldUserSnapshot,
                                $newUserSnapshot,
                                $changedByName,
                                (int)$userId
                            );
                        }
                    }

                    wc_insert_user_audit(
                        $conn,
                        (int)$userId,
                        'LOGIN',
                        $oldUserSnapshot,
                        $newUserSnapshot,
                        $changedByName,
                        (int)$userId
                    );

                    clearUserOtp($conn, $user);

                    session_regenerate_id(true);

                    $_SESSION['AUTHENTICATED'] = true;
                    $_SESSION['USER_ID'] = $userId;
                    $_SESSION['PRN'] = (string)($user['PRN'] ?? '');
                    $_SESSION['FULL_NAME'] = $user['FULL_NAME'] ?? 'Participant';
                    $_SESSION['MOBILE'] = normalizeMobile($user['Mobile'] ?? '');
                    $_SESSION['USER_TYPE'] = $user['user_type'] ?? 'Guest';

                    unset(
                        $_SESSION['login_mobile'],
                        $_SESSION['guest_mobile'],
                        $_SESSION['wc_step'],
                        $_SESSION['otp_last']
                    );

                    header("Location: /WC2026/");
                    exit;
                }
            } else {
                $error = "Invalid or expired OTP.";
                $step = "otp";
                $_SESSION['wc_step'] = "otp";
            }
        }

        if ($action === "back") {
            unset($_SESSION['login_mobile'], $_SESSION['guest_mobile'], $_SESSION['wc_step']);
            $step = "mobile";
            $_SESSION['wc_step'] = "mobile";
        }
    }
}

$csrf = $_SESSION['csrf'];
$mobileValue = $_POST['mobile'] ?? ($_SESSION['guest_mobile'] ?? ($_SESSION['login_mobile'] ?? ''));
$logoPath = "/WC2026/partials/CATRION%20logo.png";
$bannerPath = "WC-2026-KV.png";
$flagEnPath = "https://flagcdn.com/w40/us.png";
$flagArPath = "https://flagcdn.com/w40/sa.png";

/* Countdown target: FIFA World Cup 2026 kickoff */
$kickoff = "2026-06-11T00:00:00+03:00";
$messageMap = [
    "Invalid request. Please refresh and try again." => "طلب غير صالح. يرجى تحديث الصفحة والمحاولة مرة أخرى.",
    "Please enter a valid mobile number." => "يرجى إدخال رقم جوال صحيح.",
    "Please wait a few seconds before requesting another OTP." => "يرجى الانتظار قليلاً قبل طلب رمز تحقق جديد.",
    "OTP has been sent to your mobile number." => "تم إرسال رمز التحقق إلى رقم جوالك.",
    "Unable to send OTP. Please try again." => "تعذر إرسال رمز التحقق. يرجى المحاولة مرة أخرى.",
    "Please enter your full name and valid mobile number." => "يرجى إدخال الاسم الكامل ورقم جوال صحيح.",
    "Registration completed. OTP has been sent." => "تم إنشاء الحساب وإرسال رمز التحقق.",
    "Unable to complete registration. Please try again." => "تعذر إكمال التسجيل. يرجى المحاولة مرة أخرى.",
    "Unable to create your participant profile. Please try again." => "تعذر إنشاء ملف المشارك. يرجى المحاولة مرة أخرى.",
    "Invalid or expired OTP." => "رمز التحقق غير صحيح أو منتهي الصلاحية."
];

$displayError = $isArabic ? ($messageMap[$error] ?? $error) : $error;
$displaySuccess = $isArabic ? ($messageMap[$success] ?? $success) : $success;

?>
<!DOCTYPE html>
<html lang="<?= $isArabic ? 'ar' : 'en' ?>" dir="<?= $isArabic ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="UTF-8">
<title><?= wc_t('CATRION FIFA World Cup 2026 Challenge', 'تحدي كاتريون لكأس العالم 2026') ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link rel="icon" type="image/png" href="https://e-services.catrion.com/WC2026/partials/CATRION%20Icon.png">
<link rel="apple-touch-icon" href="https://e-services.catrion.com/WC2026/partials/CATRION%20Icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">


<style>
:root{
    --navy:#071A35;
    --deep:#0B2C55;
    --blue:#0E63E6;
    --sky:#55B7FF;
    --cyan:#A8E7FF;
    --gold:#5E93DB;
    --gold2:#5E93DB;
    --card:#FFFFFF;
    --text:#102033;
    --muted:#71839A;
    --border:#DDE9F6;
    --danger:#E94747;
    --success:#11A36A;
}

*{box-sizing:border-box}
html,body{min-height:100%}

body{
    margin:0;
    min-height:100vh;
    font-family:<?= $isArabic ? "'Tajawal','Inter',sans-serif" : "'Inter',sans-serif" ?>;
    color:var(--text);
    display:flex;
    align-items:center;
    justify-content:center;
    padding:28px;
    overflow-x:hidden;
    background:
        radial-gradient(circle at 12% 8%, rgba(85,183,255,.24), transparent 28%),
        radial-gradient(circle at 88% 0%, rgba(245,200,91,.14), transparent 24%),
        linear-gradient(135deg,#05162F 0%,#071A35 44%,#0B2C55 100%);
    background-attachment:fixed;
}

body:before{
    content:"";
    position:fixed;
    inset:0;
    pointer-events:none;
    background:
        repeating-linear-gradient(90deg,rgba(255,255,255,.035) 0 1px,transparent 1px 82px),
        repeating-linear-gradient(0deg,rgba(255,255,255,.024) 0 1px,transparent 1px 72px),
        radial-gradient(circle at 50% -8%,rgba(255,255,255,.10),transparent 38%);
}

body:after{
    content:"";
    position:fixed;
    inset:auto 10% -220px 10%;
    height:420px;
    pointer-events:none;
    background:radial-gradient(ellipse at center,rgba(85,183,255,.20),transparent 68%);
    filter:blur(18px);
}

.ambient{display:none}

.page-shell{
    position:relative;
    z-index:2;
    width:100%;
    max-width:1120px;
    min-height:640px;
    display:grid;
    grid-template-columns: 1fr 430px;
    overflow:hidden;
    border-radius:34px;
    background:rgba(255,255,255,.96);
    border:1px solid rgba(255,255,255,.46);
    box-shadow:0 36px 95px rgba(0,0,0,.38);
    animation:shellIn .55s ease both;
}

@keyframes shellIn{
    from{opacity:0;transform:translateY(18px) scale(.985)}
    to{opacity:1;transform:translateY(0) scale(1)}
}

.login-side{
    order:2;
    position:relative;
    padding:42px 48px 34px;
    background:linear-gradient(180deg,#FFFFFF 0%,#F7FAFE 100%);
    display:flex;
    flex-direction:column;
    justify-content:space-between;
}

.login-side:before{
    content:"";
    position:absolute;
    left:-120px;
    top:-120px;
    width:300px;
    height:300px;
    border-radius:50%;
    background:radial-gradient(circle,rgba(85,183,255,.18),rgba(85,183,255,.05) 48%,transparent 49%);
}

.top-line{
    position:relative;
    z-index:20;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:18px;
    margin-bottom:38px;
}

.logo-card{
    width:150px;
    height:58px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:18px;
    background:#fff;
    border:1px solid #E3EEF9;
    box-shadow:0 14px 35px rgba(7,42,85,.12);
}

.logo-card img{max-width:122px;max-height:36px;object-fit:contain}
.lang-switcher{position:relative}
.lang-toggle{
    width:auto;
    min-height:42px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    padding:10px 15px;
    border-radius:999px;
    background:#EAF4FF;
    border:1px solid #D4E7FB;
    color:var(--deep);
    font-size:12px;
    font-weight:900;
    box-shadow:0 10px 22px rgba(7,42,85,.06);
}

.lang-menu{
    position:absolute;
    top:calc(100% + 8px);
    <?= $isArabic ? 'left:0;' : 'right:0;' ?>
    min-width:165px;
    display:none;
    padding:7px;
    border-radius:16px;
    background:#fff;
    border:1px solid #E3EEF9;
    box-shadow:0 24px 48px rgba(0,0,0,.14);
    z-index:30;
}
.lang-switcher:hover .lang-menu,.lang-switcher:focus-within .lang-menu{display:block}
.lang-menu a{
    display:flex;
    align-items:center;
    gap:9px;
    padding:11px 12px;
    border-radius:12px;
    color:var(--deep);
    text-decoration:none;
    font-size:13px;
    font-weight:900;
}
.lang-menu a:hover,.lang-menu a.active{background:#EAF4FF;color:var(--blue)}

.login-card{
    position:relative;
    z-index:2;
    width:100%;
    max-width:360px;
    margin:0 auto;
}

.micro{
    color:var(--blue);
    font-size:12px;
    font-weight:900;
    letter-spacing:.45px;
    text-transform:uppercase;
    margin-bottom:12px;
}

.login-card h2{
    margin:0;
    font-size:35px;
    line-height:1.06;
    letter-spacing:-1.25px;
    color:var(--deep);
    font-weight:900;
}
html[dir="rtl"] .login-card h2{letter-spacing:0}
.login-card h2 span{color:var(--blue)}
.subtitle{
    margin:16px 0 24px;
    color:var(--muted);
    font-size:14px;
    line-height:1.7;
}

.step-indicator{display:flex;gap:8px;margin-bottom:22px}
.step-dot{height:4px;flex:1;border-radius:999px;background:#DDE9F6}
.step-dot.active{background:linear-gradient(90deg,var(--blue),var(--sky));box-shadow:0 0 14px rgba(85,183,255,.40)}

.input-group{margin-bottom:16px}
label{display:block;margin-bottom:8px;color:#33475F;font-size:12px;font-weight:900}
.input-wrap{position:relative}
.input-icon{
    position:absolute;
    left:16px;
    top:50%;
    transform:translateY(-50%);
    color:#8CA0B7;
    font-size:15px;
    z-index:2;
    pointer-events:none;
}
html[dir="rtl"] .input-icon{left:auto;right:16px}
input{
    width:100%;
    min-height:52px;
    border:1px solid var(--border);
    border-radius:15px;
    padding:13px 16px 13px 52px;
    background:#fff;
    color:#102033;
    outline:none;
    font-size:16px;
    box-shadow:0 8px 20px rgba(7,42,85,.03);
    font-family:inherit;
}
html[dir="rtl"] input{padding:13px 52px 13px 16px}
input[name="full_name"]{direction:<?= $isArabic ? 'rtl' : 'ltr' ?>}
input:focus{border-color:var(--blue);box-shadow:0 0 0 4px rgba(14,99,230,.11)}

button{
    width:100%;
    min-height:49px;
    border:0;
    border-radius:15px;
    padding:13px 16px;
    background:#5E93DB;
    color:#fff;
    font-weight:900;
    font-size:14px;
    cursor:pointer;
    box-shadow:0 16px 30px rgba(94,147,219,.28);
    transition:.22s;
    font-family:inherit;
}
button:hover{transform:translateY(-1px);background:#4D86D6;box-shadow:0 20px 34px rgba(94,147,219,.30)}
.secondary-btn{margin-top:10px;background:#EAF4FF;color:var(--deep);box-shadow:none}
.secondary-btn:hover{box-shadow:none;background:#DCEEFF}
.note{margin-top:14px;color:#8494A8;font-size:12px;line-height:1.6;text-align:center}
.message{margin-top:16px;padding:13px 15px;border-radius:16px;font-size:13px;line-height:1.55;font-weight:800}
.error{color:var(--danger);background:#FFF1F0;border:1px solid #FFD6D2}
.success{color:var(--success);background:#EFFFF7;border:1px solid #C7F3DF}
.footer{
    margin-top:28px;
    text-align:center;
    color:#91A0B2;
    font-size:11px;
    font-weight:900;
    white-space:nowrap;
}

.feature-grid{display:none}

.visual-side{
    order:1;
    position:relative;
    min-height:640px;
    padding:48px;
    color:#fff;
    display:flex;
    flex-direction:column;
    justify-content:center;
    overflow:hidden;
    background:
        linear-gradient(90deg,rgba(5,22,47,.90) 0%,rgba(7,26,53,.55) 44%,rgba(7,26,53,.12) 100%),
        linear-gradient(180deg,rgba(5,22,47,.25),rgba(5,22,47,.82)),
        url('<?= htmlspecialchars($bannerPath, ENT_QUOTES, 'UTF-8') ?>') center/cover no-repeat,
        radial-gradient(circle at 75% 18%, rgba(85,183,255,.35), transparent 28%),
        linear-gradient(135deg,#071A35 0%,#0B2C55 48%,#0E63E6 100%);
}
.visual-side:before{
    content:"";
    position:absolute;
    inset:0;
    background:
        repeating-linear-gradient(90deg,rgba(255,255,255,.035) 0 1px,transparent 1px 76px),
        radial-gradient(circle at 50% 110%,rgba(255,255,255,.14),transparent 36%);
    pointer-events:none;
}
.visual-side:after{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    background:linear-gradient(135deg,rgba(255,255,255,.08),transparent 45%);
}

.visual-top{
    position:absolute;
    z-index:3;
    top:38px;
    left:42px;
    right:42px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
}
.visual-kicker{
    display:inline-flex;
    align-items:center;
    gap:9px;
    padding:10px 14px;
    border-radius:999px;
    color:rgba(255,255,255,.92);
    background:rgba(255,255,255,.12);
    border:1px solid rgba(255,255,255,.18);
    font-size:12px;
    font-weight:900;
    box-shadow:0 14px 30px rgba(0,0,0,.16);
    -webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px);
}
.visual-kicker i{width:7px;height:7px;border-radius:50%;background:var(--gold);box-shadow:0 0 18px rgba(245,200,91,.95);animation:pulseDot 1.5s ease-in-out infinite}
@keyframes pulseDot{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.45);opacity:.75}}
.visual-logo-card{
    width:150px;
    height:58px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:18px;
    background:rgba(255,255,255,.94);
    border:1px solid rgba(255,255,255,.45);
    box-shadow:0 14px 35px rgba(0,0,0,.18);
    -webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px);
}
.visual-logo-card img{max-width:122px;max-height:36px;object-fit:contain}

.sa-pill{display:none}

.visual-main{
    position:relative;
    z-index:3;
    max-width:600px;
}
.visual-main h1{
    margin:0;
    font-size:76px;
    line-height:.93;
    letter-spacing:-3.8px;
    font-weight:900;
    text-shadow:0 22px 44px rgba(0,0,0,.36);
}
html[dir="rtl"] .visual-main h1{letter-spacing:0}
.visual-main h1 span{color:var(--cyan)}
.visual-main p,
.visual-bottom,
.trophy,
.stadium,
.spark,
.countdown{display:none !important}

@media(max-width:1050px){
    body{align-items:flex-start;padding:16px}
    .page-shell{grid-template-columns:1fr;max-width:780px;min-height:auto}
    .login-side{order:2;padding:34px 26px}
    .visual-side{order:1;min-height:430px;padding:34px 28px}
    .visual-main h1{font-size:56px;letter-spacing:-2.2px}
}
@media(max-width:560px){
    body{padding:0;background:#071A35}
    .page-shell{border-radius:0;box-shadow:none;min-height:100vh;min-height:100dvh}
    .visual-side{min-height:330px;padding:24px 20px}
    .visual-top{top:22px;left:20px;right:20px}
    .visual-kicker{font-size:10px;padding:9px 11px}
    .visual-logo-card{width:132px;height:52px}
    .visual-logo-card img{max-width:108px;max-height:32px}
    .visual-main h1{font-size:42px;letter-spacing:-1.7px}
    .login-side{padding:28px 20px 34px}
    .top-line{align-items:flex-start}
    .logo-card{width:132px;height:52px}
    .login-card h2{font-size:30px}
}
@media (prefers-reduced-motion: reduce){*,*:before,*:after{animation:none !important;transition:none !important}}


/* ================= PREMIUM LOGIN REFINEMENT ================= */
.page-shell{
    isolation:isolate;
    transform-origin:center;
}
.page-shell:before{
    content:"";
    position:absolute;
    inset:0;
    pointer-events:none;
    border-radius:34px;
    background:linear-gradient(120deg,transparent 0%,rgba(255,255,255,.38) 18%,transparent 36%);
    transform:translateX(-115%);
    animation:shellShine 7s ease-in-out infinite;
    z-index:6;
}
@keyframes shellShine{
    0%,62%{transform:translateX(-115%);opacity:0}
    72%{opacity:.55}
    100%{transform:translateX(115%);opacity:0}
}
.logo-card{
    transition:.25s ease;
}
.logo-card:hover{
    transform:translateY(-2px);
    box-shadow:0 18px 42px rgba(14,99,230,.16);
}
.flag-img{
    width:22px;
    height:22px;
    border-radius:50%;
    object-fit:cover;
    display:inline-block;
    flex:0 0 auto;
    box-shadow:0 4px 10px rgba(7,26,53,.14);
}
.lang-toggle{
    white-space:nowrap;
    transition:.22s ease;
}
.lang-toggle:hover{
    background:#fff;
    transform:translateY(-1px);
    box-shadow:0 14px 30px rgba(14,99,230,.13);
}
.lang-toggle .chevron{
    font-size:10px;
    opacity:.72;
}
.lang-menu{
    animation:menuIn .18s ease both;
}
@keyframes menuIn{
    from{opacity:0;transform:translateY(-6px) scale(.98)}
    to{opacity:1;transform:translateY(0) scale(1)}
}
.lang-menu a{
    min-height:46px;
}
.login-card{
    animation:fadeUp .62s ease both;
}
.visual-main{
    animation:fadeUp .78s ease .08s both;
}
@keyframes fadeUp{
    from{opacity:0;transform:translateY(18px)}
    to{opacity:1;transform:translateY(0)}
}
.login-card h2 span,
.visual-main h1 span{
    background:linear-gradient(135deg,var(--blue),var(--sky));
    -webkit-background-clip:text;
    background-clip:text;
    color:transparent;
}
.visual-main h1 span{
    background:none;
    color:#5E93DB;
    -webkit-text-fill-color:#5E93DB;
}
.step-dot.active{
    position:relative;
    overflow:hidden;
}
.step-dot.active:after{
    content:"";
    position:absolute;
    inset:0;
    background:linear-gradient(90deg,transparent,rgba(255,255,255,.75),transparent);
    animation:dotSlide 1.7s ease-in-out infinite;
}
@keyframes dotSlide{
    from{transform:translateX(-100%)}
    to{transform:translateX(100%)}
}
input{
    transition:.22s ease;
}
input:hover{
    border-color:#C8DCF3;
    box-shadow:0 10px 24px rgba(7,42,85,.06);
}
.main-btn{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:12px;
    position:relative;
    overflow:hidden;
}
.main-btn:before{
    content:"";
    position:absolute;
    top:0;
    bottom:0;
    width:70px;
    left:-90px;
    background:linear-gradient(90deg,transparent,rgba(255,255,255,.55),transparent);
    transform:skewX(-18deg);
    transition:.42s ease;
}
.main-btn:hover:before{
    left:calc(100% + 40px);
}
.btn-arrow{
    position:absolute;
    <?= $isArabic ? 'left:18px;' : 'right:18px;' ?>
    font-size:21px;
    line-height:1;
    transition:.22s ease;
}
html[dir="rtl"] .btn-arrow{
    transform:rotate(180deg);
}
.main-btn:hover .btn-arrow{
    <?= $isArabic ? 'transform:translateX(-3px) rotate(180deg);' : 'transform:translateX(3px);' ?>
}
.brand-bottom{
    margin-top:22px;
    padding-top:18px;
    border-top:1px solid #E5EEF8;
    position:relative;
}
.brand-bottom:before{
    content:"";
    position:absolute;
    top:-1px;
    left:50%;
    width:42px;
    height:2px;
    border-radius:999px;
    transform:translateX(-50%);
    background:linear-gradient(90deg,var(--blue),var(--sky));
}
.brand-policy-links{
    display:flex;
    align-items:center;
    justify-content:center;
    gap:12px;
    flex-wrap:wrap;
}
.brand-policy-links a{
    color:var(--blue);
    text-decoration:none;
    font-size:11px;
    font-weight:800;
    transition:.2s ease;
}
.brand-policy-links a:hover{
    color:var(--deep);
    text-decoration:underline;
}
.brand-policy-links a + a:before{
    content:"";
    display:inline-block;
    width:1px;
    height:12px;
    margin-inline-end:12px;
    vertical-align:-2px;
    background:#D7E4F3;
}
.visual-side{
    background-position:center center;
}
.kv-light{
    position:absolute;
    z-index:2;
    width:320px;
    height:320px;
    border-radius:50%;
    pointer-events:none;
    filter:blur(18px);
    opacity:.55;
    animation:lightFloat 8s ease-in-out infinite alternate;
}
.kv-light-one{
    top:8%;
    right:8%;
    background:radial-gradient(circle,rgba(85,183,255,.38),transparent 70%);
}
.kv-light-two{
    bottom:8%;
    left:8%;
    background:radial-gradient(circle,rgba(245,200,91,.20),transparent 72%);
    animation-delay:1.2s;
}
@keyframes lightFloat{
    from{transform:translate3d(0,0,0) scale(1)}
    to{transform:translate3d(22px,-18px,0) scale(1.06)}
}
.gold-piece{
    position:absolute;
    z-index:3;
    width:8px;
    height:8px;
    border-radius:2px;
    background:linear-gradient(135deg,var(--gold),var(--gold2));
    box-shadow:0 0 18px rgba(245,200,91,.55);
    opacity:.8;
    pointer-events:none;
    animation:goldDrift 7s ease-in-out infinite;
}
.gp1{top:14%;right:22%;animation-delay:.2s}
.gp2{top:44%;right:11%;width:6px;height:6px;animation-delay:1.8s}
.gp3{bottom:18%;left:18%;width:7px;height:7px;animation-delay:3.1s}
@keyframes goldDrift{
    0%,100%{transform:translateY(0) rotate(0deg);opacity:.4}
    50%{transform:translateY(-18px) rotate(35deg);opacity:1}
}
.visual-kicker{
    animation:kickerGlow 2.8s ease-in-out infinite;
}
@keyframes kickerGlow{
    0%,100%{box-shadow:0 14px 30px rgba(0,0,0,.16)}
    50%{box-shadow:0 16px 38px rgba(85,183,255,.26)}
}

@media(max-width:1050px){
    .page-shell:before{display:none}
    .login-side{padding-bottom:28px}
    .brand-policy-links{gap:9px}
}
@media(max-width:560px){
    body{align-items:stretch}
    .top-line{
        gap:12px;
        margin-bottom:26px;
    }
    .lang-toggle{
        min-height:40px;
        padding:9px 12px;
        font-size:11px;
    }
    .flag-img{width:20px;height:20px}
    .lang-menu{
        min-width:148px;
        <?= $isArabic ? 'left:0;right:auto;' : 'right:0;left:auto;' ?>
    }
    .lang-menu a{
        min-height:42px;
        font-size:12px;
    }
    .visual-side{
        background-position:center top;
    }
    .brand-policy-links{
        gap:8px 10px;
        line-height:1.8;
    }
    .brand-policy-links a{
        font-size:10.5px;
    }
    .brand-policy-links a + a:before{
        margin-inline-end:10px;
    }
}
/* ================= END PREMIUM LOGIN REFINEMENT ================= */

</style>

</head>

<body>

<div class="ambient" aria-hidden="true">
    <div class="orb one"></div>
    <div class="orb two"></div>
    <div class="float-ball">⚽</div>
    <div class="float-trophy">🏆</div>
    <div class="float-star">✨</div>
    <div class="float-confetti">🎉</div>
</div>

<div class="page-shell">

    <section class="login-side">
        <div class="top-line">
            <div class="lang-switcher">
                <button type="button" class="lang-toggle" aria-label="<?= wc_t('Change language', 'تغيير اللغة') ?>">
                    <img class="flag-img" src="<?= htmlspecialchars($isArabic ? $flagArPath : $flagEnPath, ENT_QUOTES, 'UTF-8') ?>" alt="">
                    <span><?= $isArabic ? 'العربية' : 'English' ?></span>
                    <span class="chevron">▾</span>
                </button>
                <div class="lang-menu">
                    <a class="<?= !$isArabic ? 'active' : '' ?>" href="/WC2026/?lang=en">
                        <img class="flag-img" src="<?= htmlspecialchars($flagEnPath, ENT_QUOTES, 'UTF-8') ?>" alt="">
                        <span>English</span>
                    </a>
                    <a class="<?= $isArabic ? 'active' : '' ?>" href="/WC2026/?lang=ar">
                        <img class="flag-img" src="<?= htmlspecialchars($flagArPath, ENT_QUOTES, 'UTF-8') ?>" alt="">
                        <span>العربية</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="login-card">
            <div class="micro"><?= wc_t('FIFA World Cup 2026', 'كأس العالم 2026') ?></div>

            <?php if ($step === "mobile"): ?>
                <h2>
                    <?= wc_t('Cheer with', 'شجع مع') ?><br>
                    <span><?= wc_t('CATRION', 'كاتريون') ?></span>
                </h2>
                <p class="subtitle">
                    <?= wc_t(
                        'Enter your mobile number to join the CATRION World Cup challenge and start collecting points.',
                        'أدخل رقم جوالك للانضمام إلى تحدي كاتريون لكأس العالم وابدأ بجمع النقاط.'
                    ) ?>
                </p>
            <?php endif; ?>

            <?php if ($step === "register"): ?>
                <h2><?= wc_t('Create Your', 'أنشئ') ?> <span><?= wc_t('Access.', 'حسابك.') ?></span></h2>
                <p class="subtitle">
                    <?= wc_t(
                        'This number is not registered. Complete your details and we will send your OTP code.',
                        'هذا الرقم غير مسجل. أكمل بياناتك وسنرسل لك رمز التحقق.'
                    ) ?>
                </p>
            <?php endif; ?>

            <?php if ($step === "otp"): ?>
                <h2><?= wc_t('Verify Your', 'تحقق من') ?> <span><?= wc_t('OTP.', 'الرمز.') ?></span></h2>
                <p class="subtitle">
                    <?= wc_t(
                        'Enter the verification code sent to your mobile number to continue.',
                        'أدخل رمز التحقق المرسل إلى رقم جوالك للمتابعة.'
                    ) ?>
                </p>
            <?php endif; ?>

            <div class="step-indicator">
                <div class="step-dot <?= $step === 'mobile' ? 'active' : '' ?>"></div>
                <div class="step-dot <?= $step === 'register' ? 'active' : '' ?>"></div>
                <div class="step-dot <?= $step === 'otp' ? 'active' : '' ?>"></div>
            </div>

            <?php if ($step === "mobile"): ?>
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="check_mobile">
                    <input type="hidden" name="lang" value="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="input-group">
                        <label><?= wc_t('Mobile Number', 'رقم الجوال') ?></label>
                        <div class="input-wrap">
                            <span class="input-icon">☎</span>
                            <input type="text" name="mobile" placeholder="05XXXXXXXX or +9665XXXXXXXX" required value="<?= htmlspecialchars($mobileValue, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>

                    <button type="submit" class="main-btn"><span><?= wc_t('Login', 'تسجيل الدخول') ?></span><span class="btn-arrow">→</span></button>
                </form>

                <div class="note">
                    <?= wc_t(
                        'Not registered? You can create your access after entering your mobile number.',
                        'غير مسجل؟ يمكنك إنشاء حسابك بعد إدخال رقم الجوال.'
                    ) ?>
                </div>
            <?php endif; ?>

            <?php if ($step === "register"): ?>
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="register_guest">
                    <input type="hidden" name="lang" value="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="input-group">
                        <label><?= wc_t('Full Name', 'الاسم الكامل') ?></label>
                        <div class="input-wrap">
                            <span class="input-icon">👤</span>
                            <input type="text" name="full_name" placeholder="<?= wc_t('Enter your full name', 'أدخل اسمك الكامل') ?>" required>
                        </div>
                    </div>

                    <div class="input-group">
                        <label><?= wc_t('Mobile Number', 'رقم الجوال') ?></label>
                        <div class="input-wrap">
                            <span class="input-icon">☎</span>
                            <input type="text" name="mobile" required value="<?= htmlspecialchars($mobileValue, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>

                    <button type="submit" class="main-btn"><span><?= wc_t('Register & Send OTP', 'تسجيل وإرسال رمز التحقق') ?></span><span class="btn-arrow">→</span></button>
                </form>

                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="back">
                    <input type="hidden" name="lang" value="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="secondary-btn"><?= wc_t('Back', 'رجوع') ?></button>
                </form>
            <?php endif; ?>

            <?php if ($step === "otp"): ?>
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="verify_otp">
                    <input type="hidden" name="lang" value="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="input-group">
                        <label><?= wc_t('OTP Code', 'رمز التحقق') ?></label>
                        <div class="input-wrap">
                            <span class="input-icon">#</span>
                            <input type="text" name="otp" placeholder="<?= wc_t('Enter OTP', 'أدخل رمز التحقق') ?>" inputmode="numeric" maxlength="6" required>
                        </div>
                    </div>

                    <button type="submit" class="main-btn"><span><?= wc_t('Verify & Login', 'تحقق وسجل الدخول') ?></span><span class="btn-arrow">→</span></button>
                </form>

                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="back">
                    <input type="hidden" name="lang" value="<?= htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="secondary-btn"><?= wc_t('Change Mobile Number', 'تغيير رقم الجوال') ?></button>
                </form>
            <?php endif; ?>

            <?php if ($displayError): ?>
                <div class="message error"><?= htmlspecialchars($displayError, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($displaySuccess): ?>
                <div class="message success"><?= htmlspecialchars($displaySuccess, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <div class="footer">
                <?= wc_t('Designed & Developed by CATRION IT Team • Saudi Made', 'تصميم وتطوير فريق تقنية المعلومات في كاتريون   ') ?>
            </div>

            <div class="brand-bottom">
                <div class="brand-policy-links">
                    <a href="https://www.catrion.com/terms-and-conditions" target="_blank" rel="noopener noreferrer">Terms &amp; Conditions</a>
                    <a href="https://www.catrion.com/privacy-policy" target="_blank" rel="noopener noreferrer">Privacy Policy</a>
                    <a href="https://www.catrion.com/cookie-policy" target="_blank" rel="noopener noreferrer">Cookie Policy</a>
                </div>
            </div>
        </div>
    </section>

    <section class="visual-side">
        <span class="kv-light kv-light-one"></span>
        <span class="kv-light kv-light-two"></span>
        <span class="gold-piece gp1"></span>
        <span class="gold-piece gp2"></span>
        <span class="gold-piece gp3"></span>
        <div class="visual-top">
            <div class="visual-logo-card">
                <img src="<?= htmlspecialchars($logoPath, ENT_QUOTES, 'UTF-8') ?>" alt="CATRION">
            </div>
        </div>

       <div class="visual-main">
    <h1>
        <?= wc_t('Cheer with', 'شجع مع') ?><br>
        <span><?= wc_t('CATRION', 'كاتريون') ?></span>
    </h1>
</div>
    </section>

</div>

<script>
(function(){
    const countdown = document.querySelector('.countdown');
    if (!countdown) return;

    const target = new Date(countdown.dataset.kickoff).getTime();

    const daysEl = document.getElementById('days');
    const hoursEl = document.getElementById('hours');
    const minsEl = document.getElementById('mins');
    const secsEl = document.getElementById('secs');

    function pad(n){
        return String(n).padStart(2, '0');
    }

    function tick(){
        const now = new Date().getTime();
        let diff = target - now;

        if (diff <= 0) {
            daysEl.textContent = '00';
            hoursEl.textContent = '00';
            minsEl.textContent = '00';
            secsEl.textContent = '00';
            return;
        }

        const days = Math.floor(diff / (1000 * 60 * 60 * 24));
        diff -= days * (1000 * 60 * 60 * 24);

        const hours = Math.floor(diff / (1000 * 60 * 60));
        diff -= hours * (1000 * 60 * 60);

        const mins = Math.floor(diff / (1000 * 60));
        diff -= mins * (1000 * 60);

        const secs = Math.floor(diff / 1000);

        daysEl.textContent = days;
        hoursEl.textContent = pad(hours);
        minsEl.textContent = pad(mins);
        secsEl.textContent = pad(secs);
    }

    tick();
    setInterval(tick, 1000);
})();
</script>

</body>
</html>
