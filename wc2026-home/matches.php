<?php
// /WC2026/matches.php

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
$type   = $_SESSION['USER_TYPE'] ?? 'User';

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

function matchStatusLabel(?string $short, ?string $long): string {
    $short = strtoupper((string)$short);
    return match ($short) {
        'NS' => 'Upcoming',
        'LIVE', '1H', '2H', 'HT' => 'Live',
        'FT', 'AET', 'PEN' => 'Finished',
        'PST' => 'Postponed',
        'CANC' => 'Cancelled',
        default => $long ?: 'Scheduled',
    };
}

function matchStatusClass(?string $short): string {
    $short = strtoupper((string)$short);
    return match ($short) {
        'LIVE', '1H', '2H', 'HT' => 'live',
        'FT', 'AET', 'PEN' => 'finished',
        'PST', 'CANC' => 'closed',
        default => 'upcoming',
    };
}

$view = strtolower(trim((string)($_GET['view'] ?? 'all')));
$allowedViews = ['all', 'upcoming', 'live', 'finished'];
if (!in_array($view, $allowedViews, true)) {
    $view = 'all';
}

$search = trim((string)($_GET['q'] ?? ''));

$where = [];
$types = '';
$params = [];

if ($view === 'upcoming') {
    $where[] = "is_finished = 0 AND is_live = 0 AND match_datetime >= NOW()";
} elseif ($view === 'live') {
    $where[] = "is_live = 1";
} elseif ($view === 'finished') {
    $where[] = "is_finished = 1";
}

if ($search !== '') {
    $where[] = "(home_team LIKE ? OR away_team LIKE ? OR round_name LIKE ?)";
    $like = '%' . $search . '%';
    $types .= 'sss';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$matches = wc_rows(
    $conn,
    "
    SELECT
        id,
        api_fixture_id,
        round_name,
        home_team_id,
        home_team,
        home_logo,
        away_team_id,
        away_team,
        away_logo,
        match_datetime,
        timezone,
        stadium,
        city,
        status_short,
        status_long,
        elapsed,
        home_score,
        away_score,
        is_live,
        is_finished,
        last_api_sync
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
    $whereSql
    ORDER BY
        CASE
            WHEN is_live = 1 THEN 0
            WHEN is_finished = 0 AND match_datetime >= NOW() THEN 1
            ELSE 2
        END,
        match_datetime ASC
    LIMIT 160
    ",
    $types,
    $params
);

$fixtureSubquery = "
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
";

$totalMatches    = (int)wc_scalar($conn, "SELECT COUNT(*) FROM ($fixtureSubquery) WC2026_Matches");
$liveMatches     = (int)wc_scalar($conn, "SELECT COUNT(*) FROM ($fixtureSubquery) WC2026_Matches WHERE is_live=1");
$upcomingMatches = (int)wc_scalar($conn, "SELECT COUNT(*) FROM ($fixtureSubquery) WC2026_Matches WHERE is_finished=0 AND is_live=0 AND match_datetime >= NOW()");
$finishedMatches = (int)wc_scalar($conn, "SELECT COUNT(*) FROM ($fixtureSubquery) WC2026_Matches WHERE is_finished=1");
$lastSync        = wc_scalar($conn, "SELECT MAX(last_api_sync) FROM ($fixtureSubquery) WC2026_Matches");

$myPredictionRows = wc_rows(
    $conn,
    "SELECT match_id FROM WC2026_Predictions WHERE user_id = ?",
    "i",
    [$userId]
);

$myPredictions = [];
foreach ($myPredictionRows as $predictionRow) {
    $myPredictions[(int)$predictionRow['match_id']] = true;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Matches - CATRION FIFA World Cup 2026 Challenge</title>
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
*{box-sizing:border-box}
body{
    margin:0;
    font-family:'Inter',sans-serif;
    background:
        radial-gradient(circle at 15% 8%, rgba(85,183,255,.22), transparent 28%),
        radial-gradient(circle at 85% 0%, rgba(245,200,91,.16), transparent 24%),
        linear-gradient(180deg,#EAF3FC 0%,#F7FAFE 48%,#EEF5FC 100%);
    color:var(--text);
}
.hero{
    color:#fff;
    padding:26px 38px 96px;
    position:relative;
    overflow:hidden;
    background:
        radial-gradient(circle at 75% 18%, rgba(85,183,255,.35), transparent 28%),
        radial-gradient(circle at 20% 20%, rgba(245,200,91,.18), transparent 25%),
        linear-gradient(135deg,#071A35 0%,#0B2C55 48%,#0E63E6 100%);
}
.hero:before{
    content:"";
    position:absolute;
    inset:0;
    background:
        repeating-linear-gradient(90deg,rgba(255,255,255,.04) 0 1px,transparent 1px 76px),
        radial-gradient(circle at 50% 110%,rgba(255,255,255,.18),transparent 36%);
    pointer-events:none;
}
.nav{
    position:relative;
    z-index:2;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:18px;
}
.brand{display:flex;align-items:center;gap:14px}
.logo-card{
    background:#fff;
    border-radius:18px;
    height:58px;
    width:150px;
    display:flex;
    align-items:center;
    justify-content:center;
    box-shadow:0 14px 35px rgba(0,0,0,.20);
}
.logo-card img{max-width:122px;max-height:36px}
.brand-title{font-size:18px;font-weight:900;letter-spacing:-.4px}
.brand-sub{margin-top:3px;font-size:12px;color:rgba(255,255,255,.68);font-weight:700}
.nav-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.nav-link,
.logout{
    border:0;
    color:#fff;
    text-decoration:none;
    font-weight:800;
    font-size:13px;
    padding:12px 18px;
    border-radius:999px;
    background:rgba(255,255,255,.14);
    border:1px solid rgba(255,255,255,.22);
    cursor:pointer;
}
.nav-link.active{background:#fff;color:var(--deep)}
.hero-content{
    position:relative;
    z-index:2;
    max-width:1220px;
    margin:58px auto 0;
}
.badge{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:9px 14px;
    border-radius:999px;
    background:rgba(255,255,255,.12);
    border:1px solid rgba(255,255,255,.20);
    font-size:12px;
    font-weight:900;
    margin-bottom:18px;
}
.badge i{
    width:8px;height:8px;border-radius:50%;
    background:var(--gold);
    box-shadow:0 0 18px rgba(245,200,91,.9);
}
.hero h1{
    margin:0;
    font-size:52px;
    line-height:1;
    letter-spacing:-2px;
    font-weight:900;
}
.hero h1 span{color:#A8E7FF}
.hero p{
    margin:18px 0 0;
    max-width:760px;
    color:rgba(255,255,255,.78);
    line-height:1.8;
    font-size:15px;
}
.container{
    max-width:1220px;
    margin:-62px auto 44px;
    padding:0 24px;
    position:relative;
    z-index:5;
}
.stats{
    display:grid;
    grid-template-columns:repeat(4,1fr);
    gap:16px;
    margin-bottom:20px;
}
.stat{
    background:#fff;
    border:1px solid var(--border);
    border-radius:26px;
    padding:22px;
    box-shadow:0 18px 38px rgba(7,42,85,.08);
}
.stat-label{
    font-size:12px;
    font-weight:900;
    color:var(--muted);
    text-transform:uppercase;
    letter-spacing:.35px;
}
.stat-value{
    margin-top:8px;
    font-size:31px;
    font-weight:900;
    color:var(--deep);
    letter-spacing:-1px;
}
.stat-note{
    margin-top:5px;
    color:#8A98AA;
    font-size:12px;
    font-weight:700;
}
.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:28px;
    padding:24px;
    box-shadow:0 18px 40px rgba(7,42,85,.07);
}
.toolbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:16px;
    margin-bottom:20px;
    flex-wrap:wrap;
}
.tabs{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}
.tab{
    text-decoration:none;
    padding:11px 15px;
    border-radius:999px;
    background:#EAF4FF;
    color:var(--deep);
    font-weight:900;
    font-size:13px;
}
.tab.active{
    background:linear-gradient(135deg,var(--gold),var(--gold2));
    color:#071A35;
}
.search{
    display:flex;
    gap:8px;
    align-items:center;
}
.search input{
    min-height:42px;
    width:260px;
    border:1px solid var(--border);
    border-radius:14px;
    padding:0 14px;
    font-weight:700;
    outline:none;
}
.search button{
    min-height:42px;
    border:0;
    border-radius:14px;
    background:var(--deep);
    color:#fff;
    padding:0 16px;
    font-weight:900;
    cursor:pointer;
}
.matches-grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:16px;
}
.match-card{
    border:1px solid var(--border);
    border-radius:24px;
    padding:18px;
    background:#FBFDFF;
    position:relative;
    overflow:hidden;
}
.match-card:before{
    content:"";
    position:absolute;
    inset:0;
    background:radial-gradient(circle at 100% 0%, rgba(85,183,255,.14), transparent 35%);
    pointer-events:none;
}
.match-head{
    position:relative;
    z-index:1;
    display:flex;
    justify-content:space-between;
    gap:10px;
    align-items:center;
    margin-bottom:16px;
}
.round{
    color:var(--muted);
    font-size:11px;
    font-weight:900;
    text-transform:uppercase;
    line-height:1.5;
}
.status{
    padding:7px 10px;
    border-radius:999px;
    font-size:11px;
    font-weight:900;
    white-space:nowrap;
}
.status.upcoming{background:#EAF4FF;color:var(--deep)}
.status.live{background:#EFFFF7;color:var(--green);box-shadow:0 0 0 4px rgba(17,163,106,.08)}
.status.finished{background:#F3F6FA;color:#5A6678}
.status.closed{background:#FFF1F0;color:var(--red)}
.teams{
    position:relative;
    z-index:1;
    display:grid;
    grid-template-columns:1fr auto 1fr;
    gap:12px;
    align-items:center;
    margin:18px 0;
}
.team{
    text-align:center;
    min-width:0;
}
.team-logo{
    width:48px;
    height:48px;
    border-radius:50%;
    object-fit:contain;
    background:#fff;
    border:1px solid var(--border);
    padding:7px;
    margin-bottom:8px;
}
.team-name{
    font-size:13px;
    font-weight:900;
    color:var(--deep);
    overflow:hidden;
    text-overflow:ellipsis;
}
.score{
    min-width:76px;
    min-height:46px;
    display:grid;
    place-items:center;
    border-radius:16px;
    background:linear-gradient(135deg,#071A35,#0B2C55);
    color:#fff;
    font-size:18px;
    font-weight:900;
    box-shadow:0 12px 24px rgba(7,42,85,.16);
}
.match-meta{
    position:relative;
    z-index:1;
    color:var(--muted);
    font-size:12px;
    font-weight:700;
    line-height:1.7;
    border-top:1px solid #EDF2F7;
    padding-top:13px;
}
.match-actions{
    position:relative;
    z-index:1;
    display:flex;
    gap:8px;
    margin-top:14px;
}
.predict-btn,
.details-btn{
    flex:1;
    text-align:center;
    text-decoration:none;
    border-radius:14px;
    padding:11px 12px;
    font-size:12px;
    font-weight:900;
}
.predict-btn{
    color:#071A35;
    background:linear-gradient(135deg,var(--gold),var(--gold2));
    border:0;
    cursor:pointer;
    font-family:inherit;
}
.predict-btn.predicted{
    color:#fff;
    background:linear-gradient(135deg,#11A36A,#27C27F);
    box-shadow:0 12px 22px rgba(17,163,106,.20);
}
.predict-btn.disabled{
    color:#7B8796;
    background:#EEF3F8;
    pointer-events:none;
}
.details-btn{
    color:#fff;
    background:var(--deep);
}
.empty{
    padding:24px;
    border-radius:22px;
    background:#FFF8E4;
    border:1px solid #F3DFA2;
    color:#755B14;
    line-height:1.7;
    font-size:14px;
}
@media(max-width:1050px){
    .matches-grid{grid-template-columns:repeat(2,1fr)}
    .stats{grid-template-columns:repeat(2,1fr)}
}
@media(max-width:640px){
    .hero{padding:22px 18px 100px}
    .nav{align-items:flex-start;flex-direction:column}
    .hero h1{font-size:38px}
    .stats{grid-template-columns:1fr}
    .matches-grid{grid-template-columns:1fr}
    .toolbar{align-items:stretch}
    .search{width:100%}
    .search input{width:100%;flex:1}
}

/* Predict Popup */
.predict-popup{
    position:fixed;
    inset:0;
    z-index:300;
    display:none;
    align-items:center;
    justify-content:center;
    padding:22px;
    background:rgba(7,26,53,.72);
    backdrop-filter:blur(10px);
}
.predict-popup.active{
    display:flex;
}
.predict-popup-card{
    width:100%;
    max-width:820px;
    height:min(84vh, 720px);
    background:#fff;
    border-radius:30px;
    overflow:hidden;
    box-shadow:0 35px 90px rgba(0,0,0,.35);
    position:relative;
    animation:popupIn .24s ease;
}
.predict-popup-top{
    height:62px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:14px;
    padding:0 16px 0 24px;
    background:#fff;
    color:var(--deep);
    border-bottom:1px solid #EDF2F7;
}
.predict-popup-title{
    font-size:22px;
    font-weight:900;
    letter-spacing:-.5px;
}
.predict-popup-close{
    width:40px;
    height:40px;
    border:0;
    border-radius:15px;
    background:#EEF3F8;
    color:var(--deep);
    font-size:24px;
    font-weight:900;
    cursor:pointer;
}
.predict-popup iframe{
    width:100%;
    height:calc(100% - 62px);
    border:0;
    background:#fff;
}
@keyframes popupIn{
    from{opacity:0;transform:scale(.94) translateY(12px)}
    to{opacity:1;transform:scale(1) translateY(0)}
}
@media(max-width:640px){
    .predict-popup{
        padding:10px;
        align-items:flex-end;
    }
    .predict-popup-card{
        height:92vh;
        border-radius:24px 24px 0 0;
    }
    .predict-popup-title{
        font-size:18px;
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
.hero{
    background:
        radial-gradient(circle at 72% 18%, rgba(14,99,230,.36), transparent 32%),
        radial-gradient(circle at 15% 28%, rgba(85,183,255,.16), transparent 30%),
        linear-gradient(135deg,#04142B 0%,#08254D 48%,#0E63E6 100%) !important;
}
.nav,.hero-content,.container{max-width:1680px !important}
.stat{background:linear-gradient(180deg,#FFFFFF,#F7FAFF) !important;box-shadow:0 18px 48px rgba(0,0,0,.18) !important}
.card{background:linear-gradient(135deg,#061A36,#08254D 58%,#0A3A76) !important;border:1px solid rgba(168,231,255,.18) !important;color:#fff !important;box-shadow:0 24px 60px rgba(0,0,0,.28) !important}
.card-title{color:#fff !important}
.tab{background:rgba(255,255,255,.08) !important;color:#fff !important;border:1px solid rgba(168,231,255,.18)}
.tab.active{background:linear-gradient(135deg,#F5C85B,#FFE19A) !important;color:#071A35 !important}
.search input{background:rgba(255,255,255,.06) !important;border:1px solid rgba(168,231,255,.2) !important;color:#fff !important}
.search input::placeholder{color:rgba(255,255,255,.5)}
.search button{background:linear-gradient(135deg,#F5C85B,#FFE19A) !important;color:#071A35 !important}
.match-card{background:linear-gradient(135deg,rgba(255,255,255,.07),rgba(255,255,255,.02)) !important;border:1px solid rgba(168,231,255,.16) !important}
.match-card:before{display:none}
.round{color:rgba(255,255,255,.6) !important}
.team-name{color:#fff !important}
.team-logo{background:rgba(255,255,255,.92) !important}
.match-meta{color:rgba(255,255,255,.6) !important;border-top:1px solid rgba(168,231,255,.12) !important}
.status.upcoming{background:rgba(85,183,255,.18) !important;color:#A8E7FF !important}
.status.finished{background:rgba(255,255,255,.1) !important;color:rgba(255,255,255,.7) !important}
.details-btn{background:rgba(255,255,255,.12) !important;color:#fff !important;border:1px solid rgba(168,231,255,.18)}
.empty{background:rgba(245,200,91,.12) !important;border-color:rgba(245,200,91,.3) !important;color:#FFE19A !important}
.predict-popup-card{background:#0c1830 !important}
.predict-popup-top{background:#0c1830 !important;color:#fff !important;border-bottom:1px solid rgba(168,231,255,.14) !important}
.predict-popup-close{background:rgba(255,255,255,.12) !important;color:#fff !important}
.predict-popup iframe{background:#0c1830 !important}
</style>
</head>
<body>

<header class="hero">
    <div class="nav">
        <div class="brand">
            <div class="logo-card">
                <img src="<?= h($logoPath) ?>" alt="CATRION">
            </div>
            <div>
                <div class="brand-title">FIFA World Cup 2026 Challenge</div>
                <div class="brand-sub">Real Matches Center</div>
            </div>
        </div>

        <div class="nav-actions">
            <a href="/WC2026/" class="nav-link">Home</a>
            <a href="/WC2026/matches" class="nav-link active">Matches</a>
            <form method="POST" action="/WC2026/" style="margin:0;">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="logout">Logout</button>
            </form>
        </div>
    </div>

    <div class="hero-content">
        <div class="badge"><i></i> Reading from Database</div>
        <h1>Real Matches.<br><span>Real Results.</span></h1>
        <p>
            Browse the official World Cup 2026 match list, live status, upcoming fixtures,
            and final results. Submit predictions before kickoff and climb the leaderboard.
        </p>
    </div>
</header>

<main class="container">

    <section class="stats">
        <div class="stat">
            <div class="stat-label">Total Matches</div>
            <div class="stat-value"><?= (int)$totalMatches ?></div>
            <div class="stat-note">Synced fixtures</div>
        </div>

        <div class="stat">
            <div class="stat-label">Upcoming</div>
            <div class="stat-value"><?= (int)$upcomingMatches ?></div>
            <div class="stat-note">Open for predictions</div>
        </div>

        <div class="stat">
            <div class="stat-label">Live Now</div>
            <div class="stat-value"><?= (int)$liveMatches ?></div>
            <div class="stat-note">Currently playing</div>
        </div>

        <div class="stat">
            <div class="stat-label">Finished</div>
            <div class="stat-value"><?= (int)$finishedMatches ?></div>
            <div class="stat-note">Results available</div>
        </div>
    </section>

    <section class="card">
        <div class="toolbar">
            <div class="tabs">
                <a class="tab <?= $view === 'all' ? 'active' : '' ?>" href="/WC2026/matches">All</a>
                <a class="tab <?= $view === 'upcoming' ? 'active' : '' ?>" href="/WC2026/matches?view=upcoming">Upcoming</a>
                <a class="tab <?= $view === 'live' ? 'active' : '' ?>" href="/WC2026/matches?view=live">Live</a>
                <a class="tab <?= $view === 'finished' ? 'active' : '' ?>" href="/WC2026/matches?view=finished">Finished</a>
            </div>

            <form class="search" method="GET" action="/WC2026/matches">
                <?php if ($view !== 'all'): ?>
                    <input type="hidden" name="view" value="<?= h($view) ?>">
                <?php endif; ?>
                <input type="text" name="q" placeholder="Search team or round..." value="<?= h($search) ?>">
                <button type="submit">Search</button>
            </form>
        </div>

        <?php if (empty($matches)): ?>
            <div class="empty">
                No matches found in <strong>wc_fixtures</strong> yet.
            </div>
        <?php else: ?>
            <div class="matches-grid">
                <?php foreach ($matches as $m): ?>
                    <?php
                        $statusClass = matchStatusClass($m['status_short'] ?? '');
                        $statusLabel = matchStatusLabel($m['status_short'] ?? '', $m['status_long'] ?? '');
                        $isOpen = empty($m['is_finished']) && empty($m['is_live']) && strtotime((string)$m['match_datetime']) > time();
                        $homeScore = $m['home_score'];
                        $awayScore = $m['away_score'];
                        $hasPrediction = !empty($myPredictions[(int)$m['id']]);
                        $scoreText = ($homeScore !== null && $awayScore !== null)
                            ? ((int)$homeScore . ' - ' . (int)$awayScore)
                            : 'VS';
                    ?>
                    <article class="match-card">
                        <div class="match-head">
                            <div class="round"><?= h($m['round_name'] ?: 'World Cup 2026') ?></div>
                            <div class="status <?= h($statusClass) ?>"><?= h($statusLabel) ?></div>
                        </div>

                        <div class="teams">
                            <div class="team">
                                <?php if (!empty($m['home_logo'])): ?>
                                    <img class="team-logo" src="<?= h($m['home_logo']) ?>" alt="<?= h($m['home_team']) ?>">
                                <?php else: ?>
                                    <div class="team-logo" style="display:inline-grid;place-items:center;">⚽</div>
                                <?php endif; ?>
                                <div class="team-name"><?= h($m['home_team']) ?></div>
                            </div>

                            <div class="score"><?= h($scoreText) ?></div>

                            <div class="team">
                                <?php if (!empty($m['away_logo'])): ?>
                                    <img class="team-logo" src="<?= h($m['away_logo']) ?>" alt="<?= h($m['away_team']) ?>">
                                <?php else: ?>
                                    <div class="team-logo" style="display:inline-grid;place-items:center;">⚽</div>
                                <?php endif; ?>
                                <div class="team-name"><?= h($m['away_team']) ?></div>
                            </div>
                        </div>

                        <div class="match-meta">
                            <?= h(date('D, d M Y - h:i A', strtotime((string)$m['match_datetime']))) ?><br>
                            <?= h($m['stadium'] ?: 'Stadium TBA') ?><?= !empty($m['city']) ? ' • ' . h($m['city']) : '' ?>
                            <?php if (!empty($m['last_api_sync'])): ?>
                                <br>Last sync: <?= h(date('d M Y h:i A', strtotime((string)$m['last_api_sync']))) ?>
                            <?php endif; ?>
                        </div>

                        <div class="match-actions">
                            <?php if ($isOpen): ?>
                                <button
                                    type="button"
                                    class="predict-btn <?= $hasPrediction ? 'predicted' : '' ?>"
                                    data-predict-url="/WC2026/predict?match=<?= (int)$m['id'] ?>"
                                >
                                    <?= $hasPrediction ? '✓ Update Prediction' : 'Predict Match' ?>
                                </button>
                            <?php else: ?>
                                <button type="button" class="predict-btn disabled" disabled>
                                    Prediction Closed
                                </button>
                            <?php endif; ?>
                            <a class="details-btn" href="/WC2026/matches?match=<?= (int)$m['id'] ?>">Details</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

</main>

<div class="predict-popup" id="predictPopup" aria-hidden="true">
    <div class="predict-popup-card">
        <div class="predict-popup-top">
            <div class="predict-popup-title">Match Prediction</div>
            <button type="button" class="predict-popup-close" id="predictPopupClose" aria-label="Close">×</button>
        </div>
        <iframe id="predictFrame" src="about:blank" title="Match Prediction"></iframe>
    </div>
</div>

<script>
(function(){
    const popup = document.getElementById('predictPopup');
    const frame = document.getElementById('predictFrame');
    const closeBtn = document.getElementById('predictPopupClose');

    function openPopup(url){
        url += (url.indexOf('?') >= 0 ? '&' : '?') + 'popup=1';

        frame.src = url;
        popup.classList.add('active');
        popup.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closePopup(reloadPage = true){
        popup.classList.remove('active');
        popup.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        frame.src = 'about:blank';

        if (reloadPage) {
            setTimeout(() => window.location.reload(), 180);
        }
    }

    window.closePredictPopup = closePopup;

    document.querySelectorAll('[data-predict-url]').forEach(btn => {
        btn.addEventListener('click', function(){
            openPopup(this.dataset.predictUrl);
        });
    });

    closeBtn.addEventListener('click', function(){
        closePopup(true);
    });

    popup.addEventListener('click', function(e){
        if (e.target === popup) closePopup(true);
    });

    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && popup.classList.contains('active')) {
            closePopup(true);
        }
    });

    window.addEventListener('message', function(event){
        if (event && event.data && event.data.type === 'WC2026_PREDICTION_SAVED') {
            closePopup(true);
        }
    });
})();
</script>

</body>
</html>
