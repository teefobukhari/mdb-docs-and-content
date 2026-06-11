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

/* =====================================================================
 * [WC2026 UI ENHANCEMENT] Country flag helpers (visual layer only).
 * These DO NOT touch data fetching or saving — they only map a team
 * name to a flag so the cards look like a real World Cup bracket.
 * ===================================================================== */

/**
 * Maps a national-team name to an ISO 3166-1 alpha-2 code (used to build a
 * real flag image from flagcdn.com). Returns '' for unknown / TBA teams so
 * the UI can fall back gracefully to an emoji or a soccer ball.
 */
function wc_country_code(?string $team): string {
    $name = strtolower(trim((string)$team));
    if ($name === '' || $name === 'tba') return '';

    // Normalize accents so "Côte d'Ivoire" matches "cotedivoire".
    $name = strtr($name, [
        'á'=>'a','à'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','ô'=>'o','ö'=>'o','õ'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n','š'=>'s','ž'=>'z','ć'=>'c','đ'=>'d',
    ]);
    $name = str_replace('&', 'and', $name);          // FIX: "Bosnia & Herzegovina" -> "...and..."
    $name = preg_replace('/[^a-z]/', '', $name);      // letters only -> "south korea" => "southkorea"

    static $map = [
        // ---- Hosts ----
        'usa'=>'us','unitedstates'=>'us','unitedstatesofamerica'=>'us',
        'canada'=>'ca','mexico'=>'mx',
        // ---- CONMEBOL (South America) ----
        'argentina'=>'ar','brazil'=>'br','brasil'=>'br','uruguay'=>'uy',
        'colombia'=>'co','chile'=>'cl','peru'=>'pe','paraguay'=>'py',
        'ecuador'=>'ec','venezuela'=>'ve','bolivia'=>'bo',
        // ---- UEFA (Europe) ----
        'france'=>'fr','spain'=>'es','germany'=>'de','portugal'=>'pt',
        'england'=>'gb-eng','scotland'=>'gb-sct','wales'=>'gb-wls','northernireland'=>'gb-nir',
        'netherlands'=>'nl','holland'=>'nl','belgium'=>'be','italy'=>'it',
        'croatia'=>'hr','switzerland'=>'ch','denmark'=>'dk','sweden'=>'se',
        'norway'=>'no','poland'=>'pl','austria'=>'at','serbia'=>'rs',
        'ukraine'=>'ua','czechia'=>'cz','czechrepublic'=>'cz','turkey'=>'tr','turkiye'=>'tr',
        'greece'=>'gr','russia'=>'ru','hungary'=>'hu','romania'=>'ro','slovenia'=>'si',
        'slovakia'=>'sk','iceland'=>'is','finland'=>'fi','republicofireland'=>'ie','ireland'=>'ie',
        'albania'=>'al','bosniaandherzegovina'=>'ba','bosnia'=>'ba','herzegovina'=>'ba',
        'northmacedonia'=>'mk','macedonia'=>'mk','georgia'=>'ge','bulgaria'=>'bg',
        'montenegro'=>'me','kosovo'=>'xk','luxembourg'=>'lu','armenia'=>'am','azerbaijan'=>'az',
        'belarus'=>'by','estonia'=>'ee','latvia'=>'lv','lithuania'=>'lt','moldova'=>'md',
        'cyprus'=>'cy','malta'=>'mt','faroeislands'=>'fo','gibraltar'=>'gi','andorra'=>'ad',
        'sanmarino'=>'sm','liechtenstein'=>'li','kazakhstan'=>'kz',
        // ---- AFC (Asia) ----
        'japan'=>'jp','southkorea'=>'kr','korearepublic'=>'kr','koreasouth'=>'kr',
        'northkorea'=>'kp','koreadpr'=>'kp','koreanorth'=>'kp',
        'australia'=>'au','saudiarabia'=>'sa','qatar'=>'qa','iran'=>'ir','iriran'=>'ir',
        'iraq'=>'iq','uae'=>'ae','unitedarabemirates'=>'ae','jordan'=>'jo','oman'=>'om',
        'bahrain'=>'bh','kuwait'=>'kw','uzbekistan'=>'uz','china'=>'cn','chinapr'=>'cn',
        'india'=>'in','thailand'=>'th','vietnam'=>'vn','indonesia'=>'id','malaysia'=>'my',
        'philippines'=>'ph','israel'=>'il','palestine'=>'ps','syria'=>'sy','lebanon'=>'lb',
        'yemen'=>'ye','tajikistan'=>'tj','turkmenistan'=>'tm','kyrgyzstan'=>'kg',
        'afghanistan'=>'af','bangladesh'=>'bd','srilanka'=>'lk','nepal'=>'np','myanmar'=>'mm',
        'cambodia'=>'kh','singapore'=>'sg','hongkong'=>'hk','chinesetaipei'=>'tw','taiwan'=>'tw',
        'maldives'=>'mv','bhutan'=>'bt','brunei'=>'bn','laos'=>'la','mongolia'=>'mn',
        // ---- CAF (Africa) ----
        'morocco'=>'ma','senegal'=>'sn','ghana'=>'gh','nigeria'=>'ng','cameroon'=>'cm',
        'egypt'=>'eg','algeria'=>'dz','tunisia'=>'tn','ivorycoast'=>'ci','cotedivoire'=>'ci',
        'southafrica'=>'za','mali'=>'ml','burkinafaso'=>'bf','capeverde'=>'cv','caboverde'=>'cv',
        'guinea'=>'gn','gabon'=>'ga','angola'=>'ao','zambia'=>'zm','kenya'=>'ke',
        'drcongo'=>'cd','congodr'=>'cd','democraticrepublicofthecongo'=>'cd',
        'congo'=>'cg','republicofthecongo'=>'cg','tanzania'=>'tz','uganda'=>'ug',
        'zimbabwe'=>'zw','mozambique'=>'mz','namibia'=>'na','botswana'=>'bw','benin'=>'bj',
        'togo'=>'tg','niger'=>'ne','sierraleone'=>'sl','liberia'=>'lr','gambia'=>'gm',
        'mauritania'=>'mr','madagascar'=>'mg','ethiopia'=>'et','sudan'=>'sd','southsudan'=>'ss',
        'libya'=>'ly','comoros'=>'km','guineabissau'=>'gw','equatorialguinea'=>'gq',
        'centralafricanrepublic'=>'cf','chad'=>'td','rwanda'=>'rw','burundi'=>'bi',
        'malawi'=>'mw','lesotho'=>'ls','eswatini'=>'sz','swaziland'=>'sz','somalia'=>'so',
        'eritrea'=>'er','djibouti'=>'dj','mauritius'=>'mu','seychelles'=>'sc',
        // ---- CONCACAF / Caribbean ----
        'costarica'=>'cr','panama'=>'pa','jamaica'=>'jm','honduras'=>'hn','curacao'=>'cw',
        'suriname'=>'sr','haiti'=>'ht','elsalvador'=>'sv','guatemala'=>'gt','cuba'=>'cu',
        'trinidadandtobago'=>'tt','nicaragua'=>'ni','dominicanrepublic'=>'do','guyana'=>'gy',
        'belize'=>'bz','bermuda'=>'bm','barbados'=>'bb','grenada'=>'gd','bahamas'=>'bs',
        'puertorico'=>'pr','aruba'=>'aw','antiguaandbarbuda'=>'ag','saintlucia'=>'lc',
        'saintkittsandnevis'=>'kn','saintvincentandthegrenadines'=>'vc','dominica'=>'dm',
        'montserrat'=>'ms','caymanislands'=>'ky',
        // ---- OFC (Oceania) ----
        'newzealand'=>'nz','fiji'=>'fj','papuanewguinea'=>'pg','solomonislands'=>'sb',
        'vanuatu'=>'vu','tahiti'=>'pf','newcaledonia'=>'nc','samoa'=>'ws','tonga'=>'to',
        'cookislands'=>'ck',
    ];

    return $map[$name] ?? '';
}

/**
 * Converts an ISO2 code to a flag emoji. Used only as a fallback if the flag
 * image fails to load. Returns '' for empty / non-standard codes.
 */
function wc_flag_emoji(string $code): string {
    $code = strtoupper(trim($code));
    if (strlen($code) !== 2 || !ctype_alpha($code)) return ''; // e.g. "gb-eng" -> no emoji, image only
    $a = 0x1F1E6 + (ord($code[0]) - 65);
    $b = 0x1F1E6 + (ord($code[1]) - 65);
    return mb_chr($a, 'UTF-8') . mb_chr($b, 'UTF-8');
}
/* ============ [/WC2026 UI ENHANCEMENT helpers] ============ */

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

/* =====================================================================
 * [WC2026 UI ENHANCEMENT] Build a DISPLAY-ONLY list of nations to orbit
 * the globe. Uses ONLY the $matches already fetched above — no new query,
 * no change to any existing logic. Capped to keep the orbit clean; the
 * full list always remains in the functional grid below.
 * ===================================================================== */
$globeNations = [];
foreach ($matches as $gm) {
    foreach ([$gm['home_team'] ?? '', $gm['away_team'] ?? ''] as $sideName) {
        $nm = trim((string)$sideName);
        if ($nm === '' || strtolower($nm) === 'tba') continue;
        if (isset($globeNations[$nm])) continue;
        $code = wc_country_code($nm);
        $globeNations[$nm] = [
            'name'  => $nm,
            'code'  => $code,
            'emoji' => wc_flag_emoji($code),
        ];
        if (count($globeNations) >= 12) break 2; // clean orbit cap (full list stays in the grid)
    }
}
$globeNations = array_values($globeNations);
$globeCount   = count($globeNations);

/* ---- Profile stats (for the My Profile pop-up, mirrors home.php) ---- */
$overallScore = (int)wc_scalar($conn, "SELECT COALESCE(SUM(total_points),0) FROM WC2026_Game_Sessions WHERE user_id=?", "i", [$userId]);
$weeklyScore  = (int)wc_scalar($conn, "SELECT COALESCE(SUM(total_points),0) FROM WC2026_Game_Sessions WHERE user_id=? AND YEARWEEK(play_date,3)=YEARWEEK(CURDATE(),3)", "i", [$userId]);
$playedDays   = (int)wc_scalar($conn, "SELECT COUNT(DISTINCT play_date) FROM WC2026_Game_Sessions WHERE user_id=?", "i", [$userId]);
$myRank = '--';
$rankRows = wc_rows($conn, "
    SELECT rank_no FROM (
        SELECT user_id, DENSE_RANK() OVER (ORDER BY COALESCE(SUM(total_points),0) DESC) AS rank_no
        FROM WC2026_Game_Sessions GROUP BY user_id
    ) r WHERE user_id=? LIMIT 1", "i", [$userId]);
if ($rankRows) $myRank = '#' . (int)$rankRows[0]['rank_no'];

/* ---- Per-match reaction + comment counts (for the card badges) ---- */
$matchReactCounts   = [];
$matchCommentCounts = [];
$mids = [];
foreach ($matches as $mm) $mids[] = (int)$mm['id'];
if ($mids) {
    $inList = implode(',', array_map('intval', $mids)); // ints only -> safe to inline
    foreach (wc_rows($conn, "SELECT match_id, COUNT(*) AS c FROM WC2026_Match_Reactions WHERE match_id IN ($inList) GROUP BY match_id") as $r) {
        $matchReactCounts[(int)$r['match_id']] = (int)$r['c'];
    }
    foreach (wc_rows($conn, "SELECT match_id, COUNT(*) AS c FROM WC2026_Match_Comments WHERE status='Active' AND match_id IN ($inList) GROUP BY match_id") as $r) {
        $matchCommentCounts[(int)$r['match_id']] = (int)$r['c'];
    }
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
    border:0;
    cursor:pointer;
    font-family:inherit;
}

/* Footer (consistent CATRION credit) */
.wc-footer{
    margin-top:10px;
    padding:24px 38px;
    background:linear-gradient(135deg,#071A35,#0B2C55);
    color:#fff;
}
.wc-foot-inner{
    max-width:1220px;
    margin:0 auto;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    flex-wrap:wrap;
}
.wc-foot-inner b{font-size:14px;font-weight:900}
.wc-foot-inner span{font-size:12px;font-weight:700;color:rgba(255,255,255,.7)}
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
    background:#0c1830;
    border:1px solid rgba(168,231,255,.2);
    border-radius:30px;
    overflow:hidden;
    box-shadow:0 35px 90px rgba(0,0,0,.5);
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
    background:#0c1830;
    color:#fff;
    border-bottom:1px solid rgba(168,231,255,.14);
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
    background:rgba(255,255,255,.12);
    color:#fff;
    font-size:24px;
    font-weight:900;
    cursor:pointer;
}
.predict-popup iframe{
    width:100%;
    height:calc(100% - 62px);
    border:0;
    background:#0c1830;
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

/* =====================================================================
   [WC2026 UI ENHANCEMENT] Flags, card hover lift & gold tap flourish.
   Pure visual layer — added below the original styles so nothing above
   is overridden destructively. Keeps the existing navy/blue/gold theme.
   ===================================================================== */

/* --- Country flag as a FOOTBALL BADGE (hollow-ball shape, not a square) ---
   The white circular frame + faint panel hints + gloss read like a ball,
   and the flag sits as a smaller inner disc, leaving a "hollow ball" ring. */
.team-flag-frame{
    position:relative;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:66px;
    height:66px;
    margin:0 auto 10px;
    border-radius:50%;             /* ball shape */
    background:#fff;
    /* gold ring + soft depth = trophy/ball vibe */
    box-shadow:0 0 0 2px rgba(245,200,91,.60), 0 8px 18px rgba(7,42,85,.16);
    overflow:hidden;
}
/* faint football-panel hints painted over the white ring */
.team-flag-frame:before{
    content:"";
    position:absolute;
    inset:0;
    border-radius:50%;
    background:
        radial-gradient(circle at 50% 16%, rgba(7,26,53,.07) 0 18%, transparent 19%),
        radial-gradient(circle at 16% 80%, rgba(7,26,53,.05) 0 12%, transparent 13%),
        radial-gradient(circle at 84% 80%, rgba(7,26,53,.05) 0 12%, transparent 13%);
    pointer-events:none;
    z-index:2;
}
/* glossy highlight so the badge looks like a real ball */
.team-flag-frame:after{
    content:"";
    position:absolute;
    top:8%;left:16%;
    width:40%;height:28%;
    border-radius:50%;
    background:linear-gradient(180deg,rgba(255,255,255,.85),rgba(255,255,255,0));
    pointer-events:none;
    z-index:3;
}
.team-flag{
    width:50px;
    height:50px;
    border-radius:50%;             /* inner disc -> flag as a round badge */
    object-fit:cover;
    display:block;
    position:relative;
    z-index:1;
}
.team-flag-fallback{               /* emoji flag or soccer ball fallback */
    width:50px;
    height:50px;
    border-radius:50%;
    display:grid;
    place-items:center;
    font-size:26px;
    line-height:1;
    position:relative;
    z-index:1;
}

/* --- Clearer, themed country name (wraps to 2 lines, stays aligned) --- */
.team-name{
    font-size:13.5px;
    letter-spacing:.2px;
    line-height:1.3;
    display:-webkit-box;
    -webkit-line-clamp:2;
    -webkit-box-orient:vertical;
    white-space:normal;        /* allow wrapping instead of clipping */
    min-height:34px;           /* keeps home/away baseline aligned */
}

/* --- Country box = tappable "pick" target (cosmetic voting feel) --- */
.team[data-team-pick]{
    cursor:pointer;
    border-radius:18px;
    padding:8px 4px;
    transition:transform .18s ease, box-shadow .25s ease, background .25s ease;
    -webkit-tap-highlight-color:transparent;
    touch-action:manipulation; /* removes the 300ms mobile tap delay */
}
.team[data-team-pick]:hover{
    background:rgba(245,200,91,.10);
}
/* Gold glow burst when a country is tapped/clicked */
.team[data-team-pick].picked{
    background:rgba(245,200,91,.16);
    box-shadow:0 0 0 2px rgba(245,200,91,.55), 0 0 22px rgba(245,200,91,.45);
    animation:wcPickPulse .5s ease;
}
@keyframes wcPickPulse{
    0%{transform:scale(1)}
    35%{transform:scale(1.05)}
    100%{transform:scale(1)}
}

/* --- Short gold sparks (created in JS, cleaned up after they finish) --- */
.wc-spark{
    position:fixed;
    z-index:400;
    width:8px;
    height:8px;
    border-radius:50%;
    background:radial-gradient(circle, var(--gold2), var(--gold));
    box-shadow:0 0 8px rgba(245,200,91,.9);
    pointer-events:none;
    will-change:transform, opacity;
    animation:wcSpark .6s ease-out forwards;
}
@keyframes wcSpark{
    0%{transform:translate(0,0) scale(1); opacity:1}
    100%{transform:translate(var(--dx), var(--dy)) scale(.2); opacity:0}
}

/* --- Smooth card hover: slight lift, richer shadow, glowing gold border --- */
.match-card{
    transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease;
}
.match-card:hover{
    transform:translateY(-4px);
    box-shadow:0 24px 50px rgba(7,42,85,.16);
    border-color:rgba(245,200,91,.75);
}

/* --- Accessibility: honor users who ask for less motion --- */
@media (prefers-reduced-motion: reduce){
    .team[data-team-pick].picked{animation:none}
    .match-card{transition:none}
    .match-card:hover{transform:none}
    .wc-spark{display:none}
}

/* --- Mobile polish for the new flag block --- */
@media(max-width:640px){
    .team-flag-frame{width:54px;height:54px}
    .team-name{font-size:13px}
}

/* --- Football accent in the center score box (⚽ on top of VS/score) --- */
.score{
    display:flex;            /* override original grid so the ball can sit on top */
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:1px;
    padding:4px 0;
}
.vs-ball{
    font-size:14px;
    line-height:1;
    filter:drop-shadow(0 2px 4px rgba(0,0,0,.35));
}

/* --- Trophy chip on finished matches (results available) --- */
.trophy{
    font-size:12px;
    margin-right:3px;
    filter:drop-shadow(0 1px 2px rgba(0,0,0,.15));
}

/* --- Faint football watermark behind each card (theme, no clutter) --- */
.card-ball{
    position:absolute;
    right:-14px;
    bottom:-14px;
    width:96px;
    height:96px;
    opacity:.06;
    z-index:0;               /* sits below the card content (content is z-index:1) */
    pointer-events:none;
    color:var(--deep);
}
@media(max-width:640px){
    .card-ball{width:78px;height:78px;right:-10px;bottom:-10px}
}
/* =====================================================================
   [WC2026 UI ENHANCEMENT] GLOBE STAGE — nations orbiting a 3D-ish Earth.
   Pure CSS animation (transform / background-position / opacity only) so
   it stays smooth. Honors the existing navy/blue/gold theme.
   ===================================================================== */
.globe-stage{
    --globe:320px;          /* globe diameter (overridden on mobile) */
    --R:240px;              /* orbit radius: badge distance from center */
    position:relative;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    margin:8px 0 26px;
    padding:30px 0 10px;
    min-height:520px;
    overflow:hidden;
    border-radius:30px;
    /* deep night-sky backdrop, on-theme */
    background:
        radial-gradient(circle at 50% 40%, rgba(85,183,255,.12), transparent 55%),
        radial-gradient(circle at 50% 40%, rgba(245,200,91,.08), transparent 45%),
        linear-gradient(180deg,#08213F 0%,#0B2C55 60%,#0A2548 100%);
    border:1px solid rgba(245,200,91,.18);
}
/* faint starfield dots for atmosphere */
.globe-stage:before{
    content:"";
    position:absolute;
    inset:0;
    background:
        radial-gradient(1.5px 1.5px at 18% 22%, rgba(255,255,255,.6), transparent),
        radial-gradient(1.5px 1.5px at 78% 30%, rgba(255,255,255,.45), transparent),
        radial-gradient(1.5px 1.5px at 35% 75%, rgba(255,255,255,.5), transparent),
        radial-gradient(1.5px 1.5px at 65% 82%, rgba(255,255,255,.4), transparent),
        radial-gradient(1.5px 1.5px at 88% 60%, rgba(255,255,255,.45), transparent);
    opacity:.7;
    pointer-events:none;
}

.globe-wrap{
    position:relative;
    width:calc(var(--R) * 2 + 130px);
    height:calc(var(--R) * 2 + 130px);
    max-width:100%;
    display:grid;
    place-items:center;
}

/* soft glow halo behind the globe */
.globe-glow{
    position:absolute;
    width:calc(var(--globe) + 120px);
    height:calc(var(--globe) + 120px);
    border-radius:50%;
    background:radial-gradient(circle, rgba(85,183,255,.45) 0%, rgba(245,200,91,.18) 45%, transparent 70%);
    filter:blur(8px);
    pointer-events:none;
}

/* --- The globe itself (semi-3D sphere) --- */
.globe{
    position:relative;
    width:var(--globe);
    height:var(--globe);
    border-radius:50%;
    background:radial-gradient(circle at 34% 28%, #3A8BF6 0%, #0E63E6 30%, #0B2C55 64%, #061633 100%);
    box-shadow:
        inset -26px -30px 60px rgba(0,0,0,.55),
        inset 20px 18px 44px rgba(120,200,255,.30),
        0 0 0 3px rgba(245,200,91,.35),
        0 18px 50px rgba(7,26,53,.45);
    overflow:hidden;
    animation:globeSway 10s ease-in-out infinite;   /* gentle left-right life */
    z-index:1;
}
/* drifting continents (green blobs) */
.globe-tex{
    position:absolute;
    inset:0;
    border-radius:50%;
    background:
        radial-gradient(42px 30px at 28% 38%, rgba(46,176,124,.9), transparent 70%),
        radial-gradient(60px 34px at 62% 30%, rgba(33,150,100,.8), transparent 72%),
        radial-gradient(48px 32px at 54% 68%, rgba(46,176,124,.78), transparent 70%),
        radial-gradient(30px 22px at 82% 58%, rgba(33,150,100,.75), transparent 72%);
    background-repeat:no-repeat;
    opacity:.6;
    animation:continentDrift 14s ease-in-out infinite;
}
/* longitude/latitude grid that scrolls = slow rotation illusion */
.globe-grid{
    position:absolute;
    inset:0;
    border-radius:50%;
    background:
        repeating-linear-gradient(90deg, rgba(255,255,255,.10) 0 1px, transparent 1px 26px),
        repeating-linear-gradient(0deg, rgba(255,255,255,.07) 0 1px, transparent 1px 30px);
    -webkit-mask:radial-gradient(circle at 50% 50%, #000 62%, transparent 72%);
            mask:radial-gradient(circle at 50% 50%, #000 62%, transparent 72%);
    opacity:.5;
    animation:meridianSpin 20s linear infinite;       /* seamless loop (one tile = 26px) */
}
/* glossy top-left highlight for sphere volume */
.globe-shine{
    position:absolute;
    top:10%;
    left:14%;
    width:42%;
    height:34%;
    border-radius:50%;
    background:linear-gradient(180deg, rgba(255,255,255,.55), rgba(255,255,255,0));
    filter:blur(2px);
    pointer-events:none;
}

@keyframes globeSway{
    0%,100%{transform:rotate(-2deg)}
    50%{transform:rotate(2deg)}
}
@keyframes meridianSpin{
    from{background-position:0 0, 0 0}
    to{background-position:-26px 0, 0 -30px}
}
@keyframes continentDrift{
    0%,100%{transform:translateX(-4px)}
    50%{transform:translateX(4px)}
}

/* --- Decorative orbit: dashed gold ring + slowly rotating energy rays --- */
.orbit-ring{
    position:absolute;
    width:calc(var(--R) * 2);
    height:calc(var(--R) * 2);
    border-radius:50%;
    border:1px dashed rgba(245,200,91,.30);
    pointer-events:none;
}
.orbit-rays{
    position:absolute;
    width:calc(var(--R) * 2 + 40px);
    height:calc(var(--R) * 2 + 40px);
    border-radius:50%;
    background:repeating-conic-gradient(from 0deg, rgba(245,200,91,.14) 0 1deg, transparent 1deg 30deg);
    -webkit-mask:radial-gradient(circle, transparent 58%, #000 60%, transparent 78%);
            mask:radial-gradient(circle, transparent 58%, #000 60%, transparent 78%);
    pointer-events:none;
    animation:raysSpin 60s linear infinite;           /* energy lines orbiting */
}
@keyframes raysSpin{ to{transform:rotate(360deg)} }

/* --- Nation badges pinned around the globe (upright, readable) --- */
.orbit-badge{
    position:absolute;
    top:50%;
    left:50%;
    text-decoration:none;
    /* place on the ring at angle --a while keeping the badge upright */
    transform:translate(-50%,-50%) rotate(var(--a)) translateY(calc(-1 * var(--R))) rotate(calc(-1 * var(--a)));
    z-index:3;
    animation:badgeBob 6s ease-in-out infinite;
    animation-delay:calc(var(--a) * -0.02s);          /* stagger the bob */
}
.orbit-inner{
    display:flex;
    align-items:center;
    gap:8px;
    padding:7px 12px 7px 7px;
    border-radius:999px;
    background:rgba(255,255,255,.94);
    border:1px solid rgba(245,200,91,.45);
    box-shadow:0 8px 20px rgba(7,26,53,.35);
    transition:transform .2s ease, box-shadow .25s ease, border-color .25s ease;
    cursor:pointer;
}
.orbit-flag{
    position:relative;
    flex:0 0 auto;
    width:34px;
    height:34px;
    border-radius:50%;
    overflow:hidden;
    background:#fff;
    box-shadow:0 0 0 2px rgba(245,200,91,.55);
}
.orbit-flag img{width:100%;height:100%;object-fit:cover;display:block}
.orbit-flag-fb{width:100%;height:100%;display:grid;place-items:center;font-size:18px}
.orbit-name{
    font-size:12.5px;
    font-weight:900;
    color:var(--deep);
    white-space:nowrap;
    max-width:96px;
    overflow:hidden;
    text-overflow:ellipsis;
}
/* a glowing dot anchoring each badge toward the globe (connection cue) */
.orbit-badge:after{
    content:"";
    position:absolute;
    left:50%;
    bottom:-9px;
    width:7px;
    height:7px;
    border-radius:50%;
    transform:translateX(-50%);
    background:radial-gradient(circle, var(--gold2), var(--gold));
    box-shadow:0 0 10px rgba(245,200,91,.9);
}
@keyframes badgeBob{
    0%,100%{margin-top:0}
    50%{margin-top:-6px}
}

/* hover / active: gentle zoom + gold glow */
.orbit-badge:hover .orbit-inner,
.orbit-badge:focus-visible .orbit-inner{
    transform:scale(1.12);
    border-color:var(--gold);
    box-shadow:0 12px 26px rgba(245,200,91,.45), 0 0 0 3px rgba(245,200,91,.35);
}
.orbit-badge.picked .orbit-inner{       /* gold flash on click (spark script adds .picked) */
    box-shadow:0 0 0 3px rgba(245,200,91,.65), 0 0 26px rgba(245,200,91,.55);
}

/* caption under the globe */
.globe-caption{
    position:relative;
    z-index:4;
    text-align:center;
    color:#fff;
    margin-top:8px;
}
.globe-kicker{
    display:inline-block;
    font-size:12px;
    font-weight:900;
    letter-spacing:.5px;
    color:var(--gold2);
    margin-bottom:6px;
}
.globe-caption h2{
    margin:0;
    font-size:26px;
    font-weight:900;
    letter-spacing:-.5px;
}
.globe-caption p{
    margin:6px 0 0;
    font-size:13px;
    font-weight:700;
    color:rgba(255,255,255,.72);
}

/* --- Responsive: globe shrinks, badges become flag-pins on small screens --- */
@media(max-width:1050px){
    .globe-stage{--globe:280px;--R:210px;min-height:480px}
}
@media(max-width:640px){
    .globe-stage{--globe:190px;--R:138px;min-height:420px;border-radius:24px}
    .orbit-name{display:none}            /* show flag-pins only -> no clutter */
    .orbit-inner{padding:5px}
    .orbit-flag{width:30px;height:30px}
    .globe-caption h2{font-size:21px}
}

/* --- Accessibility: stop motion for users who ask for it --- */
@media (prefers-reduced-motion: reduce){
    .globe,.globe-tex,.globe-grid,.orbit-rays,.orbit-badge,
    .hud-ring,.hud-live i{animation:none}
}

/* =====================================================================
   [WC2026 UI ENHANCEMENT] FIFA-STYLE DASHBOARD LAYER
   Broadcast HUD around the globe: corner brackets, rotating tick ring,
   connection beams, live pill + stat chips. Display only, all from vars
   already computed in PHP. transform/opacity only -> stays smooth.
   ===================================================================== */

/* broadcast corner brackets on the stage */
.globe-stage .hud-corner{
    position:absolute;
    width:34px;
    height:34px;
    border:2px solid rgba(245,200,91,.55);
    z-index:5;
    pointer-events:none;
}
.globe-stage .hud-corner.tl{top:14px;left:14px;border-right:0;border-bottom:0;border-radius:10px 0 0 0}
.globe-stage .hud-corner.tr{top:14px;right:14px;border-left:0;border-bottom:0;border-radius:0 10px 0 0}
.globe-stage .hud-corner.bl{bottom:14px;left:14px;border-right:0;border-top:0;border-radius:0 0 0 10px}
.globe-stage .hud-corner.br{bottom:14px;right:14px;border-left:0;border-top:0;border-radius:0 0 10px 0}

/* top HUD bar: live pill (left) + stat chips (right) */
.hud-bar{
    position:absolute;
    top:18px;
    left:50%;
    transform:translateX(-50%);
    width:calc(100% - 80px);
    max-width:760px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    z-index:6;
    pointer-events:none;
    flex-wrap:wrap;
}
.hud-live{
    display:inline-flex;
    align-items:center;
    gap:7px;
    padding:6px 12px;
    border-radius:999px;
    background:rgba(8,33,63,.7);
    border:1px solid rgba(245,200,91,.35);
    color:#fff;
    font-size:11px;
    font-weight:900;
    letter-spacing:.6px;
    backdrop-filter:blur(6px);
}
.hud-live i{
    width:8px;height:8px;border-radius:50%;
    background:var(--green);
    box-shadow:0 0 10px rgba(17,163,106,.9);
    animation:livePulse 1.6s ease-in-out infinite;
}
@keyframes livePulse{0%,100%{opacity:1}50%{opacity:.35}}
.hud-chips{display:flex;gap:8px;flex-wrap:wrap}
.hud-chip{
    display:inline-flex;
    flex-direction:column;
    align-items:center;
    padding:5px 12px;
    border-radius:12px;
    background:rgba(255,255,255,.08);
    border:1px solid rgba(255,255,255,.16);
    backdrop-filter:blur(6px);
    min-width:62px;
}
.hud-chip b{font-size:16px;font-weight:900;color:var(--gold2);line-height:1}
.hud-chip span{font-size:9.5px;font-weight:800;letter-spacing:.4px;color:rgba(255,255,255,.65);text-transform:uppercase;margin-top:3px}

/* rotating tick ring (HUD scanner around the globe) */
.hud-ring{
    position:absolute;
    width:calc(var(--globe) + 64px);
    height:calc(var(--globe) + 64px);
    border-radius:50%;
    pointer-events:none;
    z-index:2;
    background:
        repeating-conic-gradient(from 0deg, rgba(245,200,91,.55) 0 1.2deg, transparent 1.2deg 9deg);
    -webkit-mask:radial-gradient(circle, transparent 71%, #000 73%, #000 78%, transparent 80%);
            mask:radial-gradient(circle, transparent 71%, #000 73%, #000 78%, transparent 80%);
    animation:hudRingSpin 30s linear infinite;
}
.hud-ring.reverse{
    width:calc(var(--globe) + 22px);
    height:calc(var(--globe) + 22px);
    background:conic-gradient(from 0deg, transparent 0 78%, rgba(85,183,255,.7) 86%, transparent 92%);
    -webkit-mask:radial-gradient(circle, transparent 80%, #000 82%, #000 88%, transparent 90%);
            mask:radial-gradient(circle, transparent 80%, #000 82%, #000 88%, transparent 90%);
    animation:hudRingSpin 8s linear infinite reverse;  /* fast scanner sweep */
}
@keyframes hudRingSpin{to{transform:rotate(360deg)}}

/* widen the energy rays so they read as beams connecting globe -> badges */
.orbit-rays{
    -webkit-mask:radial-gradient(circle, transparent 30%, #000 38%, #000 88%, transparent 96%);
            mask:radial-gradient(circle, transparent 30%, #000 38%, #000 88%, transparent 96%);
}

/* mobile: tighten the HUD so it never crowds the globe */
@media(max-width:640px){
    .globe-stage .hud-corner{width:24px;height:24px}
    .hud-bar{top:12px;width:calc(100% - 36px);justify-content:center}
    .hud-chip{min-width:54px;padding:4px 9px}
    .hud-chip b{font-size:14px}
}
/* ============ [/WC2026 UI ENHANCEMENT styles] ============ */

/* =====================================================================
   [WC2026] Match home.php header + dark colour scheme.
   Applied last so it overrides the light theme above. The globe stage
   (already dark) and the white flag badges stay intact.
   ===================================================================== */
html{background:#04142B}
body{
    background:
        radial-gradient(circle at 8% 8%, rgba(85,183,255,.16), transparent 30%),
        radial-gradient(circle at 92% 8%, rgba(14,99,230,.28), transparent 34%),
        radial-gradient(circle at 50% 76%, rgba(245,200,91,.07), transparent 30%),
        linear-gradient(180deg,#04142B 0%,#071F42 45%,#04142B 100%) !important;
    color:#fff !important;
}
/* Header to match home.php (dark gradient + KV banner) */
.hero{
    min-height:300px;
    padding:24px 38px 90px !important;
    background:
        radial-gradient(circle at 72% 18%, rgba(14,99,230,.34), transparent 32%),
        radial-gradient(circle at 15% 28%, rgba(85,183,255,.16), transparent 30%),
        linear-gradient(135deg,#04142B 0%,#08254D 48%,#0E63E6 100%) !important;
}
.hero-banner-layer{
    position:absolute;top:0;left:0;right:0;bottom:0;width:100%;z-index:0;
    background:url('/WC2026/WC-2026-KV.jpg') center center/cover no-repeat;
    opacity:.9;pointer-events:none;
}
.hero-banner-layer::after{
    content:"";position:absolute;inset:0;
    background:linear-gradient(135deg,rgba(4,20,43,.66),rgba(8,37,77,.42) 55%,rgba(14,99,230,.32));
}
.hero .nav,.hero .hero-content{position:relative;z-index:2}
.brand-title{color:#fff}
.brand-sub{color:rgba(255,255,255,.78)}

/* Stat cards stay light (same as home), content cards go dark */
.stat{background:linear-gradient(180deg,#FFFFFF,#F7FAFF) !important;box-shadow:0 18px 48px rgba(0,0,0,.20) !important}
.card{
    background:linear-gradient(135deg,#061A36,#08254D 58%,#0A3A76) !important;
    border:1px solid rgba(168,231,255,.18) !important;color:#fff !important;
    box-shadow:0 24px 60px rgba(0,0,0,.28) !important;
}
.card-title{color:#fff !important}
.tab{background:rgba(255,255,255,.08) !important;color:#fff !important;border:1px solid rgba(168,231,255,.18)}
.tab.active{background:linear-gradient(135deg,#F5C85B,#FFE19A) !important;color:#071A35 !important}
.search input{background:rgba(255,255,255,.06) !important;border:1px solid rgba(168,231,255,.2) !important;color:#fff !important}
.search input::placeholder{color:rgba(255,255,255,.5)}
.search button{background:linear-gradient(135deg,#F5C85B,#FFE19A) !important;color:#071A35 !important}

/* Match cards dark with readable text */
.match-card{background:linear-gradient(135deg,rgba(255,255,255,.07),rgba(255,255,255,.02)) !important;border:1px solid rgba(168,231,255,.16) !important}
.match-card:before{display:none}
.round{color:rgba(255,255,255,.6) !important}
.team-name{color:#fff !important}
.match-meta{color:rgba(255,255,255,.62) !important;border-top:1px solid rgba(168,231,255,.12) !important}
.status.upcoming{background:rgba(85,183,255,.18) !important;color:#A8E7FF !important}
.status.finished{background:rgba(255,255,255,.1) !important;color:rgba(255,255,255,.72) !important}
.status.live{background:rgba(17,163,106,.18) !important;color:#7EF4AE !important;box-shadow:none !important}
.empty{background:rgba(245,200,91,.12) !important;border-color:rgba(245,200,91,.3) !important;color:#FFE19A !important}
.details-btn{background:rgba(255,255,255,.12) !important;border:1px solid rgba(168,231,255,.18) !important}

/* Wide layout + full-width banner like home.php */
.nav,.hero-content,.container{max-width:1680px !important}
@media(min-width:1500px){.nav,.hero-content,.container{max-width:1720px !important}}

/* Match card: total reactions + comment indicator (always available) */
.match-social-mini{position:relative;z-index:1;display:flex;gap:10px;flex-wrap:wrap;align-items:center;width:100%;
    margin-top:12px;padding:9px 11px;border:1px solid rgba(168,231,255,.16);border-radius:14px;
    background:rgba(255,255,255,.04);color:#fff;cursor:pointer;font:inherit;text-align:start;transition:.2s ease}
.match-social-mini:hover{background:rgba(245,200,91,.12);border-color:rgba(245,200,91,.5)}
.msm-pill{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:800}
.msm-pill b{font-weight:900;color:#FFE19A}
.msm-comment{color:rgba(255,255,255,.78)}
.msm-comment.muted{color:rgba(255,255,255,.5)}
@media(max-width:640px){.hero{padding:22px 18px 86px !important}}
</style>
</head>
<body>

<header class="hero">
    <div class="hero-banner-layer"></div>
    <div class="nav">
        <div class="brand">
            <div class="logo-card">
                <img src="<?= h($logoPath) ?>" alt="CATRION">
            </div>
            <div>
                <div class="brand-title">CATRION FIFA World Cup 2026 Challenge</div>
                <div class="brand-sub">Real Matches Center</div>
            </div>
        </div>

        <div class="nav-actions top-actions">
            <div class="theme-switch" role="group" aria-label="Theme">
                <button type="button" class="theme-btn" data-theme-set="catrion">CATRION</button>
                <button type="button" class="theme-btn" data-theme-set="saudi">Saudi</button>
            </div>
            <div class="lang-switch" role="group" aria-label="Language">
                <button type="button" class="lang-btn" data-lang-set="en">EN</button>
                <button type="button" class="lang-btn" data-lang-set="ar">عربي</button>
            </div>
            <a href="/WC2026/" class="nav-link" data-i18n="navHome">Home</a>
            <a href="/WC2026/matches" class="nav-link active" data-i18n="navMatches">Matches</a>
            <button type="button" class="nav-link icon-link" id="openFanFilterBtn" title="Fan Filter Studio">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z"/><path d="M3 8a2 2 0 0 1 2-2h2l1.5-2h7L19 6h0a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
                <span data-i18n="navFanFilter">Fan Filter</span>
            </button>
            <button type="button" class="nav-link" id="openProfileBtn" data-i18n="navProfile">My Profile</button>
            <button type="button" class="nav-link" id="howToBtn" data-i18n="navHowTo">How to Use</button>
            <button type="button" class="nav-link" id="pointsBtn" data-i18n="navPoints">Points</button>
            <form method="POST" action="/WC2026/" style="margin:0;">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="logout" data-i18n="navLogout">Logout</button>
            </form>
        </div>
    </div>

    <div class="hero-content">
        <div class="badge"><i></i> <span data-i18n="heroBadge">Reading from Database</span></div>
        <h1 data-i18n-html="heroTitle">Real Matches.<br><span>Real Results.</span></h1>
        <p data-i18n="heroIntro">
            Browse the official World Cup 2026 match list, live status, upcoming fixtures,
            and final results. Submit predictions before kickoff and climb the leaderboard.
        </p>
    </div>
</header>

<main class="container">

    <section class="stats">
        <div class="stat">
            <div class="stat-label" data-i18n="statTotal">Total Matches</div>
            <div class="stat-value"><?= (int)$totalMatches ?></div>
            <div class="stat-note" data-i18n="statTotalNote">Synced fixtures</div>
        </div>

        <div class="stat">
            <div class="stat-label" data-i18n="statUpcoming">Upcoming</div>
            <div class="stat-value"><?= (int)$upcomingMatches ?></div>
            <div class="stat-note" data-i18n="statUpcomingNote">Open for predictions</div>
        </div>

        <div class="stat">
            <div class="stat-label" data-i18n="statLive">Live Now</div>
            <div class="stat-value"><?= (int)$liveMatches ?></div>
            <div class="stat-note" data-i18n="statLiveNote">Currently playing</div>
        </div>

        <div class="stat">
            <div class="stat-label" data-i18n="statFinished">Finished</div>
            <div class="stat-value"><?= (int)$finishedMatches ?></div>
            <div class="stat-note" data-i18n="statFinishedNote">Results available</div>
        </div>
    </section>

    <!-- =====================================================================
         [WC2026 UI ENHANCEMENT] GLOBE STAGE — nations orbit a 3D-ish Earth.
         Display only. Each badge links to the EXISTING search filter
         (?q=Country), so no logic/data flow changes. The full functional
         match grid stays right below.
         ===================================================================== -->
    <section class="globe-stage" aria-label="World Cup 2026 nations">
        <!-- [WC2026 UI ENHANCEMENT] FIFA-style HUD frame + live stats (display only) -->
        <span class="hud-corner tl" aria-hidden="true"></span>
        <span class="hud-corner tr" aria-hidden="true"></span>
        <span class="hud-corner bl" aria-hidden="true"></span>
        <span class="hud-corner br" aria-hidden="true"></span>

        <div class="hud-bar" aria-hidden="true">
            <span class="hud-live"><i></i> LIVE GLOBE</span>
            <div class="hud-chips">
                <span class="hud-chip"><b><?= (int)$globeCount ?></b><span>Nations</span></span>
                <span class="hud-chip"><b><?= (int)$totalMatches ?></b><span>Fixtures</span></span>
                <span class="hud-chip"><b><?= (int)$liveMatches ?></b><span>Live</span></span>
            </div>
        </div>

        <div class="globe-glow"></div>
        <div class="globe-wrap">
            <div class="hud-ring" aria-hidden="true"></div>
            <div class="hud-ring reverse" aria-hidden="true"></div>
            <div class="orbit-rays" aria-hidden="true"></div>
            <div class="orbit-ring" aria-hidden="true"></div>

            <div class="globe" aria-hidden="true">
                <div class="globe-tex"></div>
                <div class="globe-grid"></div>
                <div class="globe-shine"></div>
            </div>

            <?php if ($globeCount > 0): ?>
                <?php foreach ($globeNations as $i => $gn): ?>
                    <?php $angle = round((360 / max($globeCount, 1)) * $i, 2); ?>
                    <a class="orbit-badge"
                       href="/WC2026/matches?q=<?= h(urlencode($gn['name'])) ?>"
                       style="--a:<?= $angle ?>deg;"
                       data-globe-pick
                       title="View <?= h($gn['name']) ?> fixtures">
                        <span class="orbit-inner">
                            <span class="orbit-flag">
                                <?php if ($gn['code'] !== ''): ?>
                                    <img src="https://flagcdn.com/w80/<?= h(strtolower($gn['code'])) ?>.png"
                                         alt="<?= h($gn['name']) ?> flag"
                                         loading="lazy"
                                         onerror="this.style.display='none';this.nextElementSibling.style.display='grid';">
                                    <span class="orbit-flag-fb" style="display:none;"><?= $gn['emoji'] !== '' ? $gn['emoji'] : '⚽' ?></span>
                                <?php else: ?>
                                    <span class="orbit-flag-fb">⚽</span>
                                <?php endif; ?>
                            </span>
                            <span class="orbit-name"><?= h($gn['name']) ?></span>
                        </span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="globe-caption">
            <span class="globe-kicker">🌍 <span data-i18n="globeKicker">WORLD CUP 2026</span></span>
            <h2 data-i18n="globeTitle">Nations on the Pitch</h2>
            <p data-i18n="globeP">Tap a nation to jump to its fixtures.</p>
        </div>
    </section>

    <section class="card">
        <div class="toolbar">
            <div class="tabs">
                <a class="tab <?= $view === 'all' ? 'active' : '' ?>" href="/WC2026/matches" data-i18n="tabAll">All</a>
                <a class="tab <?= $view === 'upcoming' ? 'active' : '' ?>" href="/WC2026/matches?view=upcoming" data-i18n="tabUpcoming">Upcoming</a>
                <a class="tab <?= $view === 'live' ? 'active' : '' ?>" href="/WC2026/matches?view=live" data-i18n="tabLive">Live</a>
                <a class="tab <?= $view === 'finished' ? 'active' : '' ?>" href="/WC2026/matches?view=finished" data-i18n="tabFinished">Finished</a>
            </div>

            <form class="search" method="GET" action="/WC2026/matches">
                <?php if ($view !== 'all'): ?>
                    <input type="hidden" name="view" value="<?= h($view) ?>">
                <?php endif; ?>
                <input type="text" name="q" data-i18n-ph="searchPh" placeholder="Search team or round..." value="<?= h($search) ?>">
                <button type="submit" data-i18n="searchBtn">Search</button>
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

                        // [WC2026 UI ENHANCEMENT] Resolve flags for both teams (display only).
                        $homeCode  = wc_country_code($m['home_team'] ?? '');
                        $awayCode  = wc_country_code($m['away_team'] ?? '');
                        $homeEmoji = wc_flag_emoji($homeCode);
                        $awayEmoji = wc_flag_emoji($awayCode);
                    ?>
                    <article class="match-card">
                        <!-- [WC2026 UI ENHANCEMENT] Faint football watermark (decorative only) -->
                        <svg class="card-ball" viewBox="0 0 100 100" fill="none" aria-hidden="true">
                            <circle cx="50" cy="50" r="46" stroke="currentColor" stroke-width="3"/>
                            <path d="M50 24 64 35 59 52 41 52 36 35Z" fill="currentColor"/>
                            <path d="M50 24V10M64 35 78 30M59 52 70 64M41 52 30 64M36 35 22 30" stroke="currentColor" stroke-width="3"/>
                        </svg>

                        <div class="match-head">
                            <div class="round"><?= h($m['round_name'] ?: 'World Cup 2026') ?></div>
                            <div class="status <?= h($statusClass) ?>"><?php if ($statusClass === 'finished'): ?><span class="trophy" aria-hidden="true">🏆</span><?php endif; ?><?= h($statusLabel) ?></div>
                        </div>

                        <div class="teams">
                            <!-- [WC2026 UI ENHANCEMENT] HOME team: flag on top, name below.
                                 Priority: DB logo -> flag image (flagcdn) -> emoji -> ball. -->
                            <div class="team" data-team-pick>
                                <span class="team-flag-frame">
                                    <?php if (!empty($m['home_logo'])): ?>
                                        <img class="team-flag" src="<?= h($m['home_logo']) ?>" alt="<?= h($m['home_team']) ?>" loading="lazy">
                                    <?php elseif ($homeCode !== ''): ?>
                                        <img class="team-flag"
                                             src="https://flagcdn.com/w160/<?= h(strtolower($homeCode)) ?>.png"
                                             alt="<?= h($m['home_team']) ?> flag"
                                             loading="lazy"
                                             onerror="this.style.display='none';this.nextElementSibling.style.display='grid';">
                                        <span class="team-flag-fallback" aria-hidden="true" style="display:none;"><?= $homeEmoji !== '' ? $homeEmoji : '⚽' ?></span>
                                    <?php else: ?>
                                        <span class="team-flag-fallback" aria-hidden="true">⚽</span>
                                    <?php endif; ?>
                                </span>
                                <div class="team-name"><?= h($m['home_team']) ?></div>
                            </div>

                            <div class="score"><span class="vs-ball" aria-hidden="true">⚽</span><?= h($scoreText) ?></div>

                            <!-- [WC2026 UI ENHANCEMENT] AWAY team: same flag-on-top layout. -->
                            <div class="team" data-team-pick>
                                <span class="team-flag-frame">
                                    <?php if (!empty($m['away_logo'])): ?>
                                        <img class="team-flag" src="<?= h($m['away_logo']) ?>" alt="<?= h($m['away_team']) ?>" loading="lazy">
                                    <?php elseif ($awayCode !== ''): ?>
                                        <img class="team-flag"
                                             src="https://flagcdn.com/w160/<?= h(strtolower($awayCode)) ?>.png"
                                             alt="<?= h($m['away_team']) ?> flag"
                                             loading="lazy"
                                             onerror="this.style.display='none';this.nextElementSibling.style.display='grid';">
                                        <span class="team-flag-fallback" aria-hidden="true" style="display:none;"><?= $awayEmoji !== '' ? $awayEmoji : '⚽' ?></span>
                                    <?php else: ?>
                                        <span class="team-flag-fallback" aria-hidden="true">⚽</span>
                                    <?php endif; ?>
                                </span>
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

                        <?php $rcCount = (int)($matchReactCounts[(int)$m['id']] ?? 0); $ccCount = (int)($matchCommentCounts[(int)$m['id']] ?? 0); ?>
                        <button type="button" class="match-social-mini" data-predict-url="/WC2026/predict?match=<?= (int)$m['id'] ?>" title="Open reactions &amp; comments">
                            <span class="msm-pill msm-react">🔥 <b><?= $rcCount ?></b> <span data-i18n="reactionsWord">reactions</span></span>
                            <?php if ($ccCount > 0): ?>
                                <span class="msm-pill msm-comment">💬 <b><?= $ccCount ?></b> <span data-i18n="commentsWord">comments</span></span>
                            <?php else: ?>
                                <span class="msm-pill msm-comment muted">💬 <span data-i18n="commentWord">Comment</span></span>
                            <?php endif; ?>
                        </button>

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
                            <button type="button" class="details-btn" data-predict-url="/WC2026/predict?match=<?= (int)$m['id'] ?>">Details</button>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

</main>

<footer class="wc-footer">
    <div class="wc-foot-inner">
        <b data-i18n="footerDev">Developed by CATRION &copy; IT Digital &amp; Transformation</b>
        <span>CATRION FIFA World Cup 2026 Challenge</span>
    </div>
</footer>

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

<!-- =====================================================================
     [WC2026 UI ENHANCEMENT] Tap/click flourish on the country boxes.
     100% cosmetic: gold glow pulse + short gold sparks. No data is sent,
     and this is fully separate from the prediction popup script above.
     ===================================================================== -->
<script>
(function(){
    const reduceMotion = window.matchMedia &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Spawn a few gold sparks at the tap point, then auto-remove them.
    function spawnSparks(x, y){
        if (reduceMotion) return;
        const count = 9; // kept low so the page stays fast
        for (let i = 0; i < count; i++){
            const s = document.createElement('span');
            s.className = 'wc-spark';
            const angle = (Math.PI * 2 * i) / count + Math.random() * 0.4;
            const dist  = 26 + Math.random() * 22;
            s.style.left = x + 'px';
            s.style.top  = y + 'px';
            s.style.setProperty('--dx', (Math.cos(angle) * dist).toFixed(1) + 'px');
            s.style.setProperty('--dy', (Math.sin(angle) * dist).toFixed(1) + 'px');
            document.body.appendChild(s);
            s.addEventListener('animationend', () => s.remove());
        }
    }

    document.querySelectorAll('.team[data-team-pick], .orbit-badge[data-globe-pick]').forEach(box => {
        box.addEventListener('click', function(e){
            // restart the gold glow pulse
            this.classList.remove('picked');
            void this.offsetWidth;
            this.classList.add('picked');
            setTimeout(() => this.classList.remove('picked'), 600);
            // sparks at the exact tap/click point
            spawnSparks(e.clientX, e.clientY);
        });
    });
})();
</script>

<!-- ===================== home-style nav chrome (toggles, modals) ===================== -->
<style>
.top-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.theme-switch,.lang-switch{display:inline-flex;gap:4px;padding:4px;border-radius:999px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22)}
.theme-btn,.lang-btn{border:0;border-radius:999px;padding:8px 12px;background:transparent;color:#fff;font-weight:900;font-size:12px;cursor:pointer;font-family:inherit;line-height:1;transition:.2s}
.theme-btn.active,.lang-btn.active{background:#fff;color:var(--deep)}
html[data-theme="saudi"] .theme-btn.active,html[data-theme="saudi"] .lang-btn.active{color:#06371f}
.nav-link.icon-link{display:inline-flex;align-items:center;gap:6px}
.nav-link.icon-link svg{width:16px;height:16px}
button.nav-link{font-family:inherit}

/* modals */
.wc-modal{position:fixed;inset:0;z-index:500;background:rgba(4,18,40,.78);display:none;align-items:center;justify-content:center;padding:22px;backdrop-filter:blur(8px)}
.wc-modal.active{display:flex}
.wc-modal-card{width:100%;max-width:560px;background:linear-gradient(150deg,#0B2C55,#071A35);border:1px solid rgba(168,231,255,.2);border-radius:24px;padding:26px;color:#fff;box-shadow:0 30px 80px rgba(0,0,0,.5);max-height:88vh;overflow:auto}
.wc-modal-kicker{color:var(--cyan);font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
.wc-modal-card h3{margin:0 0 16px;font-size:24px;color:#fff}
.wc-primary-btn{width:100%;border:0;border-radius:14px;padding:12px 18px;font-weight:900;cursor:pointer;background:linear-gradient(135deg,#F5C85B,#FFE19A);color:#071A35}
.wc-profile-line{display:flex;justify-content:space-between;gap:12px;padding:12px 0;border-bottom:1px solid rgba(168,231,255,.14);font-size:14px}
.wc-profile-line:last-of-type{border-bottom:0}
.wc-profile-line span:first-child{color:rgba(255,255,255,.72);font-weight:700}
.wc-profile-line span:last-child{color:#fff;font-weight:900}
.help-list{list-style:none;margin:6px 0 18px;padding:0;display:flex;flex-direction:column;gap:12px}
.help-item{display:flex;gap:12px;align-items:flex-start}
.help-num{flex:none;width:30px;height:30px;border-radius:50%;display:grid;place-items:center;font-weight:900;font-size:13px;color:#06202e;background:linear-gradient(135deg,#F5C85B,#FFE19A)}
.help-item b{display:block;color:#fff;font-size:14px;margin-bottom:2px}
.help-item span{color:rgba(255,255,255,.72);font-size:13px;line-height:1.55}
.pts-table{width:100%;border-collapse:collapse;margin:6px 0 16px}
.pts-table th,.pts-table td{text-align:start;padding:10px 8px;border-bottom:1px solid rgba(168,231,255,.14);font-size:13px}
.pts-table th{color:rgba(255,255,255,.6);font-weight:800;text-transform:uppercase;font-size:11px;letter-spacing:.4px}
.pts-table td{color:#fff;font-weight:700}.pts-table td b{color:#FFE19A;font-weight:900}
.pts-note{color:rgba(255,255,255,.66);font-size:12px;line-height:1.6;margin:0 0 16px}
.ff-frame-wrap{border-radius:18px;overflow:hidden;border:1px solid rgba(168,231,255,.18);background:#05162F;height:74vh}
.ff-frame-wrap iframe{width:100%;height:100%;border:0;display:block}
.wc-modal-card.wide{max-width:min(960px,96vw)}
.wc-modal-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
.wc-x{width:34px;height:34px;border:0;border-radius:10px;cursor:pointer;background:rgba(255,255,255,.12);color:#fff;font-size:15px;font-weight:900}

/* Saudi theme (matches home) */
html[data-theme="saudi"] body{background:radial-gradient(circle at 8% 8%,rgba(34,197,94,.18),transparent 30%),radial-gradient(circle at 92% 8%,rgba(17,163,106,.3),transparent 34%),linear-gradient(180deg,#03190f 0%,#06371f 45%,#03190f 100%) !important}
html[data-theme="saudi"] .hero{background:radial-gradient(circle at 72% 18%,rgba(17,163,106,.4),transparent 32%),linear-gradient(135deg,#03190f 0%,#0a5a32 48%,#0e7c43 100%) !important}
html[data-theme="saudi"] .hero h1 span{color:#FFE19A}
html[data-theme="saudi"] .card,html[data-theme="saudi"] .match-card{background:linear-gradient(135deg,#06291a,#0a3f27 58%,#0e6a3e) !important}
html[data-theme="saudi"] .globe-stage{background:linear-gradient(180deg,#06291a,#0a3f27 60%,#06291a) !important}
html[dir="rtl"] .nav-actions{direction:rtl}
</style>

<!-- Fan Filter pop-up (iframe) -->
<div class="wc-modal" id="ffModal">
    <div class="wc-modal-card wide">
        <div class="wc-modal-head">
            <div class="wc-modal-kicker" data-i18n="navFanFilter">Fan Filter</div>
            <button type="button" class="wc-x" data-close="ffModal" aria-label="Close">✕</button>
        </div>
        <div class="ff-frame-wrap"><iframe id="ffFrame" data-src="/WC/fan_filter.php" title="Fan Filter Studio" referrerpolicy="same-origin"></iframe></div>
    </div>
</div>

<!-- My Profile pop-up -->
<div class="wc-modal" id="profileModal">
    <div class="wc-modal-card">
        <div class="wc-modal-kicker" data-i18n="navProfile">My Profile</div>
        <h3><?= h($name) ?></h3>
        <div class="wc-profile-line"><span data-i18n="pfType">User Type</span><span><?= h($type) ?></span></div>
        <div class="wc-profile-line"><span data-i18n="statOverall">Overall Score</span><span><?= (int)$overallScore ?></span></div>
        <div class="wc-profile-line"><span data-i18n="statWeekly">Weekly Score</span><span><?= (int)$weeklyScore ?></span></div>
        <div class="wc-profile-line"><span data-i18n="statRank">My Rank</span><span><?= h($myRank) ?></span></div>
        <div class="wc-profile-line"><span data-i18n="pfDays">Days Played</span><span><?= (int)$playedDays ?></span></div>
        <br><button type="button" class="wc-primary-btn" data-close="profileModal" data-i18n="close">Close</button>
    </div>
</div>

<!-- How to Use pop-up -->
<div class="wc-modal" id="howToModal">
    <div class="wc-modal-card">
        <div class="wc-modal-kicker" data-i18n="navHowTo">How to Use</div>
        <h3 data-i18n="howToTitle">Get started in 5 steps</h3>
        <ul class="help-list">
            <li class="help-item"><span class="help-num">1</span><div><b data-i18n="howStep1t">Play the Daily Goal Rush</b><span data-i18n="howStep1d">Tap the ball (or press Space) to shoot, beat the goalkeeper, and score in 30 seconds — once per day.</span></div></li>
            <li class="help-item"><span class="help-num">2</span><div><b data-i18n="howStep2t">Predict real matches</b><span data-i18n="howStep2d">Open a match and submit your score prediction before kickoff. Predictions lock when the match starts.</span></div></li>
            <li class="help-item"><span class="help-num">3</span><div><b data-i18n="howStep3t">Create a Fan Filter photo</b><span data-i18n="howStep3d">Pick your country and a frame, snap a selfie or upload a photo, then save & download it.</span></div></li>
            <li class="help-item"><span class="help-num">4</span><div><b data-i18n="howStep4t">Join the Fan Wall</b><span data-i18n="howStep4d">Post your moment, like and comment on others.</span></div></li>
            <li class="help-item"><span class="help-num">5</span><div><b data-i18n="howStep5t">Climb the leaderboard</b><span data-i18n="howStep5d">Collect points from games, predictions and your daily photo to rise up the rankings.</span></div></li>
        </ul>
        <button type="button" class="wc-primary-btn" data-close="howToModal" data-i18n="close">Close</button>
    </div>
</div>

<!-- Points pop-up -->
<div class="wc-modal" id="pointsModal">
    <div class="wc-modal-card">
        <div class="wc-modal-kicker" data-i18n="navPoints">Points</div>
        <h3 data-i18n="pointsTitle">How to collect points</h3>
        <table class="pts-table">
            <thead><tr><th data-i18n="ptsAction">Action</th><th data-i18n="ptsReward">Reward</th></tr></thead>
            <tbody>
                <tr><td data-i18n="ptsGoal">Daily game — score a goal (by zone)</td><td><b>+10 / +20 / +30</b></td></tr>
                <tr><td data-i18n="ptsGolden">Golden ball goal (bonus)</td><td><b>+50</b></td></tr>
                <tr><td data-i18n="ptsPredWin">Predict the match winner</td><td><b>+3</b></td></tr>
                <tr><td data-i18n="ptsPredScore">Predict the correct score</td><td><b>+5</b></td></tr>
                <tr><td data-i18n="ptsChampion">Predict the champion (Final only)</td><td><b>+15</b></td></tr>
                <tr><td data-i18n="ptsPhoto">Fan Filter photo (once per day)</td><td><b>+10</b></td></tr>
            </tbody>
        </table>
        <p class="pts-note" data-i18n="ptsNote">Submit predictions before kickoff — points are awarded automatically once the official result is synced. Save your daily Fan Filter photo for the photo bonus.</p>
        <button type="button" class="wc-primary-btn" data-close="pointsModal" data-i18n="close">Close</button>
    </div>
</div>

<script>
(function(){
  "use strict";
  var d=document, root=document.documentElement;

  /* ---- theme ---- */
  var theme=(function(){ try{ return localStorage.getItem('wc_theme')||'catrion'; }catch(e){ return 'catrion'; } })();
  function applyTheme(t){ theme=(t==='saudi')?'saudi':'catrion'; root.setAttribute('data-theme',theme);
    d.querySelectorAll('.theme-btn').forEach(function(b){ b.classList.toggle('active', b.getAttribute('data-theme-set')===theme); });
    try{ localStorage.setItem('wc_theme',theme); }catch(e){} }
  d.querySelectorAll('.theme-btn').forEach(function(b){ b.addEventListener('click', function(){ applyTheme(b.getAttribute('data-theme-set')); }); });

  /* ---- language (chrome only) ---- */
  var T={
    en:{navHome:'Home',navMatches:'Matches',navFanFilter:'Fan Filter',navProfile:'My Profile',navHowTo:'How to Use',navPoints:'Points',navLogout:'Logout',
      heroBadge:'Reading from Database',heroTitle:'Real Matches.<br><span>Real Results.</span>',
      heroIntro:'Browse the official World Cup 2026 match list, live status, upcoming fixtures, and final results. Submit predictions before kickoff and climb the leaderboard.',
      statTotal:'Total Matches',statTotalNote:'Synced fixtures',statUpcoming:'Upcoming',statUpcomingNote:'Open for predictions',statLive:'Live Now',statLiveNote:'Currently playing',statFinished:'Finished',statFinishedNote:'Results available',
      tabAll:'All',tabUpcoming:'Upcoming',tabLive:'Live',tabFinished:'Finished',searchPh:'Search team or round...',searchBtn:'Search',
      reactionsWord:'reactions',commentsWord:'comments',commentWord:'Comment',
      globeKicker:'WORLD CUP 2026',globeTitle:'Nations on the Pitch',globeP:'Tap a nation to jump to its fixtures.',
      footerDev:'Developed by CATRION © IT Digital & Transformation',close:'Close',
      pfType:'User Type',pfDays:'Days Played',statOverall:'Overall Score',statWeekly:'Weekly Score',statRank:'My Rank',
      howToTitle:'Get started in 5 steps',howStep1t:'Play the Daily Goal Rush',howStep1d:'Tap the ball (or press Space) to shoot, beat the goalkeeper, and score in 30 seconds — once per day.',
      howStep2t:'Predict real matches',howStep2d:'Open a match and submit your score prediction before kickoff. Predictions lock when the match starts.',
      howStep3t:'Create a Fan Filter photo',howStep3d:'Pick your country and a frame, snap a selfie or upload a photo, then save & download it.',
      howStep4t:'Join the Fan Wall',howStep4d:'Post your moment, like and comment on others.',
      howStep5t:'Climb the leaderboard',howStep5d:'Collect points from games, predictions and your daily photo to rise up the rankings.',
      pointsTitle:'How to collect points',ptsAction:'Action',ptsReward:'Reward',ptsGoal:'Daily game — score a goal (by zone)',ptsGolden:'Golden ball goal (bonus)',ptsPredWin:'Predict the match winner',ptsPredScore:'Predict the correct score',ptsChampion:'Predict the champion (Final only)',ptsPhoto:'Fan Filter photo (once per day)',
      ptsNote:'Submit predictions before kickoff — points are awarded automatically once the official result is synced. Save your daily Fan Filter photo for the photo bonus.'},
    ar:{navHome:'الرئيسية',navMatches:'المباريات',navFanFilter:'فلتر المشجع',navProfile:'ملفي',navHowTo:'طريقة الاستخدام',navPoints:'النقاط',navLogout:'خروج',
      heroBadge:'القراءة من قاعدة البيانات',heroTitle:'مباريات حقيقية.<br><span>نتائج حقيقية.</span>',
      heroIntro:'تصفّح قائمة مباريات كأس العالم 2026 الرسمية، والحالة المباشرة، والمباريات القادمة والنتائج النهائية. أرسل توقعاتك قبل انطلاق المباراة وتصدّر لوحة الصدارة.',
      statTotal:'إجمالي المباريات',statTotalNote:'مباريات متزامنة',statUpcoming:'القادمة',statUpcomingNote:'مفتوحة للتوقع',statLive:'مباشر الآن',statLiveNote:'تُلعب حاليًا',statFinished:'منتهية',statFinishedNote:'النتائج متاحة',
      tabAll:'الكل',tabUpcoming:'القادمة',tabLive:'مباشر',tabFinished:'منتهية',searchPh:'ابحث عن فريق أو دور...',searchBtn:'بحث',
      reactionsWord:'تفاعلات',commentsWord:'تعليقات',commentWord:'تعليق',
      globeKicker:'كأس العالم 2026',globeTitle:'المنتخبات في الملعب',globeP:'انقر منتخبًا للانتقال إلى مبارياته.',
      footerDev:'تطوير كاتريون © تقنية المعلومات والتحول الرقمي',close:'إغلاق',
      pfType:'نوع المستخدم',pfDays:'أيام اللعب',statOverall:'النقاط الإجمالية',statWeekly:'نقاط الأسبوع',statRank:'ترتيبي',
      howToTitle:'ابدأ في 5 خطوات',howStep1t:'العب تحدي الأهداف اليومي',howStep1d:'انقر الكرة (أو اضغط مسافة) للتسديد، تجاوز الحارس، وسجّل خلال 30 ثانية — مرة واحدة يوميًا.',
      howStep2t:'توقّع المباريات الحقيقية',howStep2d:'افتح مباراة وأرسل توقع النتيجة قبل انطلاقها. تُقفل التوقعات عند بدء المباراة.',
      howStep3t:'أنشئ صورة فلتر المشجع',howStep3d:'اختر دولتك وإطارًا، التقط صورة أو ارفع واحدة، ثم احفظها ونزّلها.',
      howStep4t:'انضم إلى جدار المشجعين',howStep4d:'انشر لحظتك وتفاعل وعلّق على الآخرين.',
      howStep5t:'تصدّر لوحة الصدارة',howStep5d:'اجمع النقاط من الألعاب والتوقعات وصورتك اليومية لترتقي في التصنيف.',
      pointsTitle:'كيف تجمع النقاط',ptsAction:'الإجراء',ptsReward:'المكافأة',ptsGoal:'اللعبة اليومية — تسجيل هدف (حسب المنطقة)',ptsGolden:'هدف الكرة الذهبية (مكافأة)',ptsPredWin:'توقّع الفائز بالمباراة',ptsPredScore:'توقّع النتيجة الصحيحة',ptsChampion:'توقّع البطل (النهائي فقط)',ptsPhoto:'صورة فلتر المشجع (مرة يوميًا)',
      ptsNote:'أرسل التوقعات قبل انطلاق المباراة — تُمنح النقاط تلقائيًا بعد مزامنة النتيجة الرسمية. احفظ صورة فلتر المشجع اليومية للحصول على مكافأة الصورة.'}
  };
  var lang=(function(){ try{ return localStorage.getItem('wc_lang')||'en'; }catch(e){ return 'en'; } })();
  function tr(k){ return (T[lang]&&T[lang][k]!=null)?T[lang][k]:(T.en[k]!=null?T.en[k]:k); }
  function known(k){ return T.en[k]!=null; }
  function applyLang(l){
    lang=(l==='ar')?'ar':'en'; root.lang=lang; root.dir=(lang==='ar')?'rtl':'ltr';
    d.querySelectorAll('[data-i18n]').forEach(function(el){ var k=el.getAttribute('data-i18n'); if(known(k)) el.textContent=tr(k); });
    d.querySelectorAll('[data-i18n-html]').forEach(function(el){ var k=el.getAttribute('data-i18n-html'); if(known(k)) el.innerHTML=tr(k); });
    d.querySelectorAll('[data-i18n-ph]').forEach(function(el){ var k=el.getAttribute('data-i18n-ph'); if(known(k)) el.setAttribute('placeholder',tr(k)); });
    d.querySelectorAll('.lang-btn').forEach(function(b){ b.classList.toggle('active', b.getAttribute('data-lang-set')===lang); });
    try{ localStorage.setItem('wc_lang',lang); }catch(e){}
  }
  d.querySelectorAll('.lang-btn').forEach(function(b){ b.addEventListener('click', function(){ applyLang(b.getAttribute('data-lang-set')); }); });

  /* ---- modals ---- */
  function open(id){ var m=d.getElementById(id); if(m) m.classList.add('active'); }
  function close(id){ var m=d.getElementById(id); if(m) m.classList.remove('active'); }
  var ffFrame=d.getElementById('ffFrame');
  var oFF=d.getElementById('openFanFilterBtn'); if(oFF) oFF.addEventListener('click', function(){ if(ffFrame&&!ffFrame.src) ffFrame.src=ffFrame.dataset.src; open('ffModal'); });
  var oP=d.getElementById('openProfileBtn'); if(oP) oP.addEventListener('click', function(){ open('profileModal'); });
  var oH=d.getElementById('howToBtn'); if(oH) oH.addEventListener('click', function(){ open('howToModal'); });
  var oPt=d.getElementById('pointsBtn'); if(oPt) oPt.addEventListener('click', function(){ open('pointsModal'); });
  d.querySelectorAll('[data-close]').forEach(function(b){ b.addEventListener('click', function(){ close(b.getAttribute('data-close')); }); });
  d.querySelectorAll('.wc-modal').forEach(function(m){ m.addEventListener('click', function(e){ if(e.target===m) m.classList.remove('active'); }); });
  d.addEventListener('keydown', function(e){ if(e.key==='Escape') d.querySelectorAll('.wc-modal.active').forEach(function(m){ m.classList.remove('active'); }); });

  applyTheme(theme); applyLang(lang);
})();
</script>

</body>
</html>
