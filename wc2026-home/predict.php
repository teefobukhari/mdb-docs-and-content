<?php
// /WC2026/predict.php

if (session_status() === PHP_SESSION_NONE) {
    session_name('WC2026SESSID');
    session_start();
}

require_once __DIR__ . '/connections/config.php';
require_once __DIR__ . '/connections/functions.php';

require_login();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf'];

$userId = (int)($_SESSION['USER_ID'] ?? 0);
$name   = $_SESSION['FULL_NAME'] ?? 'Participant';

$logoPath = "/WC2026/partials/CATRION%20logo.png";
$iconPath = "/WC2026/partials/CATRION%20Icon.png";

function wc_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types && $params) $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) return [];
    $res = $stmt->get_result();
    return $res ? ($res->fetch_all(MYSQLI_ASSOC) ?: []) : [];
}

function wc_scalar(mysqli $conn, string $sql, string $types = '', array $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0;
    if ($types && $params) $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) return 0;
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_row() : null;
    return $row[0] ?? 0;
}

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function winnerFromScores(int $home, int $away): string {
    if ($home > $away) return 'Home';
    if ($away > $home) return 'Away';
    return 'Draw';
}

/* Map a national-team name to an ISO 3166-1 alpha-2 code for flagcdn. */
function wc_country_code(?string $team): string {
    $name = strtolower(trim((string)$team));
    if ($name === '' || $name === 'tba') return '';
    $name = strtr($name, ['á'=>'a','à'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','õ'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n','š'=>'s','ž'=>'z','ć'=>'c','đ'=>'d']);
    $name = str_replace('&', 'and', $name);
    $name = preg_replace('/[^a-z]/', '', $name);
    static $map = [
        'usa'=>'us','unitedstates'=>'us','canada'=>'ca','mexico'=>'mx',
        'argentina'=>'ar','brazil'=>'br','brasil'=>'br','uruguay'=>'uy','colombia'=>'co','chile'=>'cl','peru'=>'pe','paraguay'=>'py','ecuador'=>'ec','venezuela'=>'ve','bolivia'=>'bo',
        'france'=>'fr','spain'=>'es','germany'=>'de','portugal'=>'pt','england'=>'gb-eng','scotland'=>'gb-sct','wales'=>'gb-wls','netherlands'=>'nl','belgium'=>'be','italy'=>'it','croatia'=>'hr','switzerland'=>'ch','denmark'=>'dk','sweden'=>'se','norway'=>'no','poland'=>'pl','austria'=>'at','serbia'=>'rs','ukraine'=>'ua','czechia'=>'cz','czechrepublic'=>'cz','turkey'=>'tr','turkiye'=>'tr','greece'=>'gr','hungary'=>'hu','romania'=>'ro','slovenia'=>'si','slovakia'=>'sk','iceland'=>'is','republicofireland'=>'ie','ireland'=>'ie','albania'=>'al','bosniaandherzegovina'=>'ba','bosnia'=>'ba','northmacedonia'=>'mk','georgia'=>'ge',
        'japan'=>'jp','southkorea'=>'kr','korearepublic'=>'kr','australia'=>'au','saudiarabia'=>'sa','qatar'=>'qa','iran'=>'ir','iraq'=>'iq','uae'=>'ae','jordan'=>'jo','oman'=>'om','uzbekistan'=>'uz','china'=>'cn','india'=>'in','indonesia'=>'id','palestine'=>'ps',
        'morocco'=>'ma','senegal'=>'sn','ghana'=>'gh','nigeria'=>'ng','cameroon'=>'cm','egypt'=>'eg','algeria'=>'dz','tunisia'=>'tn','ivorycoast'=>'ci','cotedivoire'=>'ci','southafrica'=>'za','mali'=>'ml','capeverde'=>'cv','caboverde'=>'cv','guinea'=>'gn','drcongo'=>'cd','congodr'=>'cd',
        'costarica'=>'cr','panama'=>'pa','jamaica'=>'jm','honduras'=>'hn','curacao'=>'cw','suriname'=>'sr','haiti'=>'ht','elsalvador'=>'sv','guatemala'=>'gt','trinidadandtobago'=>'tt',
        'newzealand'=>'nz','fiji'=>'fj','papuanewguinea'=>'pg',
    ];
    return $map[$name] ?? '';
}
function wc_flag_emoji(string $code): string {
    $code = strtoupper(trim($code));
    if (strlen($code) !== 2 || !ctype_alpha($code)) return '';
    return mb_chr(0x1F1E6 + (ord($code[0]) - 65), 'UTF-8') . mb_chr(0x1F1E6 + (ord($code[1]) - 65), 'UTF-8');
}

$matchId = (int)($_GET['match'] ?? $_POST['match_id'] ?? 0);
$error = '';
$success = '';
$isPopup = (($_GET['popup'] ?? '') === '1' || ($_POST['popup'] ?? '') === '1');

if ($matchId <= 0) {
    header('Location: /WC2026/matches');
    exit;
}

$matchRows = wc_rows(
    $conn,
    "
    SELECT
        id,
        home_team,
        home_logo,
        away_team,
        away_logo,
        match_datetime,
        stadium,
        city,
        status_short,
        status_long,
        is_live,
        is_finished
    FROM (
        SELECT
            fixture_id AS id,
            fixture_id AS api_fixture_id,
            `round` AS round_name,
            home_id AS home_team_id,
            COALESCE(home_name, 'TBA') AS home_team,
            NULL AS home_logo,
            away_id AS away_team_id,
            COALESCE(away_name, 'TBA') AS away_team,
            NULL AS away_logo,
            DATE_ADD(kickoff_utc, INTERVAL 3 HOUR) AS match_datetime,
            'Asia/Riyadh' AS timezone,
            venue_name AS stadium,
            venue_city AS city,
            status_short,
            status_long,
            elapsed,
            COALESCE(goals_home, ft_home) AS home_score,
            COALESCE(goals_away, ft_away) AS away_score,
            ht_home AS home_halftime_score,
            ht_away AS away_halftime_score,
            NULL AS winner_team_id,
            CASE WHEN status_short IN ('LIVE','1H','HT','2H','ET','BT','P','SUSP','INT') THEN 1 ELSE 0 END AS is_live,
            CASE WHEN status_short IN ('FT','AET','PEN') THEN 1 ELSE 0 END AS is_finished,
            updated_at AS last_api_sync
        FROM wc_fixtures
    ) WC2026_Matches
    WHERE id = ?
    LIMIT 1
    ",
    "i",
    [$matchId]
);

if (!$matchRows) {
    header('Location: /WC2026/matches');
    exit;
}

$match = $matchRows[0];
$matchStarted = strtotime((string)$match['match_datetime']) <= time();
$isClosed = !empty($match['is_live']) || !empty($match['is_finished']) || $matchStarted;

$existingRows = wc_rows(
    $conn,
    "
    SELECT *
    FROM WC2026_Predictions
    WHERE user_id = ? AND match_id = ?
    LIMIT 1
    ",
    "ii",
    [$userId, $matchId]
);

$existing = $existingRows[0] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $error = 'Invalid request. Please refresh and try again.';
    } elseif ($isClosed) {
        $error = 'Prediction is closed for this match.';
    } else {
        $homeScore = max(0, min(30, (int)($_POST['predicted_home_score'] ?? 0)));
        $awayScore = max(0, min(30, (int)($_POST['predicted_away_score'] ?? 0)));
        $winner = winnerFromScores($homeScore, $awayScore);

        if ($existing) {
            $stmt = $conn->prepare("
                UPDATE WC2026_Predictions
                SET
                    predicted_home_score = ?,
                    predicted_away_score = ?,
                    predicted_winner = ?,
                    updated_at = NOW()
                WHERE user_id = ?
                  AND match_id = ?
                  AND points_calculated = 0
            ");
            $stmt->bind_param("iisii", $homeScore, $awayScore, $winner, $userId, $matchId);
            if ($stmt->execute()) {
                $success = 'Prediction updated successfully.';
                $existing['predicted_home_score'] = $homeScore;
                $existing['predicted_away_score'] = $awayScore;
                $existing['predicted_winner'] = $winner;
            } else {
                $error = 'Unable to update prediction.';
            }
        } else {
            $stmt = $conn->prepare("
                INSERT INTO WC2026_Predictions
                    (user_id, match_id, predicted_home_score, predicted_away_score, predicted_winner)
                VALUES
                    (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iiiis", $userId, $matchId, $homeScore, $awayScore, $winner);
            if ($stmt->execute()) {
                $success = 'Prediction submitted successfully.';
                $existing = [
                    'predicted_home_score' => $homeScore,
                    'predicted_away_score' => $awayScore,
                    'predicted_winner' => $winner,
                    'points_awarded' => 0,
                    'points_calculated' => 0
                ];
            } else {
                $error = 'Unable to submit prediction.';
            }
        }
    }
}

$savedNow = ($_SERVER['REQUEST_METHOD'] === 'POST' && $success !== '' && $isPopup);

$predHome = $existing ? (int)$existing['predicted_home_score'] : 0;
$predAway = $existing ? (int)$existing['predicted_away_score'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Predict Match - CATRION FIFA World Cup 2026 Challenge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link rel="icon" type="image/png" href="<?= h($iconPath) ?>">
<link rel="apple-touch-icon" href="<?= h($iconPath) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

<style>
:root{
    --navy:#071A35;
    --deep:#0B2C55;
    --blue:#0E63E6;
    --sky:#55B7FF;
    --cyan:#A8E7FF;
    --gold:#F5C85B;
    --gold2:#FFE19A;
    --green:#11A36A;
    --red:#E94747;
    --bg:#EEF5FC;
    --card:#FFFFFF;
    --text:#102033;
    --muted:#71839A;
    --border:#DDE9F6;
}

*{
    box-sizing:border-box;
}

body{
    margin:0;
    font-family:'Inter',sans-serif;
    background:
        radial-gradient(circle at 15% 8%, rgba(85,183,255,.18), transparent 30%),
        radial-gradient(circle at 85% 0%, rgba(245,200,91,.13), transparent 24%),
        linear-gradient(180deg,#EEF5FC 0%,#F8FBFF 100%);
    color:var(--text);
}

body.popup-mode{
    background:#ffffff;
    overflow:hidden;
}

.container{
    width:100%;
    max-width:880px;
    margin:0 auto;
    padding:24px;
}

body.popup-mode .container{
    max-width:100%;
    margin:0;
    padding:22px;
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:30px;
    padding:26px;
    box-shadow:0 18px 40px rgba(7,42,85,.07);
}

body.popup-mode .card{
    border:0;
    box-shadow:none;
    border-radius:0;
    padding:0;
}

.popup-title{
    display:none;
    margin:0 0 18px;
    color:var(--deep);
    font-size:28px;
    font-weight:900;
    letter-spacing:-.8px;
}

body.popup-mode .popup-title{
    display:block;
}

.match-box{
    border-radius:28px;
    padding:26px;
    color:#fff;
    position:relative;
    overflow:hidden;
    background:
        radial-gradient(circle at 100% 0%, rgba(245,200,91,.24), transparent 30%),
        radial-gradient(circle at 10% 90%, rgba(85,183,255,.22), transparent 34%),
        linear-gradient(135deg,#071A35,#0B2C55);
}

.match-box:before{
    content:"";
    position:absolute;
    inset:0;
    background:
        repeating-linear-gradient(90deg,rgba(255,255,255,.045) 0 1px,transparent 1px 48px),
        linear-gradient(135deg,rgba(255,255,255,.05),transparent 45%);
    pointer-events:none;
}

.match-date{
    position:relative;
    z-index:2;
    color:rgba(255,255,255,.72);
    font-size:13px;
    font-weight:900;
    margin-bottom:22px;
}

.teams{
    position:relative;
    z-index:2;
    display:grid;
    grid-template-columns:1fr auto 1fr;
    gap:18px;
    align-items:center;
}

.team{
    text-align:center;
    min-width:0;
}

.team-logo{
    width:72px;
    height:72px;
    object-fit:contain;
    border-radius:50%;
    background:#fff;
    padding:10px;
    margin:0 auto 11px;
    box-shadow:0 16px 30px rgba(0,0,0,.22);
}

.team-name{
    font-size:18px;
    font-weight:900;
    overflow:hidden;
    text-overflow:ellipsis;
}

.vs{
    width:62px;
    height:62px;
    display:grid;
    place-items:center;
    border-radius:20px;
    color:#071A35;
    background:linear-gradient(135deg,var(--gold),var(--gold2));
    font-weight:900;
    box-shadow:0 16px 30px rgba(0,0,0,.18);
}

.form-title{
    margin:24px 0 14px;
    color:var(--deep);
    font-size:25px;
    font-weight:900;
    letter-spacing:-.6px;
}

.score-form{
    display:grid;
    grid-template-columns:1fr auto 1fr;
    gap:16px;
    align-items:center;
}

.score-input{
    text-align:center;
}

.score-input label{
    display:block;
    color:var(--muted);
    font-size:12px;
    font-weight:900;
    text-transform:uppercase;
    margin-bottom:8px;
}

.score-input input{
    width:100%;
    min-height:74px;
    border:1px solid var(--border);
    border-radius:22px;
    text-align:center;
    font-size:36px;
    font-weight:900;
    color:var(--deep);
    outline:none;
    background:#F7FAFE;
    box-shadow:inset 0 -10px 18px rgba(7,42,85,.025);
}

.score-input input:focus{
    border-color:var(--sky);
    box-shadow:0 0 0 4px rgba(85,183,255,.15);
}

.score-sep{
    color:var(--muted);
    font-weight:900;
    font-size:22px;
    padding-top:25px;
}

.actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:22px;
}

.btn{
    border:0;
    text-decoration:none;
    min-height:48px;
    border-radius:16px;
    padding:13px 18px;
    font-size:14px;
    font-weight:900;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    font-family:'Inter',sans-serif;
}

.btn-primary{
    background:linear-gradient(135deg,var(--gold),var(--gold2));
    color:#071A35;
    box-shadow:0 14px 26px rgba(245,200,91,.28);
}

.btn-soft{
    background:#EAF4FF;
    color:var(--deep);
}

body.popup-mode .btn-soft{
    display:none;
}

.notice{
    padding:15px 16px;
    border-radius:18px;
    font-weight:900;
    line-height:1.6;
    margin-top:16px;
}

.notice.ok{
    background:#EFFFF7;
    color:var(--green);
    border:1px solid rgba(17,163,106,.18);
}

body.popup-mode .notice.ok{
    display:none;
}

.notice.bad{
    background:#FFF1F0;
    color:var(--red);
    border:1px solid rgba(233,71,71,.18);
}

.notice.warn{
    background:#FFF8E4;
    color:#755B14;
    border:1px solid #F3DFA2;
}

.prediction-summary{
    margin-top:18px;
    padding:18px;
    border-radius:22px;
    background:#F7FAFE;
    border:1px solid var(--border);
    color:var(--deep);
    font-weight:800;
    line-height:1.8;
}

.prediction-summary strong{
    font-weight:900;
}

@media(max-width:640px){
    .container,
    body.popup-mode .container{
        padding:16px;
    }

    .popup-title{
        font-size:23px;
        margin-bottom:14px;
    }

    .match-box{
        padding:18px;
        border-radius:22px;
    }

    .match-date{
        font-size:12px;
        margin-bottom:16px;
    }

    .teams{
        grid-template-columns:1fr auto 1fr;
        gap:8px;
    }

    .team-logo{
        width:52px;
        height:52px;
        padding:8px;
    }

    .team-name{
        font-size:13px;
        line-height:1.25;
    }

    .vs{
        width:46px;
        height:46px;
        border-radius:15px;
        font-size:13px;
    }

    .prediction-summary{
        margin-top:14px;
        padding:14px;
        border-radius:18px;
        font-size:13px;
    }

    .form-title{
        font-size:21px;
        margin:18px 0 12px;
    }

    .score-form{
        grid-template-columns:1fr auto 1fr;
        gap:10px;
    }

    .score-input label{
        font-size:10px;
    }

    .score-input input{
        min-height:56px;
        border-radius:18px;
        font-size:28px;
    }

    .score-sep{
        padding-top:20px;
        font-size:18px;
    }

    .btn-primary{
        width:100%;
    }
}
/* ===== Home-style dark theme (match home.php look & feel) ===== */
html{background:#04142B}
body{
    background:
        radial-gradient(circle at 8% 8%, rgba(85,183,255,.16), transparent 30%),
        radial-gradient(circle at 92% 8%, rgba(14,99,230,.28), transparent 34%),
        linear-gradient(180deg,#04142B 0%,#071F42 45%,#04142B 100%) !important;
    color:#fff !important;
}
body.popup-mode{background:#0c1830 !important;overflow-y:auto !important}
.match-social{padding-bottom:30px}
.card{background:linear-gradient(135deg,#061A36,#08254D 58%,#0A3A76) !important;border:1px solid rgba(168,231,255,.18) !important;color:#fff !important;box-shadow:0 24px 60px rgba(0,0,0,.28) !important}
body.popup-mode .card{background:transparent !important}
.popup-title,.form-title{color:#fff !important}
.score-input label{color:rgba(255,255,255,.7) !important}
.score-input input{background:rgba(255,255,255,.06) !important;border:1px solid rgba(168,231,255,.2) !important;color:#fff !important}
.score-input input:focus{border-color:#55B7FF !important;box-shadow:0 0 0 4px rgba(85,183,255,.18) !important}
.score-sep{color:rgba(255,255,255,.6) !important}
.prediction-summary{background:rgba(255,255,255,.06) !important;border:1px solid rgba(168,231,255,.18) !important;color:#fff !important}
.prediction-summary strong{color:#FFE19A}
.btn-soft{background:rgba(255,255,255,.12) !important;color:#fff !important}
.notice.ok{background:rgba(17,163,106,.18) !important;color:#7EF4AE !important;border-color:rgba(17,163,106,.3) !important}
.notice.warn{background:rgba(245,200,91,.14) !important;color:#FFE19A !important;border-color:rgba(245,200,91,.3) !important}
.notice.bad{background:rgba(233,71,71,.16) !important;color:#FFB4B4 !important;border-color:rgba(233,71,71,.3) !important}
.match-box{border:1px solid rgba(168,231,255,.18)}

/* (4) Tighter fit for the prediction pop-up on mobile */
@media(max-width:640px){
    body.popup-mode .container{padding:14px 12px 22px !important}
    .match-box{padding:14px !important;border-radius:18px !important}
    .team-logo{width:46px !important;height:46px !important}
    .team-name{font-size:12px !important}
    .vs{width:40px !important;height:40px !important;font-size:12px !important}
    .form-title{font-size:18px !important;margin:16px 0 10px !important}
    .score-input input{min-height:54px !important;font-size:26px !important;border-radius:16px !important}
    .score-input label{font-size:10px !important}
    .score-sep{padding-top:18px !important}
    .btn{min-height:46px;font-size:14px}
    .prediction-summary{font-size:12px !important;padding:13px !important}
    .ms-reacts{gap:8px !important}
    .ms-react{padding:8px 11px !important;font-size:14px !important}
    .ms-form input{font-size:14px !important}
    .ms-list{max-height:220px !important}
    .match-social{margin-top:16px !important}
}
</style>
</head>

<body class="<?= $isPopup ? 'popup-mode' : '' ?>">

<main class="container">
    <section class="card">



        <div class="match-box">
            <div class="match-date">
                <?= h(date('D, d M Y - h:i A', strtotime((string)$match['match_datetime']))) ?>
                <?= !empty($match['stadium']) ? ' • ' . h($match['stadium']) : '' ?>
                <?= !empty($match['city']) ? ' • ' . h($match['city']) : '' ?>
            </div>

            <?php
                $homeCode = wc_country_code($match['home_team'] ?? '');
                $awayCode = wc_country_code($match['away_team'] ?? '');
                $homeEmoji = wc_flag_emoji($homeCode);
                $awayEmoji = wc_flag_emoji($awayCode);
            ?>
            <div class="teams">
                <div class="team">
                    <?php if (!empty($match['home_logo'])): ?>
                        <img class="team-logo" src="<?= h($match['home_logo']) ?>" alt="<?= h($match['home_team']) ?>">
                    <?php elseif ($homeCode !== ''): ?>
                        <img class="team-logo" style="object-fit:cover;padding:0" src="https://flagcdn.com/w160/<?= h(strtolower($homeCode)) ?>.png" alt="<?= h($match['home_team']) ?> flag" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-grid';">
                        <div class="team-logo" style="display:none;place-items:center;color:#071A35;"><?= $homeEmoji !== '' ? $homeEmoji : '⚽' ?></div>
                    <?php else: ?>
                        <div class="team-logo" style="display:inline-grid;place-items:center;color:#071A35;">⚽</div>
                    <?php endif; ?>
                    <div class="team-name"><?= h($match['home_team']) ?></div>
                </div>

                <div class="vs">VS</div>

                <div class="team">
                    <?php if (!empty($match['away_logo'])): ?>
                        <img class="team-logo" src="<?= h($match['away_logo']) ?>" alt="<?= h($match['away_team']) ?>">
                    <?php elseif ($awayCode !== ''): ?>
                        <img class="team-logo" style="object-fit:cover;padding:0" src="https://flagcdn.com/w160/<?= h(strtolower($awayCode)) ?>.png" alt="<?= h($match['away_team']) ?> flag" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-grid';">
                        <div class="team-logo" style="display:none;place-items:center;color:#071A35;"><?= $awayEmoji !== '' ? $awayEmoji : '⚽' ?></div>
                    <?php else: ?>
                        <div class="team-logo" style="display:inline-grid;place-items:center;color:#071A35;">⚽</div>
                    <?php endif; ?>
                    <div class="team-name"><?= h($match['away_team']) ?></div>
                </div>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="notice ok"><?= h($success) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="notice bad"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($existing): ?>
            <div class="prediction-summary">
                <strong>Your current prediction:</strong>
                <?= h($match['home_team']) ?> <?= (int)$existing['predicted_home_score'] ?>
                -
                <?= (int)$existing['predicted_away_score'] ?> <?= h($match['away_team']) ?><br>
                <strong>Predicted Winner:</strong> <?= h($existing['predicted_winner']) ?><br>
                <strong>Points Awarded:</strong> <?= (int)($existing['points_awarded'] ?? 0) ?>
            </div>
        <?php endif; ?>

        <?php if ($isClosed): ?>
            <div class="notice warn">
                Prediction is closed for this match because the match has already started or finished.
            </div>
            <div class="actions">

            </div>
        <?php else: ?>
            <h2 class="form-title"><?= $existing ? 'Update Your Prediction' : 'Submit Your Prediction' ?></h2>

            <form method="POST" action="/WC2026/predict?match=<?= (int)$matchId ?><?= $isPopup ? '&popup=1' : '' ?>">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="match_id" value="<?= (int)$matchId ?>">
                <?php if ($isPopup): ?><input type="hidden" name="popup" value="1"><?php endif; ?>

                <div class="score-form">
                    <div class="score-input">
                        <label><?= h($match['home_team']) ?></label>
                        <input type="number" name="predicted_home_score" min="0" max="30" value="<?= (int)$predHome ?>" required>
                    </div>

                    <div class="score-sep">-</div>

                    <div class="score-input">
                        <label><?= h($match['away_team']) ?></label>
                        <input type="number" name="predicted_away_score" min="0" max="30" value="<?= (int)$predAway ?>" required>
                    </div>
                </div>

                <div class="actions">
                    <button type="submit" class="btn btn-primary"><?= $existing ? 'Update Prediction' : 'Submit Prediction' ?></button>

                </div>
            </form>
        <?php endif; ?>

        <!-- Per-match fan reactions & comments -->
        <section class="match-social" id="matchSocial" data-match="<?= (int)$matchId ?>">
            <div class="ms-title">Fan reactions</div>
            <div class="ms-reacts" id="msReacts">
                <button type="button" class="ms-react" data-reaction="like">👍 <span class="ms-rc" data-for="like">0</span></button>
                <button type="button" class="ms-react" data-reaction="fire">🔥 <span class="ms-rc" data-for="fire">0</span></button>
                <button type="button" class="ms-react" data-reaction="goal">⚽ <span class="ms-rc" data-for="goal">0</span></button>
                <button type="button" class="ms-react" data-reaction="heart">❤️ <span class="ms-rc" data-for="heart">0</span></button>
            </div>

            <div class="ms-title">Comments</div>
            <form class="ms-form" id="msForm">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="match_id" value="<?= (int)$matchId ?>">
                <input type="text" id="msInput" name="body" maxlength="500" placeholder="Write a comment…" autocomplete="off">
                <button type="submit">Send</button>
            </form>
            <div class="ms-error" id="msError"></div>
            <div class="ms-list" id="msList"><div class="ms-empty">Loading comments…</div></div>
        </section>
    </section>

    <style>
    .match-social{margin-top:22px;padding-top:18px;border-top:1px solid rgba(168,231,255,.16)}
    .ms-title{color:#A8E7FF;font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.4px;margin:0 0 10px}
    .ms-reacts{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px}
    .ms-react{display:inline-flex;align-items:center;gap:7px;border:1px solid rgba(168,231,255,.22);background:rgba(255,255,255,.06);color:#fff;font:inherit;font-weight:900;font-size:15px;padding:9px 14px;border-radius:999px;cursor:pointer;transition:.18s ease}
    .ms-react:hover{background:rgba(255,255,255,.12)}
    .ms-react.on{background:linear-gradient(135deg,#F5C85B,#FFE19A);color:#06202e;border-color:#F5C85B}
    .ms-rc{font-size:13px}
    .ms-form{display:flex;gap:8px;margin-bottom:12px}
    .ms-form input{flex:1;min-height:42px;border-radius:12px;border:1px solid rgba(168,231,255,.22);background:rgba(255,255,255,.06);color:#fff;font:inherit;font-size:14px;padding:0 13px}
    .ms-form input::placeholder{color:rgba(255,255,255,.5)}
    .ms-form button{border:0;border-radius:12px;padding:0 18px;background:linear-gradient(135deg,#F5C85B,#FFE19A);color:#071A35;font-weight:900;cursor:pointer}
    .ms-error{display:none;margin-bottom:10px;color:#FFB4B4;font-size:13px;font-weight:800}
    .ms-error.show{display:block}
    .ms-list{display:flex;flex-direction:column;gap:10px;max-height:300px;overflow:auto}
    .ms-comment{background:rgba(255,255,255,.05);border:1px solid rgba(168,231,255,.14);border-radius:12px;padding:10px 12px}
    .ms-comment b{display:block;color:#fff;font-size:13px;font-weight:900}
    .ms-comment span{color:rgba(255,255,255,.82);font-size:13px;line-height:1.5}
    .ms-comment small{color:rgba(255,255,255,.45);font-size:11px;font-weight:700}
    .ms-empty{color:rgba(255,255,255,.55);font-weight:700;font-size:13px;padding:8px 0}
    body:not(.popup-mode) .ms-form input,body:not(.popup-mode) .ms-react{}
    </style>

    <script>
    (function(){
        var root=document.getElementById('matchSocial'); if(!root) return;
        var mid=root.dataset.match, csrf="<?= h($csrf) ?>", myName="<?= h($name) ?>";
        var list=document.getElementById('msList'), form=document.getElementById('msForm'),
            input=document.getElementById('msInput'), err=document.getElementById('msError'),
            reactsWrap=document.getElementById('msReacts');
        function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[c];});}
        function showErr(m){ if(err){ err.textContent=m; err.classList.add('show'); } }
        function clrErr(){ if(err){ err.textContent=''; err.classList.remove('show'); } }
        function renderComments(arr){
            if(!arr || !arr.length){ list.innerHTML='<div class="ms-empty">Be the first to comment.</div>'; return; }
            list.innerHTML=''; arr.forEach(function(c){ var el=document.createElement('div'); el.className='ms-comment';
                el.innerHTML='<b>'+esc(c.name)+' <small>'+esc(c.created_at||'')+'</small></b><span>'+esc(c.body)+'</span>'; list.appendChild(el); });
        }
        function renderReacts(r, my){
            ['like','fire','goal','heart'].forEach(function(k){ var s=root.querySelector('.ms-rc[data-for="'+k+'"]'); if(s) s.textContent=(r&&r[k])||0; });
            root.querySelectorAll('.ms-react').forEach(function(b){ b.classList.toggle('on', b.dataset.reaction===my); });
        }
        function load(){
            fetch('/WC2026/api/match_feed.php?match='+encodeURIComponent(mid),{credentials:'same-origin'})
                .then(function(r){return r.json();})
                .then(function(d){ if(d&&d.ok){ renderComments(d.comments); renderReacts(d.reactions, d.my); } else { renderComments([]); } })
                .catch(function(){ list.innerHTML='<div class="ms-empty">Comments unavailable (run sql/wc2026_match_social.sql).</div>'; });
        }
        reactsWrap.querySelectorAll('.ms-react').forEach(function(b){
            b.addEventListener('click', function(){
                fetch('/WC2026/api/match_react.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',
                    body:JSON.stringify({csrf:csrf,match_id:mid,reaction:b.dataset.reaction})})
                    .then(function(r){return r.json();}).then(function(d){ if(d&&d.ok) renderReacts(d.reactions,d.my); }).catch(function(){});
            });
        });
        if(form) form.addEventListener('submit', function(e){ e.preventDefault(); clrErr();
            var v=(input.value||'').trim(); if(!v) return;
            var fd=new FormData(form);
            fetch('/WC2026/api/match_comment.php',{method:'POST',body:fd,credentials:'same-origin'})
                .then(function(r){return r.text();}).then(function(t){ var d; try{d=JSON.parse(t);}catch(e){ showErr('Could not post (endpoint missing or tables not created).'); return; }
                    if(d&&d.ok&&d.comment){ if(list.querySelector('.ms-empty')) list.innerHTML=''; var el=document.createElement('div'); el.className='ms-comment';
                        el.innerHTML='<b>'+esc(d.comment.name)+' <small>'+esc(d.comment.created_at||'')+'</small></b><span>'+esc(d.comment.body)+'</span>'; list.insertBefore(el,list.firstChild); input.value=''; }
                    else { showErr((d&&d.message)?d.message:'Could not post your comment.'); } })
                .catch(function(){ showErr('Could not reach the server.'); });
        });
        load();
    })();
    </script>
</main>

<?php if ($savedNow): ?>
<script>
(function(){

    try {

        if(window.parent){

            window.parent.postMessage({
                type:'WC2026_PREDICTION_SAVED',
                matchId: <?= (int)$matchId ?>
            }, '*');

        }

    } catch(e){}

})();
</script>
<?php exit; endif; ?>

</body>
</html>
