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
$matchPredictCounts = [];
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
    foreach (wc_rows($conn, "SELECT match_id, COUNT(*) AS c FROM WC2026_Predictions WHERE match_id IN ($inList) GROUP BY match_id") as $r) {
        $matchPredictCounts[(int)$r['match_id']] = (int)$r['c'];
    }
}

/* ---- Native Fan Filter Studio data (mirrors home.php; saves to /WC/api/save_filter_photo.php) ---- */
if (!function_exists('wc_asset_path')) {
    function wc_asset_path($path): string {
        $path = trim((string)$path);
        if ($path === '') return '';
        if (preg_match('#^https?://#i', $path)) return $path;
        if (str_starts_with($path, '/WC/') || str_starts_with($path, '/WC2026/')) return $path;
        if (str_starts_with($path, 'WC/') || str_starts_with($path, 'WC2026/')) return '/' . $path;
        return '/WC/' . ltrim($path, '/');
    }
}
if (!function_exists('wc_platform_logo_path')) {
    function wc_platform_logo_path($code): string {
        $code = strtolower(trim((string)$code));
        if (str_contains($code, 'instagram') || $code === 'insta') return '/WC/assets/filters/platforms/instagram.png';
        if (str_contains($code, 'snapchat') || $code === 'snap') return '/WC/assets/filters/platforms/snapchat.png';
        if (str_contains($code, 'linkedin') || str_contains($code, 'linked')) return '/WC/assets/filters/platforms/linkedin.png';
        return '';
    }
}
if (!function_exists('wc_platform_logo_fallback')) {
    function wc_platform_logo_fallback($code): string {
        $code = strtolower(trim((string)$code));
        if (str_contains($code, 'instagram') || $code === 'insta') return 'IG';
        if (str_contains($code, 'snapchat') || $code === 'snap') return 'SC';
        if (str_contains($code, 'linkedin') || str_contains($code, 'linked')) return 'in';
        return '•';
    }
}
$ffPlatforms = wc_rows($conn, "
    SELECT id, platform_name, platform_code, width, height
    FROM WC2026_Filter_Platforms
    WHERE status='Active'
    ORDER BY sort_order ASC, id ASC
");
$ffSelectedPlatform = $ffPlatforms[0] ?? null;
$ffFrames = wc_rows($conn, "
    SELECT id, platform_id, frame_name, frame_path, preview_path
    FROM WC2026_Filter_Frames
    WHERE status='Active' AND platform_id IS NOT NULL
    ORDER BY platform_id ASC, sort_order ASC, id ASC
");
$ffCountries = wc_rows($conn, "
    SELECT id, country_name, country_code, flag_path
    FROM WC2026_Filter_Countries
    WHERE status='Active'
    ORDER BY sort_order ASC, country_name ASC
");
$ffSelectedCountry = $ffCountries[0] ?? null;

/* Team flags come from WC2026_Filter_Countries (single source of truth). Reuse
   the rows already loaded above to build name/code maps for the match cards. */
require_once __DIR__ . '/_flags.php';
$wcFlagMaps  = wc_flag_maps_from_rows($ffCountries);
$wcFlagByName = $wcFlagMaps['byName'];
$wcFlagByCode = $wcFlagMaps['byCode'];
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

<!-- Leaflet (native Participating Teams Map) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">

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
    margin-top:12px;
    padding:12px 38px;
    background:linear-gradient(135deg,#071A35,#0B2C55);
    color:#fff;
}
.wc-foot-inner{
    max-width:1680px;
    margin:0 auto;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
}
.wc-foot-inner b{font-size:12px;font-weight:900}
.wc-foot-inner span{font-size:11px;font-weight:700;color:rgba(255,255,255,.7)}
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
/* Header to match home.php (same banner size: tall hero, content below it) */
.hero{
    min-height:405px !important;
    padding:24px 38px 44px !important;
    background:
        radial-gradient(circle at 72% 18%, rgba(14,99,230,.34), transparent 32%),
        radial-gradient(circle at 15% 28%, rgba(85,183,255,.16), transparent 30%),
        linear-gradient(135deg,#04142B 0%,#08254D 48%,#0E63E6 100%) !important;
}
/* sit content below the banner (no overlap) — like home */
.container{margin-top:22px !important}
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
.msm-predict{color:#A8E7FF}
@media(max-width:640px){.hero{min-height:340px !important;padding:18px 14px 40px !important}}
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
            </div>
        </div>

        <button type="button" class="nav-burger" id="navBurger" aria-label="Menu" aria-expanded="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
        </button>

        <div class="nav-actions top-actions" id="topActions">
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
            <a href="/WC2026/teams-map/" class="nav-link" data-i18n="navTeamsMap">Teams Map</a>
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
    <!-- [WC2026] Participating Teams Map — native interactive Leaflet map (same as home.php) -->
    <?php require_once __DIR__ . '/_teams_map.php';
        $teamsMapNations = wc_countries_nations();
        if (!$teamsMapNations) { $teamsMapNations = wc_teams_map_nations($conn); }
        if (!$teamsMapNations) { $teamsMapNations = wc_tm_all_nations(); }
    ?>
    <section class="card teams-map-card" id="teamsMapCard" aria-label="World Cup 2026 nations map">
        <div class="tm-head">
            <div class="tm-title"><span class="tm-dot"></span> <span data-i18n="teamsMapTitle">Participating Teams Map</span></div>
            <a href="/WC2026/teams-map/" target="_blank" rel="noopener" class="tm-open" data-i18n="openTeamsMap">Open Full Map ↗</a>
        </div>
        <div class="tm-sub" data-i18n="teamsMapSub"><?= count($teamsMapNations) ?> qualified nations on a real world map — tap any country to jump to its fixtures.</div>
        <div class="tm-frame-wrap">
            <div id="teamsLeafletMap" class="tm-leaflet" aria-label="Participating teams map"></div>
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
                        // Flag source priority: DB logo -> WC2026_Filter_Countries.flag_path -> flagcdn.
                        $homeFlagSrc = !empty($m['home_logo']) ? (string)$m['home_logo']
                            : wc_asset_path(wc_flag((string)($m['home_team'] ?? ''), $wcFlagByName, $wcFlagByCode));
                        $awayFlagSrc = !empty($m['away_logo']) ? (string)$m['away_logo']
                            : wc_asset_path(wc_flag((string)($m['away_team'] ?? ''), $wcFlagByName, $wcFlagByCode));
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
                                    <?php if ($homeFlagSrc !== ''): ?>
                                        <img class="team-flag" src="<?= h($homeFlagSrc) ?>" alt="<?= h($m['home_team']) ?>" loading="lazy"
                                             onerror="this.style.display='none';this.nextElementSibling.style.display='grid';">
                                        <span class="team-flag-fallback" aria-hidden="true" style="display:none;"><?= $homeEmoji !== '' ? $homeEmoji : '⚽' ?></span>
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
                                    <?php if ($awayFlagSrc !== ''): ?>
                                        <img class="team-flag" src="<?= h($awayFlagSrc) ?>" alt="<?= h($m['away_team']) ?>" loading="lazy"
                                             onerror="this.style.display='none';this.nextElementSibling.style.display='grid';">
                                        <span class="team-flag-fallback" aria-hidden="true" style="display:none;"><?= $awayEmoji !== '' ? $awayEmoji : '⚽' ?></span>
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

                        <?php $rcCount = (int)($matchReactCounts[(int)$m['id']] ?? 0); $ccCount = (int)($matchCommentCounts[(int)$m['id']] ?? 0); $pcCount = (int)($matchPredictCounts[(int)$m['id']] ?? 0); ?>
                        <button type="button" class="match-social-mini" data-predict-url="/WC2026/predict?match=<?= (int)$m['id'] ?>&view=details" title="Open reactions, comments &amp; predictions">
                            <span class="msm-pill msm-react">🔥 <b><?= $rcCount ?></b> <span data-i18n="reactionsWord">reactions</span></span>
                            <?php if ($ccCount > 0): ?>
                                <span class="msm-pill msm-comment">💬 <b><?= $ccCount ?></b> <span data-i18n="commentsWord">comments</span></span>
                            <?php else: ?>
                                <span class="msm-pill msm-comment muted">💬 <span data-i18n="commentWord">Comment</span></span>
                            <?php endif; ?>
                            <span class="msm-pill msm-predict">🎯 <b><?= $pcCount ?></b> <span data-i18n="predictionsWord">predictions</span></span>
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
                            <button type="button" class="details-btn" data-predict-url="/WC2026/predict?match=<?= (int)$m['id'] ?>&view=details">Details</button>
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
.top-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;position:relative;z-index:31}
.nav{position:relative;z-index:30}
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

/* [WC2026] Participating Teams Map card */
.teams-map-card{padding:22px}
/* Participating Teams Map — dark navy, cohesive with the map inside */
.card.teams-map-card{
    background:
        radial-gradient(circle at 100% 0%, rgba(14,99,230,.22), transparent 36%),
        linear-gradient(135deg,#061A36 0%,#08254D 58%,#0A3A76 100%) !important;
    border:1px solid rgba(168,231,255,.20) !important;color:#EAF4FF !important;
    box-shadow:0 28px 70px rgba(0,0,0,.30) !important;
}
.tm-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:4px;flex-wrap:wrap}
.tm-title{color:#FFFFFF !important;font-weight:900;font-size:16px;display:flex;align-items:center;gap:8px}
.tm-dot{width:10px;height:10px;border-radius:50%;background:#7EF4AE;box-shadow:0 0 12px rgba(126,244,174,.8)}
.tm-open{color:#A8E7FF !important;text-decoration:none;font-weight:800;font-size:13px;white-space:nowrap}
.tm-open:hover{text-decoration:underline}
.tm-sub{color:rgba(234,244,255,.82);font-weight:700;font-size:13px;margin:2px 0 14px;line-height:1.6}
.tm-frame-wrap{position:relative;border-radius:20px;overflow:hidden;border:1px solid rgba(168,231,255,.16);background:radial-gradient(120% 120% at 50% 0%,#123a6b 0%,#0a1f3e 55%,#06152c 100%);height:560px}
.tm-frame{width:100%;height:100%;border:0;display:block}
.tm-leaflet{width:100%;height:100%;z-index:1}
.tm-leaflet .leaflet-container{background:radial-gradient(120% 120% at 50% 0%,#123a6b 0%,#0a1f3e 55%,#06152c 100%)}
.tm-leaflet .leaflet-control-attribution{background:rgba(6,26,54,.7);color:rgba(255,255,255,.55)}
.tm-leaflet .leaflet-control-attribution a{color:rgba(168,231,255,.8)}
.tm-leaflet .leaflet-popup-content-wrapper{background:#0B2C55;color:#fff;border:1px solid rgba(168,231,255,.22);border-radius:14px}
.tm-leaflet .leaflet-popup-tip{background:#0B2C55}
.tm-leaflet .leaflet-popup-content{font-weight:800;font-size:13px;margin:10px 14px}
.tm-leaflet .leaflet-popup-content a{color:#A8E7FF;font-weight:900;text-decoration:none}
/* Rich team popup — football story, stars, key moments */
.leaflet-popup.tm-pop-wrap .leaflet-popup-content-wrapper{background:#0B2C55;border:1px solid rgba(168,231,255,.22);border-radius:16px}
.leaflet-popup.tm-pop-wrap .leaflet-popup-tip{background:#0B2C55}
.tm-pop{color:#fff;font-size:12.5px;line-height:1.55}
.tm-pop-head{display:flex;align-items:center;gap:10px;margin-bottom:8px}
.tm-pop-flag{width:34px;height:23px;object-fit:cover;border-radius:4px;box-shadow:0 2px 6px rgba(0,0,0,.4);flex:none}
.tm-pop-title{display:flex;flex-direction:column;gap:2px}
.tm-pop-title strong{font-size:15px;font-weight:900;color:#fff}
.tm-pop-confed{font-size:10px;font-weight:800;letter-spacing:.04em;color:#06202e;background:#A8E7FF;border-radius:999px;padding:1px 8px;width:fit-content}
.tm-pop-story{margin:0 0 9px;color:rgba(255,255,255,.86);font-weight:600}
.tm-pop-sec{margin:0 0 8px}
.tm-pop-lbl{display:block;font-size:11px;font-weight:900;color:#F5C85B;margin-bottom:4px;letter-spacing:.02em}
.tm-pop-chips{display:flex;flex-wrap:wrap;gap:5px}
.tm-pop-chip{font-size:11px;font-weight:800;color:#eaf6ff;background:rgba(168,231,255,.14);border:1px solid rgba(168,231,255,.2);border-radius:999px;padding:2px 9px}
.tm-pop-list{margin:0;padding-inline-start:16px;color:rgba(255,255,255,.82);font-weight:600}
.tm-pop-list li{margin:1px 0}
.tm-pop-link{display:inline-block;margin-top:6px;color:#A8E7FF !important;font-weight:900;text-decoration:none}
html[data-theme="saudi"] .tm-pop-confed{background:#7EF4AE}
html[data-theme="saudi"] .tm-pop-link{color:#7EF4AE !important}
html[data-theme="saudi"] .tm-pop-lbl{color:#7EF4AE}
.tm-pin{border:2px solid rgba(255,255,255,.9);border-radius:50%;overflow:hidden;background:#0B2C55;box-shadow:0 3px 10px rgba(0,0,0,.5)}
.tm-pin img{width:100%;height:100%;object-fit:cover;display:block}
html[dir="rtl"] .tm-sub{text-align:right}
html[data-theme="saudi"] .tm-frame-wrap{border-color:rgba(126,244,174,.22)}
@media(max-width:768px){.tm-frame-wrap{height:460px}}

/* ===== Native Fan Filter studio (scoped to #ffModal) ===== */
#ffModal .wc-modal-card.ff-native{max-width:min(1080px,96vw);width:96vw;max-height:92vh;overflow:auto;background:#fff !important;color:#102033}
#ffModal .ff-native .wc-modal-kicker{color:#0E63E6}
#ffModal .fanfilter-modal-tools{display:flex;align-items:center;gap:10px}
#ffModal .ff-openfull{color:#0E63E6;text-decoration:none;font-weight:800;font-size:13px;white-space:nowrap}
#ffModal .ff-openfull:hover{text-decoration:underline}
#ffModal .ffstudio{margin-top:6px}
#ffModal .empty{padding:20px;border-radius:18px;background:#FFF8E7;border:1px solid #F6DEA5;color:#6C520B;font-weight:800;line-height:1.6}
#ffModal .studio-layout{display:grid;grid-template-columns:minmax(300px,440px) 1fr;gap:18px;align-items:start}
#ffModal .preview-card,#ffModal .options-card{min-width:0;border:1px solid #E1ECF8;background:#FBFDFF;border-radius:24px;padding:16px}
#ffModal .section-label{font-size:12px;color:#71839A;font-weight:900;text-transform:uppercase;letter-spacing:.6px;margin:2px 0 10px}
#ffModal .camera-wrap{width:100%;max-width:430px;margin:0 auto;border-radius:24px;background:#071A35;overflow:hidden;position:relative;box-shadow:0 18px 50px rgba(7,26,53,.25);aspect-ratio:4/5}
#ffModal #video,#ffModal #canvas,#ffModal #previewImage,#ffModal #liveFrameOverlay{width:100%;height:100%;object-fit:cover;display:none}
#ffModal #video,#ffModal #canvas,#ffModal #previewImage{position:relative;z-index:1}
#ffModal #previewImage{z-index:3}
#ffModal #liveFrameOverlay{position:absolute;inset:0;z-index:4;pointer-events:none;object-fit:fill}
#ffModal #liveFlagOverlay{position:absolute;z-index:6;pointer-events:none;display:none;flex-direction:column;align-items:center;justify-content:flex-start}
#ffModal #liveFlagBox{width:100%;background:#fff;box-shadow:0 8px 24px rgba(0,0,0,.25);display:flex;align-items:center;justify-content:center;overflow:hidden}
#ffModal #liveFlagImage{width:100%;height:100%;object-fit:cover;display:block}
#ffModal #liveFlagCode{display:block;color:#fff;font-weight:900;line-height:1.05;text-align:center;text-shadow:0 3px 8px rgba(0,0,0,.45);white-space:nowrap}
#ffModal .camera-empty{position:absolute;inset:0;z-index:5;display:grid;place-items:center;color:rgba(255,255,255,.78);text-align:center;padding:22px}
#ffModal .camera-empty b{display:block;color:#fff;font-size:22px;margin-bottom:8px}
#ffModal .controls{margin:16px auto 0;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
#ffModal .btn{border:0;border-radius:999px;padding:12px 16px;font-family:inherit;font-weight:900;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:46px;text-align:center}
#ffModal .btn-primary{background:linear-gradient(135deg,#0E63E6,#0847B8);color:#fff}
#ffModal .btn-gold{background:linear-gradient(135deg,#F5C85B,#E7A90C);color:#2A2105}
#ffModal .btn-soft{background:#EEF5FC;color:#0B2C55}
#ffModal .btn-green{background:linear-gradient(135deg,#15B97A,#0B8E5C);color:#fff}
#ffModal .btn:disabled{opacity:.45;cursor:not-allowed}
#ffModal .upload-input{display:none}
#ffModal .flip-camera-btn{display:none}
#ffModal .flip-camera-btn.show{display:inline-flex}
#ffModal .option-block{background:#fff;border:1px solid #E8F0FA;border-radius:20px;padding:14px;box-shadow:0 10px 24px rgba(7,26,53,.05)}
#ffModal .option-block + .option-block{margin-top:14px}
#ffModal .platform-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
#ffModal .platform-choice{border:2px solid transparent;background:#fff;border-radius:18px;padding:12px 8px;cursor:pointer;min-height:100px;box-shadow:0 10px 24px rgba(7,26,53,.06);text-align:center;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:7px}
#ffModal .platform-choice.active{border-color:#0E63E6;background:linear-gradient(180deg,#fff,#F7FBFF)}
#ffModal .platform-logo{width:40px;height:40px;border-radius:12px;object-fit:contain;background:#fff;padding:5px;box-shadow:0 10px 22px rgba(7,26,53,.12)}
#ffModal .platform-logo-fallback{width:40px;height:40px;border-radius:12px;display:none;align-items:center;justify-content:center;color:#fff;font-size:17px;font-weight:900;background:linear-gradient(135deg,#0E63E6,#0B2C55)}
#ffModal .platform-text strong{display:block;color:#0B2C55;font-size:12px;font-weight:900}
#ffModal .platform-text span{display:block;color:#71839A;font-size:11px;font-weight:800;margin-top:4px}
#ffModal .country-picker{position:relative}
#ffModal .country-trigger{width:100%;border:2px solid #E1ECF8;background:#fff;border-radius:18px;padding:11px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:12px}
#ffModal .country-trigger.active{border-color:#0E63E6}
#ffModal .country-selected{display:flex;align-items:center;gap:12px;min-width:0}
#ffModal .country-selected img{width:50px;height:50px;object-fit:cover;border-radius:14px;background:#EEF5FC;flex:0 0 auto}
#ffModal .country-name{display:block;color:#0B2C55;font-weight:900;line-height:1.15;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#ffModal .country-code{display:block;color:#71839A;font-size:12px;font-weight:800;margin-top:4px}
#ffModal .country-arrow{color:#0B2C55;font-weight:900;flex:0 0 auto}
#ffModal .country-menu{display:none;position:absolute;z-index:30;top:calc(100% + 8px);left:0;right:0;background:#fff;border:1px solid #DDE9F6;border-radius:20px;box-shadow:0 24px 60px rgba(7,26,53,.18);overflow:hidden}
#ffModal .country-menu.show{display:block}
#ffModal .country-search-wrap{padding:10px;border-bottom:1px solid #EDF3FA}
#ffModal .country-search{width:100%;border:1px solid #DDE9F6;background:#F7FBFF;border-radius:14px;padding:11px 13px;font-family:inherit;font-weight:800;color:#0B2C55;outline:none;font-size:15px}
#ffModal .country-options{max-height:280px;overflow:auto;padding:7px;-webkit-overflow-scrolling:touch}
#ffModal .country-option{width:100%;border:0;background:#fff;border-radius:14px;padding:9px;cursor:pointer;display:flex;align-items:center;gap:12px;text-align:left}
#ffModal .country-option:hover,#ffModal .country-option.active{background:#EEF5FC}
#ffModal .country-option img{width:40px;height:40px;object-fit:cover;border-radius:12px;background:#EEF5FC;flex:0 0 auto}
#ffModal .no-results{display:none;padding:16px;color:#71839A;font-weight:800;text-align:center}
#ffModal .frame-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
#ffModal .frame-choice{border:2px solid transparent;background:#fff;border-radius:18px;padding:9px;cursor:pointer;min-height:118px;box-shadow:0 10px 24px rgba(7,26,53,.06)}
#ffModal .frame-choice.active{border-color:#0E63E6}
#ffModal .frame-choice img{width:100%;height:76px;object-fit:cover;border-radius:13px;background:#EEF5FC}
#ffModal .frame-choice span{display:block;margin-top:7px;font-size:12px;font-weight:900;color:#0B2C55;text-align:center}
#ffModal .selection-summary{margin-top:14px;border-radius:18px;padding:13px;background:linear-gradient(135deg,#F7FBFF,#EEF5FC);border:1px solid #DDE9F6;color:#0B2C55;font-size:13px;font-weight:800;line-height:1.6}
#ffModal .message{display:none;margin:14px auto 0;border-radius:16px;padding:12px 14px;font-weight:800;font-size:14px}
#ffModal .message.ok{display:block;background:#E9FFF5;color:#08764B;border:1px solid #BDF1DA}
#ffModal .message.err{display:block;background:#FFF1F1;color:#B42318;border:1px solid #FFD0D0}
@media(max-width:820px){#ffModal .studio-layout{grid-template-columns:1fr}#ffModal .platform-row,#ffModal .frame-row{display:flex;overflow-x:auto;gap:10px;padding-bottom:6px}#ffModal .platform-choice{min-width:140px}#ffModal .frame-choice{min-width:140px}}
</style>

<!-- Fan Filter Studio pop-up — NATIVE studio (camera + platform/country/frame), saves to /WC/api/save_filter_photo.php -->
<div class="wc-modal" id="ffModal">
    <div class="wc-modal-card wide ff-native">
        <div class="wc-modal-head">
            <div class="wc-modal-kicker" data-i18n="navFanFilter">Fan Filter Studio</div>
            <div class="fanfilter-modal-tools">
                <a href="/WC/fan_filter.php" target="_blank" rel="noopener" class="ff-openfull" data-i18n="openFull">Open full page</a>
                <button type="button" class="wc-x" data-close="ffModal" aria-label="Close">✕</button>
            </div>
        </div>

        <div class="ffstudio">
        <?php if (empty($ffPlatforms) || empty($ffCountries) || empty($ffFrames)): ?>
            <div class="empty">
                Please add active platforms, countries and frames in
                <strong>WC2026_Filter_Platforms</strong>, <strong>WC2026_Filter_Countries</strong>
                and <strong>WC2026_Filter_Frames</strong>.
            </div>
        <?php else: ?>
            <div class="studio-layout">
                <section class="preview-card">
                    <div class="section-label">Photo Preview</div>
                    <div class="camera-wrap">
                        <video id="video" autoplay playsinline muted></video>
                        <img id="liveFrameOverlay" alt="Frame Overlay">
                        <div id="liveFlagOverlay" aria-hidden="true">
                            <div id="liveFlagBox"><img id="liveFlagImage" alt=""></div>
                            <span id="liveFlagCode"></span>
                        </div>
                        <canvas id="canvas" width="1080" height="1350"></canvas>
                        <img id="previewImage" alt="Preview">
                        <div class="camera-empty" id="cameraEmpty"><div><b>Ready to create?</b>Start the camera or upload a photo.</div></div>
                    </div>
                    <div class="controls">
                        <button type="button" class="btn btn-primary" id="startCameraBtn">📷 Start Camera</button>
                        <button type="button" class="btn btn-soft flip-camera-btn" id="flipCameraBtn">🔄 Flip Camera</button>
                        <label class="btn btn-soft" for="uploadPhoto">⬆️ Upload Photo</label>
                        <input class="upload-input" type="file" id="uploadPhoto" accept="image/*">
                        <button type="button" class="btn btn-gold" id="captureBtn" disabled>⚽ Capture</button>
                        <button type="button" class="btn btn-soft" id="retakeBtn" disabled>↩️ Retake</button>
                        <button type="button" class="btn btn-green" id="saveDownloadBtn" disabled style="grid-column:1 / -1;">⚽ Save &amp; Download</button>
                    </div>
                    <div class="message" id="messageBox"></div>
                </section>

                <aside class="options-card">
                    <div class="option-block">
                        <div class="section-label">Choose Platform / Size</div>
                        <div class="platform-row" id="platformList">
                            <?php foreach ($ffPlatforms as $index => $platform): ?>
                                <?php $plogo = wc_platform_logo_path($platform['platform_code'] ?? ''); $pfb = wc_platform_logo_fallback($platform['platform_code'] ?? ''); ?>
                                <button type="button" class="platform-choice <?= $index === 0 ? 'active' : '' ?>"
                                    data-platform-id="<?= (int)$platform['id'] ?>" data-platform-name="<?= h($platform['platform_name']) ?>"
                                    data-platform-code="<?= h($platform['platform_code']) ?>" data-width="<?= (int)$platform['width'] ?>" data-height="<?= (int)$platform['height'] ?>">
                                    <?php if ($plogo !== ''): ?><img class="platform-logo" src="<?= h($plogo) ?>" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"><?php endif; ?>
                                    <span class="platform-logo-fallback" <?= $plogo === '' ? 'style="display:flex;"' : '' ?>><?= h($pfb) ?></span>
                                    <span class="platform-text"><strong><?= h($platform['platform_name']) ?></strong><span><?= (int)$platform['width'] ?> × <?= (int)$platform['height'] ?></span></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="option-block">
                        <div class="section-label">Choose Country</div>
                        <div class="country-picker" id="countryPicker">
                            <button type="button" class="country-trigger active" id="countryTrigger">
                                <span class="country-selected">
                                    <img id="selectedCountryFlag" src="<?= h(wc_asset_path($ffSelectedCountry['flag_path'] ?? '')) ?>" alt="">
                                    <span style="min-width:0;">
                                        <span class="country-name" id="selectedCountryName"><?= h($ffSelectedCountry['country_name'] ?? 'Choose Country') ?></span>
                                        <span class="country-code" id="selectedCountryCode"><?= h($ffSelectedCountry['country_code'] ?? '') ?></span>
                                    </span>
                                </span>
                                <span class="country-arrow">⌄</span>
                            </button>
                            <div class="country-menu" id="countryMenu">
                                <div class="country-search-wrap"><input type="text" class="country-search" id="countrySearch" placeholder="Search country..."></div>
                                <div class="country-options" id="countryOptions">
                                    <?php foreach ($ffCountries as $index => $country): ?>
                                        <button type="button" class="country-option country-choice <?= $index === 0 ? 'active' : '' ?>"
                                            data-country-id="<?= (int)$country['id'] ?>" data-country-name="<?= h($country['country_name']) ?>"
                                            data-country-code="<?= h($country['country_code']) ?>" data-flag="<?= h(wc_asset_path($country['flag_path'])) ?>"
                                            data-search="<?= h(strtolower($country['country_name'] . ' ' . $country['country_code'])) ?>">
                                            <img src="<?= h(wc_asset_path($country['flag_path'])) ?>" alt="">
                                            <span><span class="country-name"><?= h($country['country_name']) ?></span><span class="country-code"><?= h($country['country_code']) ?></span></span>
                                        </button>
                                    <?php endforeach; ?>
                                    <div class="no-results" id="noCountryResults">No countries found.</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="option-block">
                        <div class="section-label">Choose Frame</div>
                        <div class="frame-row" id="frameList">
                            <?php $firstFrameSet = false; ?>
                            <?php foreach ($ffFrames as $frame): ?>
                                <?php
                                    $preview = $frame['preview_path'] ?: $frame['frame_path'];
                                    $isSel = $ffSelectedPlatform && (int)$frame['platform_id'] === (int)$ffSelectedPlatform['id'];
                                    $isActive = $isSel && !$firstFrameSet; if ($isActive) $firstFrameSet = true;
                                ?>
                                <button type="button" class="frame-choice <?= $isActive ? 'active' : '' ?>"
                                    data-frame-id="<?= (int)$frame['id'] ?>" data-platform-id="<?= (int)$frame['platform_id'] ?>" data-frame-name="<?= h($frame['frame_name']) ?>"
                                    data-frame="<?= h(wc_asset_path($frame['frame_path'])) ?>" style="<?= $isSel ? '' : 'display:none;' ?>">
                                    <img src="<?= h(wc_asset_path($preview)) ?>" alt=""><span><?= h($frame['frame_name']) ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="selection-summary" id="selectionSummary">
                        Selected platform: <strong><?= h($ffSelectedPlatform['platform_name'] ?? '-') ?></strong><br>
                        Selected country: <strong><?= h($ffSelectedCountry['country_name'] ?? '-') ?></strong><br>
                        Selected frame: <strong>-</strong>
                    </div>
                </aside>
            </div>
        <?php endif; ?>
        </div>
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
                <tr><td data-i18n="ptsCombo">Combo streak (every 3 / 5 goals)</td><td><b>+20 / +50</b></td></tr>
                <tr><td data-i18n="ptsMystery">Daily food bonus roll</td><td><b>+10 → +100</b></td></tr>
                <tr><td data-i18n="ptsPredWin">Predict the match winner</td><td><b>+300</b></td></tr>
                <tr><td data-i18n="ptsPredScore">Predict the correct score</td><td><b>+500</b></td></tr>
                <tr><td data-i18n="ptsChampion">Predict the champion (Final only)</td><td><b>+5,000</b></td></tr>
                <tr><td data-i18n="ptsPhoto">Fan Filter photo (once per day)</td><td><b>+1,000</b></td></tr>
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
    en:{navHome:'Home',navMatches:'Matches',navTeamsMap:'Teams Map',navFanFilter:'Fan Filter',navProfile:'My Profile',navHowTo:'How to Use',navPoints:'Points',navLogout:'Logout',
      heroBadge:'Reading from Database',heroTitle:'Real Matches.<br><span>Real Results.</span>',
      heroIntro:'Browse the official World Cup 2026 match list, live status, upcoming fixtures, and final results. Submit predictions before kickoff and climb the leaderboard.',
      statTotal:'Total Matches',statTotalNote:'Synced fixtures',statUpcoming:'Upcoming',statUpcomingNote:'Open for predictions',statLive:'Live Now',statLiveNote:'Currently playing',statFinished:'Finished',statFinishedNote:'Results available',
      tabAll:'All',tabUpcoming:'Upcoming',tabLive:'Live',tabFinished:'Finished',searchPh:'Search team or round...',searchBtn:'Search',
      reactionsWord:'reactions',commentsWord:'comments',commentWord:'Comment',predictionsWord:'predictions',
      globeKicker:'WORLD CUP 2026',globeTitle:'Nations on the Pitch',globeP:'Tap a nation to jump to its fixtures.',
      teamsMapTitle:'Participating Teams Map',openTeamsMap:'Open Full Map ↗',teamsMapSub:'Explore all 48 qualified nations on a real world map — tap any country for its football story, stars and key moments.',
      footerDev:'Developed by CATRION © IT Digital & Transformation',close:'Close',
      pfType:'User Type',pfDays:'Days Played',statOverall:'Overall Score',statWeekly:'Weekly Score',statRank:'My Rank',
      howToTitle:'Get started in 5 steps',howStep1t:'Play the Daily Goal Rush',howStep1d:'Tap the ball (or press Space) to shoot, beat the goalkeeper, and score in 30 seconds — once per day.',
      howStep2t:'Predict real matches',howStep2d:'Open a match and submit your score prediction before kickoff. Predictions lock when the match starts.',
      howStep3t:'Create a Fan Filter photo',howStep3d:'Pick your country and a frame, snap a selfie or upload a photo, then save & download it.',
      howStep4t:'Join the Fan Wall',howStep4d:'Post your moment, like and comment on others.',
      howStep5t:'Climb the leaderboard',howStep5d:'Collect points from games, predictions and your daily photo to rise up the rankings.',
      pointsTitle:'How to collect points',ptsAction:'Action',ptsReward:'Reward',ptsGoal:'Daily game — score a goal (by zone)',ptsGolden:'Golden ball goal (bonus)',ptsCombo:'Combo streak (every 3 / 5 goals)',ptsMystery:'Daily food bonus roll',ptsPredWin:'Predict the match winner',ptsPredScore:'Predict the correct score',ptsChampion:'Predict the champion (Final only)',ptsPhoto:'Fan Filter photo (once per day)',
      ptsNote:'Submit predictions before kickoff — points are awarded automatically once the official result is synced. Save your daily Fan Filter photo for the photo bonus.'},
    ar:{navHome:'الرئيسية',navMatches:'المباريات',navTeamsMap:'خريطة المنتخبات',navFanFilter:'فلتر المشجع',navProfile:'ملفي',navHowTo:'طريقة الاستخدام',navPoints:'النقاط',navLogout:'خروج',
      heroBadge:'القراءة من قاعدة البيانات',heroTitle:'مباريات حقيقية.<br><span>نتائج حقيقية.</span>',
      heroIntro:'تصفّح قائمة مباريات كأس العالم 2026 الرسمية، والحالة المباشرة، والمباريات القادمة والنتائج النهائية. أرسل توقعاتك قبل انطلاق المباراة وتصدّر لوحة الصدارة.',
      statTotal:'إجمالي المباريات',statTotalNote:'مباريات متزامنة',statUpcoming:'القادمة',statUpcomingNote:'مفتوحة للتوقع',statLive:'مباشر الآن',statLiveNote:'تُلعب حاليًا',statFinished:'منتهية',statFinishedNote:'النتائج متاحة',
      tabAll:'الكل',tabUpcoming:'القادمة',tabLive:'مباشر',tabFinished:'منتهية',searchPh:'ابحث عن فريق أو دور...',searchBtn:'بحث',
      reactionsWord:'تفاعلات',commentsWord:'تعليقات',commentWord:'تعليق',predictionsWord:'توقعات',
      globeKicker:'كأس العالم 2026',globeTitle:'المنتخبات في الملعب',globeP:'انقر منتخبًا للانتقال إلى مبارياته.',
      teamsMapTitle:'خريطة المنتخبات المشاركة',openTeamsMap:'فتح الخريطة كاملة ↗',teamsMapSub:'استكشف المنتخبات الـ48 المتأهلة على خريطة عالم حقيقية — اضغط على أي دولة لقصتها الكروية ونجومها ولحظاتها المميزة.',
      footerDev:'تطوير كاتريون © تقنية المعلومات والتحول الرقمي',close:'إغلاق',
      pfType:'نوع المستخدم',pfDays:'أيام اللعب',statOverall:'النقاط الإجمالية',statWeekly:'نقاط الأسبوع',statRank:'ترتيبي',
      howToTitle:'ابدأ في 5 خطوات',howStep1t:'العب تحدي الأهداف اليومي',howStep1d:'انقر الكرة (أو اضغط مسافة) للتسديد، تجاوز الحارس، وسجّل خلال 30 ثانية — مرة واحدة يوميًا.',
      howStep2t:'توقّع المباريات الحقيقية',howStep2d:'افتح مباراة وأرسل توقع النتيجة قبل انطلاقها. تُقفل التوقعات عند بدء المباراة.',
      howStep3t:'أنشئ صورة فلتر المشجع',howStep3d:'اختر دولتك وإطارًا، التقط صورة أو ارفع واحدة، ثم احفظها ونزّلها.',
      howStep4t:'انضم إلى جدار المشجعين',howStep4d:'انشر لحظتك وتفاعل وعلّق على الآخرين.',
      howStep5t:'تصدّر لوحة الصدارة',howStep5d:'اجمع النقاط من الألعاب والتوقعات وصورتك اليومية لترتقي في التصنيف.',
      pointsTitle:'كيف تجمع النقاط',ptsAction:'الإجراء',ptsReward:'المكافأة',ptsGoal:'اللعبة اليومية — تسجيل هدف (حسب المنطقة)',ptsGolden:'هدف الكرة الذهبية (مكافأة)',ptsCombo:'سلسلة متتالية (كل 3 / 5 أهداف)',ptsMystery:'لفة المكافأة الغذائية اليومية',ptsPredWin:'توقّع الفائز بالمباراة',ptsPredScore:'توقّع النتيجة الصحيحة',ptsChampion:'توقّع البطل (النهائي فقط)',ptsPhoto:'صورة فلتر المشجع (مرة يوميًا)',
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
  var oFF=d.getElementById('openFanFilterBtn'); if(oFF) oFF.addEventListener('click', function(){ open('ffModal'); });
  // stop the camera stream whenever the studio is closed
  d.querySelectorAll('[data-close="ffModal"]').forEach(function(b){ b.addEventListener('click', function(){ if(window.wcFFStop) window.wcFFStop(); }); });
  var ffM=d.getElementById('ffModal'); if(ffM) ffM.addEventListener('click', function(e){ if(e.target===ffM && window.wcFFStop) window.wcFFStop(); });
  var oP=d.getElementById('openProfileBtn'); if(oP) oP.addEventListener('click', function(){ open('profileModal'); });
  var oH=d.getElementById('howToBtn'); if(oH) oH.addEventListener('click', function(){ open('howToModal'); });
  var oPt=d.getElementById('pointsBtn'); if(oPt) oPt.addEventListener('click', function(){ open('pointsModal'); });
  d.querySelectorAll('[data-close]').forEach(function(b){ b.addEventListener('click', function(){ close(b.getAttribute('data-close')); }); });
  d.querySelectorAll('.wc-modal').forEach(function(m){ m.addEventListener('click', function(e){ if(e.target===m) m.classList.remove('active'); }); });
  d.addEventListener('keydown', function(e){ if(e.key==='Escape') d.querySelectorAll('.wc-modal.active').forEach(function(m){ m.classList.remove('active'); }); });

  applyTheme(theme); applyLang(lang);
})();
</script>

<style>
/* Mobile: header buttons slide in as a scrollable side drawer + scrollable tabs */
.nav-burger{display:none;width:44px;height:44px;flex:none;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.12);color:#fff;border-radius:12px;cursor:pointer;align-items:center;justify-content:center}
.nav-burger svg{width:22px;height:22px}
@media(max-width:768px){
  .nav{flex-wrap:wrap;position:relative}
  /* When the drawer is open, lift the whole hero (which contains the nav
     drawer) above the page content (.container z-index:5) and the backdrop. */
  html.wc-nav-open .hero{z-index:1995 !important}
  /* Mobile nav drawer — !important so it can't be clobbered by the
     unconditional ".nav/.top-actions" z-index/position rules above. */
  .nav{flex-wrap:wrap;position:relative;z-index:2002 !important}
  .brand{min-width:0}
  /* Burger pinned to the inline-end → right in LTR, left in RTL. */
  .nav-burger{display:inline-flex !important;position:relative;z-index:2005;margin-inline-start:auto}
  /* Hide the burger once the drawer is open (close via the dimmed backdrop or Esc). */
  html.wc-nav-open .nav-burger{display:none !important}
  /* Long brand title overflowed on mobile; the logo conveys the brand. */
  .brand-title{display:none !important}
  .top-actions{position:fixed !important;top:0;inset-inline-end:0;height:100vh;height:100dvh;width:min(82vw,300px);z-index:2000 !important;
    display:flex !important;flex-direction:column !important;align-items:stretch;gap:10px;overflow-y:auto;-webkit-overflow-scrolling:touch;
    background:#0c1830;border-inline-start:1px solid rgba(168,231,255,.2);border-radius:0;padding:70px 14px 28px;
    box-shadow:-24px 0 60px rgba(0,0,0,.55);transform:translateX(105%) !important;transition:transform .28s ease}
  .top-actions.open{transform:none !important}
  html[dir="rtl"] .top-actions{box-shadow:24px 0 60px rgba(0,0,0,.55);transform:translateX(-105%) !important}
  html[dir="rtl"] .top-actions.open{transform:none !important}
  .top-actions .theme-switch,.top-actions .lang-switch{justify-content:center}
  .top-actions .nav-link,.top-actions .logout,.top-actions form{width:100%}
  .top-actions .nav-link,.top-actions .logout{text-align:center;justify-content:center}
  .top-actions form button{width:100%}
  .nav-backdrop{position:fixed;inset:0;z-index:1990;background:rgba(4,12,28,.55);opacity:0;visibility:hidden;transition:.25s}
  .nav-backdrop.show{opacity:1;visibility:visible}
  .tabs{flex-wrap:nowrap;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none}
  .tabs::-webkit-scrollbar{display:none}
  .tab{flex:0 0 auto}
  .toolbar{flex-direction:column;align-items:stretch}
  .search{width:100%}
  .wc-foot-inner{flex-direction:column;text-align:center}
}
/* Arabic / RTL spacing fixes */
html[dir="rtl"] .pts-table th,html[dir="rtl"] .pts-table td{text-align:right}
html[dir="rtl"] .help-item{flex-direction:row-reverse;text-align:right}
html[dir="rtl"] .match-social-mini,html[dir="rtl"] .match-meta{text-align:right}
</style>
<script>
(function(){
  var b=document.getElementById('navBurger'), a=document.getElementById('topActions');
  if(!b||!a) return;
  var bd=document.createElement('div'); bd.className='nav-backdrop'; document.body.appendChild(bd);
  function setOpen(open){ a.classList.toggle('open', open); bd.classList.toggle('show', open); document.documentElement.classList.toggle('wc-nav-open', open); b.setAttribute('aria-expanded', open?'true':'false'); }
  b.addEventListener('click', function(e){ e.stopPropagation(); setOpen(!a.classList.contains('open')); });
  bd.addEventListener('click', function(){ setOpen(false); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') setOpen(false); });
  a.querySelectorAll('a, button').forEach(function(el){
    if(el.classList.contains('theme-btn')||el.classList.contains('lang-btn')) return;
    el.addEventListener('click', function(){ setOpen(false); });
  });
})();
</script>

<script>
/* ===== Native Fan Filter studio engine (saves to /WC/api/save_filter_photo.php) ===== */
(function(){
    const csrf = <?= json_encode($csrf) ?>;
    const video = document.getElementById('video');
    const canvas = document.getElementById('canvas');
    if (!video || !canvas) return;
    const ctx = canvas.getContext('2d');
    const previewImage = document.getElementById('previewImage');
    const liveFrameOverlay = document.getElementById('liveFrameOverlay');
    const liveFlagOverlay = document.getElementById('liveFlagOverlay');
    const liveFlagBox = document.getElementById('liveFlagBox');
    const liveFlagImage = document.getElementById('liveFlagImage');
    const liveFlagCode = document.getElementById('liveFlagCode');
    const cameraEmpty = document.getElementById('cameraEmpty');
    const startCameraBtn = document.getElementById('startCameraBtn');
    const flipCameraBtn = document.getElementById('flipCameraBtn');
    const captureBtn = document.getElementById('captureBtn');
    const uploadPhoto = document.getElementById('uploadPhoto');
    const retakeBtn = document.getElementById('retakeBtn');
    const saveDownloadBtn = document.getElementById('saveDownloadBtn');
    const messageBox = document.getElementById('messageBox');
    const countryTrigger = document.getElementById('countryTrigger');
    const countryMenu = document.getElementById('countryMenu');
    const countrySearch = document.getElementById('countrySearch');
    const noCountryResults = document.getElementById('noCountryResults');
    const selectedCountryFlag = document.getElementById('selectedCountryFlag');
    const selectedCountryName = document.getElementById('selectedCountryName');
    const selectedCountryCode = document.getElementById('selectedCountryCode');
    const selectionSummary = document.getElementById('selectionSummary');
    const cameraWrap = document.querySelector('#ffModal .camera-wrap');
    let stream=null, finalImageData='', finalImageBlob=null, currentFacingMode='user', hasCameraStarted=false;
    const isMobileDevice = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent||'');
    window.wcFFStop = function(){ if(stream){ stream.getTracks().forEach(t=>t.stop()); stream=null; hasCameraStarted=false; } };
    function showMessage(t,x){messageBox.className='message '+(t==='ok'?'ok':'err');messageBox.textContent=x;}
    function clearMessage(){messageBox.className='message';messageBox.textContent='';}
    function downloadFanImage(){
        try{
            var ext=(finalImageBlob&&finalImageBlob.type&&finalImageBlob.type.indexOf('png')>=0)?'png':'jpg';
            var url=finalImageBlob?URL.createObjectURL(finalImageBlob):finalImageData;
            var a=document.createElement('a');
            a.download='wc2026-fan-filter.'+ext; a.href=url; a.rel='noopener'; a.style.display='none';
            document.body.appendChild(a); a.click(); a.remove();
            if(finalImageBlob) setTimeout(function(){ URL.revokeObjectURL(url); },4000);
        }catch(e){
            var a2=document.createElement('a'); a2.download='wc2026-fan-filter.jpg'; a2.href=finalImageData; a2.click();
        }
    }
    function escapeHtml(s){return String(s).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');}
    function updateFlip(){ if(!flipCameraBtn) return; if(isMobileDevice&&navigator.mediaDevices&&navigator.mediaDevices.getUserMedia){flipCameraBtn.classList.add('show');flipCameraBtn.disabled=!hasCameraStarted;flipCameraBtn.textContent=currentFacingMode==='user'?'🔄 Back Camera':'🔄 Front Camera';}else{flipCameraBtn.classList.remove('show');flipCameraBtn.disabled=true;} }
    function selPlatform(){const el=document.querySelector('#ffModal .platform-choice.active');if(!el)return null;return{id:el.dataset.platformId,name:el.dataset.platformName,code:el.dataset.platformCode,width:parseInt(el.dataset.width||'720',10),height:parseInt(el.dataset.height||'900',10)};}
    function selCountry(){const el=document.querySelector('#ffModal .country-choice.active');if(!el)return null;return{id:el.dataset.countryId,name:el.dataset.countryName,code:el.dataset.countryCode,flag:el.dataset.flag};}
    function selFrame(){const el=document.querySelector('#ffModal .frame-choice.active');if(!el)return null;return{id:el.dataset.frameId,name:el.dataset.frameName,frame:el.dataset.frame};}
    function updFrameOverlay(){const f=selFrame();if(!liveFrameOverlay)return;if(!f||!f.frame){liveFrameOverlay.style.display='none';liveFrameOverlay.src='';return;}liveFrameOverlay.src=f.frame;liveFrameOverlay.style.display='block';}
    function updFlagOverlay(){const c=selCountry();if(!liveFlagOverlay||!liveFlagBox||!liveFlagImage||!liveFlagCode||!cameraWrap||!c)return;const r=cameraWrap.getBoundingClientRect();const W=r.width||0,H=r.height||0;if(!W||!H){liveFlagOverlay.style.display='none';return;}const flagW=Math.round(W*0.245),flagH=Math.round(flagW*0.70),mr=Math.round(W*0.070),bm=Math.round(H*0.110),tg=Math.round(H*0.028),pad=Math.max(4,Math.round(W*0.0046));const fx=W-flagW-mr,fy=H-flagH-bm-tg;liveFlagOverlay.style.left=Math.round(fx-pad)+'px';liveFlagOverlay.style.top=Math.round(fy-pad)+'px';liveFlagOverlay.style.width=Math.round(flagW+pad*2)+'px';liveFlagOverlay.style.display='flex';liveFlagBox.style.height=Math.round(flagH+pad*2)+'px';liveFlagBox.style.padding=pad+'px';liveFlagBox.style.borderRadius=Math.round(W*0.030)+'px';liveFlagImage.style.borderRadius=Math.round(W*0.022)+'px';liveFlagImage.src=c.flag;liveFlagImage.alt=c.name||'';liveFlagCode.textContent=c.code||'';liveFlagCode.style.fontSize=Math.max(12,Math.round(W*0.040))+'px';liveFlagCode.style.marginTop=Math.max(2,tg-pad)+'px';}
    function updOverlays(){updFrameOverlay();updFlagOverlay();}
    function updSummary(){const p=selPlatform(),c=selCountry(),f=selFrame();if(selectionSummary)selectionSummary.innerHTML='Selected platform: <strong>'+escapeHtml(p?p.name:'-')+'</strong><br>Selected country: <strong>'+escapeHtml(c?c.name:'-')+'</strong><br>Selected frame: <strong>'+escapeHtml(f?f.name:'-')+'</strong>';}
    function updAspect(){const p=selPlatform();if(!p||!cameraWrap)return;cameraWrap.style.aspectRatio=p.width+' / '+p.height;}
    function filterFrames(){const p=selPlatform();if(!p)return;let first=null;document.querySelectorAll('#ffModal .frame-choice').forEach(b=>{const m=String(b.dataset.platformId||'')===String(p.id);b.style.display=m?'':'none';b.classList.remove('active');if(m&&!first)first=b;});if(first)first.classList.add('active');updAspect();updSummary();finalImageData='';finalImageBlob=null;previewImage.src='';previewImage.style.display='none';saveDownloadBtn.disabled=true;retakeBtn.disabled=true;if(stream){video.style.display='block';cameraEmpty.style.display='none';captureBtn.disabled=false;}else{video.style.display='none';cameraEmpty.style.display='grid';captureBtn.disabled=true;}updOverlays();clearMessage();}
    function loadImage(src){return new Promise((res,rej)=>{const i=new Image();i.crossOrigin='anonymous';i.onload=()=>res(i);i.onerror=()=>rej(new Error('load '+src));i.src=src;});}
    function drawCover(s,x,y,w,h){const sw=s.videoWidth||s.naturalWidth||s.width,sh=s.videoHeight||s.naturalHeight||s.height;if(!sw||!sh)throw new Error('not ready');const r=Math.max(w/sw,h/sh),nw=sw*r,nh=sh*r;ctx.drawImage(s,x+(w-nw)/2,y+(h-nh)/2,nw,nh);}
    function roundRect(c,x,y,w,h,r){c.beginPath();c.moveTo(x+r,y);c.arcTo(x+w,y,x+w,y+h,r);c.arcTo(x+w,y+h,x,y+h,r);c.arcTo(x,y+h,x,y,r);c.arcTo(x,y,x+w,y,r);c.closePath();}
    async function compose(source){clearMessage();const p=selPlatform(),c=selCountry(),f=selFrame();if(!p||!c||!f){showMessage('err','Please choose a platform, country and frame first.');return;}canvas.width=p.width;canvas.height=p.height;ctx.clearRect(0,0,canvas.width,canvas.height);try{drawCover(source,0,0,canvas.width,canvas.height);}catch(e){showMessage('err','Camera image is not ready. Please try again.');return;}try{const fr=await loadImage(f.frame);ctx.drawImage(fr,0,0,canvas.width,canvas.height);}catch(e){showMessage('err','Frame image could not be loaded.');return;}try{const fl=await loadImage(c.flag);const flagW=Math.round(canvas.width*0.245),flagH=Math.round(flagW*0.70),mr=Math.round(canvas.width*0.070),bm=Math.round(canvas.height*0.110),tg=Math.round(canvas.height*0.028);const fx=canvas.width-flagW-mr,fy=canvas.height-flagH-bm-tg;ctx.save();ctx.shadowColor='rgba(0,0,0,.25)';ctx.shadowBlur=18;ctx.fillStyle='#fff';roundRect(ctx,fx-10,fy-10,flagW+20,flagH+20,Math.round(canvas.width*0.030));ctx.fill();ctx.restore();ctx.save();roundRect(ctx,fx,fy,flagW,flagH,Math.round(canvas.width*0.022));ctx.clip();ctx.drawImage(fl,fx,fy,flagW,flagH);ctx.restore();ctx.font='900 '+Math.round(canvas.width*0.040)+'px Inter, Arial';ctx.fillStyle='#fff';ctx.textAlign='center';ctx.shadowColor='rgba(0,0,0,.45)';ctx.shadowBlur=8;ctx.fillText(c.code,fx+flagW/2,fy+flagH+tg);ctx.shadowBlur=0;}catch(e){showMessage('err','Flag image could not be loaded.');return;}finalImageData=canvas.toDataURL('image/jpeg',0.88);finalImageBlob=await new Promise(r=>canvas.toBlob(r,'image/jpeg',0.88));if(!finalImageBlob){showMessage('err','Unable to prepare image.');return;}previewImage.src=finalImageData;previewImage.style.display='block';if(liveFrameOverlay)liveFrameOverlay.style.display='none';if(liveFlagOverlay)liveFlagOverlay.style.display='none';canvas.style.display='none';video.style.display='none';cameraEmpty.style.display='none';saveDownloadBtn.disabled=false;retakeBtn.disabled=false;showMessage('ok','Photo created successfully.');}
    async function startCamera(fm=currentFacingMode){clearMessage();if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia){showMessage('err','Camera not supported. Please upload a photo.');return;}try{if(stream)stream.getTracks().forEach(t=>t.stop());currentFacingMode=fm;stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:currentFacingMode},width:{ideal:1080},height:{ideal:1350}},audio:false});video.srcObject=stream;video.style.display='block';updOverlays();previewImage.style.display='none';cameraEmpty.style.display='none';await video.play();captureBtn.disabled=false;retakeBtn.disabled=true;saveDownloadBtn.disabled=true;finalImageData='';finalImageBlob=null;hasCameraStarted=true;updateFlip();}catch(e){hasCameraStarted=!!stream;updateFlip();showMessage('err','Unable to open camera. Allow access or upload a photo.');}}
    if(countryTrigger&&countryMenu){countryTrigger.addEventListener('click',e=>{e.stopPropagation();countryMenu.classList.toggle('show');if(countryMenu.classList.contains('show')&&countrySearch)setTimeout(()=>countrySearch.focus(),60);});document.addEventListener('click',e=>{const pk=document.getElementById('countryPicker');if(pk&&!pk.contains(e.target))countryMenu.classList.remove('show');});}
    document.querySelectorAll('#ffModal .platform-choice').forEach(b=>b.addEventListener('click',()=>{document.querySelectorAll('#ffModal .platform-choice').forEach(x=>x.classList.remove('active'));b.classList.add('active');filterFrames();}));
    if(countrySearch)countrySearch.addEventListener('input',()=>{const q=countrySearch.value.trim().toLowerCase();let v=0;document.querySelectorAll('#ffModal .country-choice').forEach(b=>{const ok=(b.dataset.search||'').includes(q);b.style.display=ok?'flex':'none';if(ok)v++;});if(noCountryResults)noCountryResults.style.display=v?'none':'block';});
    document.querySelectorAll('#ffModal .country-choice').forEach(b=>b.addEventListener('click',()=>{document.querySelectorAll('#ffModal .country-choice').forEach(x=>x.classList.remove('active'));b.classList.add('active');selectedCountryFlag.src=b.dataset.flag;selectedCountryName.textContent=b.dataset.countryName;selectedCountryCode.textContent=b.dataset.countryCode;if(countryMenu)countryMenu.classList.remove('show');if(countrySearch){countrySearch.value='';document.querySelectorAll('#ffModal .country-choice').forEach(x=>x.style.display='flex');if(noCountryResults)noCountryResults.style.display='none';}updSummary();updFlagOverlay();clearMessage();}));
    document.querySelectorAll('#ffModal .frame-choice').forEach(b=>b.addEventListener('click',()=>{if(b.style.display==='none')return;document.querySelectorAll('#ffModal .frame-choice').forEach(x=>x.classList.remove('active'));b.classList.add('active');updSummary();updOverlays();clearMessage();}));
    startCameraBtn.addEventListener('click',()=>startCamera(currentFacingMode));
    if(flipCameraBtn)flipCameraBtn.addEventListener('click',async()=>{if(!isMobileDevice)return;const nf=currentFacingMode==='user'?'environment':'user';flipCameraBtn.disabled=true;try{await startCamera(nf);}finally{updateFlip();}});
    captureBtn.addEventListener('click',()=>{if(!video.srcObject||!video.videoWidth){showMessage('err','Camera is not ready yet.');return;}compose(video);});
    uploadPhoto.addEventListener('change',()=>{const f=uploadPhoto.files&&uploadPhoto.files[0];if(!f)return;if(!f.type.startsWith('image/')){showMessage('err','Please upload an image file.');return;}const rd=new FileReader();rd.onload=()=>{const im=new Image();im.onload=()=>{if(stream){stream.getTracks().forEach(t=>t.stop());stream=null;hasCameraStarted=false;updateFlip();}compose(im);};im.onerror=()=>showMessage('err','Unable to read photo.');im.src=rd.result;};rd.readAsDataURL(f);});
    retakeBtn.addEventListener('click',()=>{finalImageData='';finalImageBlob=null;previewImage.src='';previewImage.style.display='none';saveDownloadBtn.disabled=true;retakeBtn.disabled=true;if(stream){video.style.display='block';updOverlays();cameraEmpty.style.display='none';captureBtn.disabled=false;}else{video.style.display='none';cameraEmpty.style.display='grid';captureBtn.disabled=true;}clearMessage();});
    saveDownloadBtn.addEventListener('click',async()=>{if(!finalImageData||!finalImageBlob){showMessage('err','Create your photo first.');return;}const p=selPlatform(),c=selCountry(),f=selFrame();if(!p||!c||!f){showMessage('err','Please choose a platform, country and frame first.');return;}saveDownloadBtn.disabled=true;saveDownloadBtn.textContent='Saving…';try{const fd=new FormData();fd.append('csrf',csrf);fd.append('photo',finalImageBlob,'wc2026-fan-filter.jpg');fd.append('country_id',c.id);fd.append('frame_id',f.id);fd.append('platform_id',p.id);const res=await fetch('/WC/api/save_filter_photo.php',{method:'POST',body:fd,credentials:'same-origin'});const data=await res.json();if(!data.ok)throw new Error(data.message||'Unable to save photo.');downloadFanImage();showMessage('ok','Photo saved and downloaded successfully.');}catch(e){downloadFanImage();showMessage('ok','Photo downloaded. (Server save not reachable.)');}finally{saveDownloadBtn.disabled=false;saveDownloadBtn.textContent='⚽ Save & Download';}});
    window.addEventListener('resize',()=>updFlagOverlay());
    filterFrames();updOverlays();updateFlip();
})();
</script>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
/* Participating Teams Map — native Leaflet map of the qualified nations */
(function(){
  var el=document.getElementById('teamsLeafletMap');
  if(!el || typeof L==='undefined') return;
  var nations=<?= json_encode($teamsMapNations ?? [], JSON_UNESCAPED_UNICODE) ?>;
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
  function isAr(){ return document.documentElement.getAttribute('dir')==='rtl'; }
  function popupHtml(n){
    var ar=isAr();
    var story=(ar && n.storyAr) ? n.storyAr : (n.story||'');
    var L1=ar?{stars:'أبرز النجوم',moments:'لحظات بارزة',fixtures:'عرض المباريات ←',soon:'سيتوفر ملف هذا المنتخب قريباً.'}
             :{stars:'Key players',moments:'Key moments',fixtures:'View fixtures →',soon:'Full team profile coming soon.'};
    var nm=(ar && n.nameAr) ? n.nameAr : n.name;
    var h='<div class="tm-pop"'+(ar?' dir="rtl"':'')+'>';
    h+='<div class="tm-pop-head"><img class="tm-pop-flag" src="https://flagcdn.com/w40/'+esc(n.code)+'.png" alt="">'
      +'<div class="tm-pop-title"><strong>'+esc(nm)+'</strong>'
      +(n.confed?'<span class="tm-pop-confed">'+esc(n.confed)+'</span>':'')+'</div></div>';
    h+='<p class="tm-pop-story">'+esc(story||L1.soon)+'</p>';
    if(n.stars&&n.stars.length){
      h+='<div class="tm-pop-sec"><span class="tm-pop-lbl">★ '+L1.stars+'</span><div class="tm-pop-chips">';
      n.stars.forEach(function(s){ h+='<span class="tm-pop-chip">'+esc(s)+'</span>'; });
      h+='</div></div>';
    }
    if(n.moments&&n.moments.length){
      h+='<div class="tm-pop-sec"><span class="tm-pop-lbl">🏆 '+L1.moments+'</span><ul class="tm-pop-list">';
      n.moments.forEach(function(s){ h+='<li>'+esc(s)+'</li>'; });
      h+='</ul></div>';
    }
    h+='<a class="tm-pop-link" href="/WC2026/matches?q='+encodeURIComponent(n.name)+'">'+L1.fixtures+'</a>';
    h+='</div>';
    return h;
  }
  var map=L.map(el,{zoomControl:true,scrollWheelZoom:false,worldCopyJump:true}).setView([25,10],2);
  L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',{
    maxZoom:9,minZoom:1,
    errorTileUrl:'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==',
    attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>'
  }).addTo(map);
  var bounds=[];
  nations.forEach(function(n){
    var icon=L.divIcon({
      className:'tm-pin-wrap',
      html:'<span class="tm-pin" style="display:block;width:30px;height:30px"><img src="https://flagcdn.com/w40/'+esc(n.code)+'.png" alt="" loading="lazy"></span>',
      iconSize:[30,30], iconAnchor:[15,15]
    });
    var m=L.marker([n.lat,n.lng],{icon:icon,title:n.name}).addTo(map);
    m.bindPopup(function(){ return popupHtml(n); },{maxWidth:300,minWidth:240,className:'tm-pop-wrap'});
    bounds.push([n.lat,n.lng]);
  });
  if(bounds.length) map.fitBounds(bounds,{padding:[30,30],maxZoom:4});
  setTimeout(function(){ map.invalidateSize(); }, 300);
  window.addEventListener('resize', function(){ map.invalidateSize(); });
})();
</script>

</body>
</html>
