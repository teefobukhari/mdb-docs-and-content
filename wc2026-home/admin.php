<?php
/**
 * WC2026 — Admin Analytics Dashboard
 * Access restricted to users whose WC2026_Users.role = 'admin'.
 *
 * Provides KPIs, multiple chart types (line/bar/doughnut/pie/horizontal-bar),
 * filters (date range, department, location, role, status), CSV exports for
 * users / participants / studio, and engagement + studio insights.
 *
 * Every query runs through q()/qv() which swallow DB errors and return empty,
 * so a missing table or column only blanks that one widget instead of the page.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_name('WC2026SESSID');
    session_start();
}

require_once __DIR__ . '/connections/config.php';
require_once __DIR__ . '/connections/functions.php';

require_login();

/* ---------- defensive query helpers ---------- */
function q(string $sql, string $types = '', array $params = []): array {
    global $conn;
    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        if ($types !== '' && $params) $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) return [];
        $res = $stmt->get_result();
        return $res ? ($res->fetch_all(MYSQLI_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return [];
    }
}
function qv(string $sql, string $types = '', array $params = []) {
    $r = q($sql, $types, $params);
    if (!$r) return 0;
    $row = array_values($r)[0];
    return array_values($row)[0] ?? 0;
}

/* ---------- role gate (admin only) ---------- */
$adminId   = (int)($_SESSION['USER_ID'] ?? 0);
$meRows    = q("SELECT id, full_name, role FROM WC2026_Users WHERE id = ? LIMIT 1", "i", [$adminId]);
$me        = $meRows[0] ?? null;
$myRole    = strtolower(trim((string)($me['role'] ?? '')));
$isAdmin   = ($myRole === 'admin');

if (!$isAdmin) {
    http_response_code(403);
    $csrf = $_SESSION['csrf'] ?? '';
    ?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Access denied</title>
    <style>body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:Inter,system-ui,sans-serif;
    background:linear-gradient(135deg,#061A36,#0B2C55);color:#eaf6ff}
    .box{max-width:420px;text-align:center;background:rgba(255,255,255,.06);border:1px solid rgba(168,231,255,.2);
    border-radius:20px;padding:34px}h1{margin:0 0 8px;font-size:22px}p{color:rgba(234,244,255,.78);line-height:1.6}
    a{display:inline-block;margin-top:16px;padding:10px 18px;border-radius:999px;background:#0E63E6;color:#fff;
    text-decoration:none;font-weight:800}</style></head><body>
    <div class="box"><div style="font-size:40px">🔒</div><h1>Admin access only</h1>
    <p>This dashboard is restricted to administrators. Your account doesn't have the <b>admin</b> role.</p>
    <a href="/WC2026/">← Back to the challenge</a></div></body></html><?php
    exit;
}

/* ---------- filters ---------- */
$f_from = trim((string)($_GET['from']   ?? ''));
$f_to   = trim((string)($_GET['to']     ?? ''));
$f_dept = trim((string)($_GET['dept']   ?? ''));
$f_loc  = trim((string)($_GET['loc']    ?? ''));
$f_role = trim((string)($_GET['role']   ?? ''));
$f_stat = trim((string)($_GET['status'] ?? ''));

/** Build the user-attribute WHERE additions (department/location/role/status). */
function uWhere(string $alias, string &$types, array &$params): string {
    global $f_dept, $f_loc, $f_role, $f_stat;
    $sql = '';
    $a = $alias === '' ? '' : ($alias . '.');
    if ($f_dept !== '') { $sql .= " AND {$a}department = ?"; $types .= 's'; $params[] = $f_dept; }
    if ($f_loc  !== '') { $sql .= " AND {$a}location = ?";   $types .= 's'; $params[] = $f_loc;  }
    if ($f_role !== '') { $sql .= " AND {$a}role = ?";       $types .= 's'; $params[] = $f_role; }
    if ($f_stat !== '') { $sql .= " AND {$a}status = ?";     $types .= 's'; $params[] = $f_stat; }
    return $sql;
}
/** Build a date-range WHERE addition on a datetime column. */
function dWhere(string $col, string &$types, array &$params): string {
    global $f_from, $f_to;
    $sql = '';
    if ($f_from !== '') { $sql .= " AND {$col} >= ?"; $types .= 's'; $params[] = $f_from . ' 00:00:00'; }
    if ($f_to   !== '') { $sql .= " AND {$col} <= ?"; $types .= 's'; $params[] = $f_to   . ' 23:59:59'; }
    return $sql;
}

/* ---------- participants-with-results dataset (table + export) ---------- */
function participants(int $limit = 0): array {
    $types = ''; $params = [];
    $uw = uWhere('u', $types, $params);
    $dw = dWhere('u.created_at', $types, $params);
    $lim = $limit > 0 ? (' LIMIT ' . (int)$limit) : '';
    return q("
        SELECT
            u.id, u.full_name, u.mobile, u.email, u.department, u.location, u.role, u.status,
            u.created_at, u.last_login_at,
            COALESCE(gs.pts,0)    AS game_points,
            COALESCE(gs.plays,0)  AS game_plays,
            COALESCE(pr.preds,0)  AS predictions,
            COALESCE(pr.scored,0) AS predictions_scored,
            COALESCE(pr.pts,0)    AS prediction_points,
            COALESCE(fw.posts,0)  AS fanwall_posts,
            (COALESCE(gs.pts,0) + COALESCE(pr.pts,0)) AS total_points
        FROM WC2026_Users u
        LEFT JOIN (SELECT user_id, SUM(total_points) pts, COUNT(*) plays FROM WC2026_Game_Sessions GROUP BY user_id) gs ON gs.user_id = u.id
        LEFT JOIN (SELECT user_id, COUNT(*) preds, SUM(points_calculated) scored, SUM(points_awarded) pts FROM WC2026_Predictions GROUP BY user_id) pr ON pr.user_id = u.id
        LEFT JOIN (SELECT user_id, COUNT(*) posts FROM WC2026_Fan_Wall GROUP BY user_id) fw ON fw.user_id = u.id
        WHERE 1=1 {$uw} {$dw}
        ORDER BY total_points DESC, u.created_at DESC
        {$lim}
    ", $types, $params);
}

/* ---------- CSV export (must run before any HTML) ---------- */
function csv_out(string $filename, array $headers, array $rows): void {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM (Excel-friendly)
    fputcsv($out, $headers);
    foreach ($rows as $r) fputcsv($out, $r);
    fclose($out);
    exit;
}

if (isset($_GET['export'])) {
    $ex   = (string)$_GET['export'];
    $date = date('Ymd_His');

    if ($ex === 'users' || $ex === 'participants') {
        $rows = participants(0);
        if ($ex === 'participants') {
            $rows = array_values(array_filter($rows, function ($r) {
                return ((int)$r['game_plays'] + (int)$r['predictions'] + (int)$r['fanwall_posts']) > 0;
            }));
        }
        $headers = ['ID','Full name','Mobile','Email','Department','Location','Role','Status','Registered','Last login',
                    'Game points','Game plays','Predictions','Predictions scored','Prediction points','Fan Wall posts','Total points'];
        $data = array_map(function ($r) {
            return [$r['id'],$r['full_name'],$r['mobile'],$r['email'],$r['department'],$r['location'],$r['role'],$r['status'],
                    $r['created_at'],$r['last_login_at'],$r['game_points'],$r['game_plays'],$r['predictions'],
                    $r['predictions_scored'],$r['prediction_points'],$r['fanwall_posts'],$r['total_points']];
        }, $rows);
        csv_out("wc2026_{$ex}_{$date}.csv", $headers, $data);
    }

    if ($ex === 'studio') {
        $types = ''; $params = [];
        $dw = dWhere('created_at', $types, $params);
        $rows = q("SELECT id, user_id, author_name, author_location, photo_path, likes_count, comments_count, status, created_at
                   FROM WC2026_Fan_Wall WHERE photo_path IS NOT NULL AND photo_path <> '' {$dw}
                   ORDER BY created_at DESC", $types, $params);
        $headers = ['Post ID','User ID','Author','Location','Photo','Likes','Comments','Status','Created'];
        $data = array_map(function ($r) {
            return [$r['id'],$r['user_id'],$r['author_name'],$r['author_location'],$r['photo_path'],
                    $r['likes_count'],$r['comments_count'],$r['status'],$r['created_at']];
        }, $rows);
        csv_out("wc2026_studio_{$date}.csv", $headers, $data);
    }
}

/* ================= KPIs ================= */
$kTypes=''; $kParams=[]; $uw = uWhere('', $kTypes, $kParams);
$kpiTotalUsers   = (int) qv("SELECT COUNT(*) FROM WC2026_Users WHERE 1=1 {$uw}", $kTypes, $kParams);
$kTypes=''; $kParams=[]; $uw2 = uWhere('', $kTypes, $kParams);
$kpiActiveUsers  = (int) qv("SELECT COUNT(*) FROM WC2026_Users WHERE status='Active' {$uw2}", $kTypes, $kParams);
$kpiAdmins       = (int) qv("SELECT COUNT(*) FROM WC2026_Users WHERE LOWER(role)='admin'");
$kpiOnline24h    = (int) qv("SELECT COUNT(*) FROM WC2026_Users WHERE last_login_at >= NOW() - INTERVAL 24 HOUR");

$kpiGamePlays    = (int) qv("SELECT COUNT(*) FROM WC2026_Game_Sessions");
$kpiGamePoints   = (int) qv("SELECT COALESCE(SUM(total_points),0) FROM WC2026_Game_Sessions");
$kpiPredictions  = (int) qv("SELECT COUNT(*) FROM WC2026_Predictions");
$kpiPredPoints   = (int) qv("SELECT COALESCE(SUM(points_awarded),0) FROM WC2026_Predictions");

$kpiFanPosts     = (int) qv("SELECT COUNT(*) FROM WC2026_Fan_Wall WHERE status='Active'");
$kpiFanComments  = (int) qv("SELECT COUNT(*) FROM WC2026_Fan_Wall_Comments WHERE status='Active'");
$kpiFanLikes     = (int) qv("SELECT COUNT(*) FROM WC2026_Fan_Wall_Likes");
$kpiStudioPhotos = (int) qv("SELECT COUNT(*) FROM WC2026_Fan_Wall WHERE photo_path IS NOT NULL AND photo_path <> ''");
$kpiReactions    = (int) qv("SELECT COUNT(*) FROM WC2026_Match_Reactions");

$kpiParticipants = (int) qv("
    SELECT COUNT(*) FROM (
        SELECT user_id FROM WC2026_Game_Sessions
        UNION SELECT user_id FROM WC2026_Predictions
        UNION SELECT user_id FROM WC2026_Fan_Wall
    ) t");

/* ================= chart datasets ================= */
$charts = [];

/* Registrations over time */
$t=''; $p=[]; $dw=dWhere('created_at',$t,$p); $w=uWhere('',$t,$p);
$charts['reg'] = q("SELECT DATE(created_at) d, COUNT(*) c FROM WC2026_Users WHERE 1=1 {$dw} {$w} GROUP BY DATE(created_at) ORDER BY d", $t,$p);

/* Logins over time (audit log) */
$t=''; $p=[]; $dw=dWhere('created_at',$t,$p);
$charts['login'] = q("SELECT DATE(created_at) d, COUNT(*) c FROM WC2026_Users_Audit_Log WHERE action_type='LOGIN' {$dw} GROUP BY DATE(created_at) ORDER BY d", $t,$p);

/* Users by department / location / role / status */
$t=''; $p=[]; $w=uWhere('',$t,$p);
$charts['dept']   = q("SELECT COALESCE(NULLIF(department,''),'—') k, COUNT(*) c FROM WC2026_Users WHERE 1=1 {$w} GROUP BY k ORDER BY c DESC LIMIT 12", $t,$p);
$t=''; $p=[]; $w=uWhere('',$t,$p);
$charts['loc']    = q("SELECT COALESCE(NULLIF(location,''),'—') k, COUNT(*) c FROM WC2026_Users WHERE 1=1 {$w} GROUP BY k ORDER BY c DESC LIMIT 12", $t,$p);
$t=''; $p=[]; $w=uWhere('',$t,$p);
$charts['role']   = q("SELECT COALESCE(NULLIF(role,''),'—') k, COUNT(*) c FROM WC2026_Users WHERE 1=1 {$w} GROUP BY k ORDER BY c DESC", $t,$p);
$t=''; $p=[]; $w=uWhere('',$t,$p);
$charts['status'] = q("SELECT COALESCE(NULLIF(status,''),'—') k, COUNT(*) c FROM WC2026_Users WHERE 1=1 {$w} GROUP BY k ORDER BY c DESC", $t,$p);

/* Game sessions per day + predictions per day + fan wall per day */
$t=''; $p=[]; $dw=dWhere('played_at',$t,$p);
$charts['game'] = q("SELECT DATE(played_at) d, COUNT(*) c, COALESCE(SUM(total_points),0) pts FROM WC2026_Game_Sessions WHERE 1=1 {$dw} GROUP BY DATE(played_at) ORDER BY d", $t,$p);
$t=''; $p=[]; $dw=dWhere('created_at',$t,$p);
$charts['pred'] = q("SELECT DATE(created_at) d, COUNT(*) c FROM WC2026_Predictions WHERE 1=1 {$dw} GROUP BY DATE(created_at) ORDER BY d", $t,$p);
$t=''; $p=[]; $dw=dWhere('created_at',$t,$p);
$charts['fanwall'] = q("SELECT DATE(created_at) d, COUNT(*) c FROM WC2026_Fan_Wall WHERE 1=1 {$dw} GROUP BY DATE(created_at) ORDER BY d", $t,$p);

/* Predicted-winner distribution */
$charts['winner'] = q("SELECT predicted_winner k, COUNT(*) c FROM WC2026_Predictions WHERE predicted_winner IS NOT NULL GROUP BY predicted_winner ORDER BY c DESC");

/* Match reaction breakdown */
$charts['reactions'] = q("SELECT reaction k, COUNT(*) c FROM WC2026_Match_Reactions GROUP BY reaction ORDER BY c DESC");

/* Studio: filter assets (active vs total) */
$studio = [
    'platforms' => ['active' => (int) qv("SELECT COUNT(*) FROM WC2026_Filter_Platforms WHERE status='Active'"), 'total' => (int) qv("SELECT COUNT(*) FROM WC2026_Filter_Platforms")],
    'countries' => ['active' => (int) qv("SELECT COUNT(*) FROM WC2026_Filter_Countries WHERE status='Active'"), 'total' => (int) qv("SELECT COUNT(*) FROM WC2026_Filter_Countries")],
    'frames'    => ['active' => (int) qv("SELECT COUNT(*) FROM WC2026_Filter_Frames WHERE status='Active'"),    'total' => (int) qv("SELECT COUNT(*) FROM WC2026_Filter_Frames")],
];
/* Studio photos over time */
$t=''; $p=[]; $dw=dWhere('created_at',$t,$p);
$charts['photos'] = q("SELECT DATE(created_at) d, COUNT(*) c FROM WC2026_Fan_Wall WHERE photo_path IS NOT NULL AND photo_path <> '' {$dw} GROUP BY DATE(created_at) ORDER BY d", $t,$p);

/* Top participants (leaderboard) */
$leaders = participants(15);

/* ---------- helper to split rows into JS arrays ---------- */
function xy(array $rows, string $kx, string $ky): array {
    $labels=[]; $data=[];
    foreach ($rows as $r) { $labels[] = (string)$r[$kx]; $data[] = (int)$r[$ky]; }
    return ['labels'=>$labels, 'data'=>$data];
}

$JS = [
    'reg'       => xy($charts['reg'], 'd', 'c'),
    'login'     => xy($charts['login'], 'd', 'c'),
    'dept'      => xy($charts['dept'], 'k', 'c'),
    'loc'       => xy($charts['loc'], 'k', 'c'),
    'role'      => xy($charts['role'], 'k', 'c'),
    'status'    => xy($charts['status'], 'k', 'c'),
    'game'      => xy($charts['game'], 'd', 'c'),
    'gamePts'   => xy($charts['game'], 'd', 'pts'),
    'pred'      => xy($charts['pred'], 'd', 'c'),
    'fanwall'   => xy($charts['fanwall'], 'd', 'c'),
    'winner'    => xy($charts['winner'], 'k', 'c'),
    'reactions' => xy($charts['reactions'], 'k', 'c'),
    'photos'    => xy($charts['photos'], 'd', 'c'),
    'studio'    => $studio,
];

/* ---------- filter dropdown options ---------- */
$optDepts = q("SELECT DISTINCT department v FROM WC2026_Users WHERE department IS NOT NULL AND department<>'' ORDER BY department");
$optLocs  = q("SELECT DISTINCT location v   FROM WC2026_Users WHERE location IS NOT NULL AND location<>''     ORDER BY location");
$optRoles = q("SELECT DISTINCT role v       FROM WC2026_Users WHERE role IS NOT NULL AND role<>''             ORDER BY role");
$optStats = q("SELECT DISTINCT status v     FROM WC2026_Users WHERE status IS NOT NULL AND status<>''         ORDER BY status");

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function qstr(): string { // current filters as a query string (for export links)
    $keep = array_intersect_key($_GET, array_flip(['from','to','dept','loc','role','status']));
    return http_build_query($keep);
}
$logoPath = "/WC2026/partials/CATRION%20logo.png";
?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CATRION · World Cup 2026 — Admin Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
:root{--bg1:#061A36;--bg2:#08254D;--bg3:#0A3A76;--ink:#eaf6ff;--muted:rgba(234,244,255,.72);
--accent:#A8E7FF;--accent2:#3A8BF6;--gold:#F5C85B;--green:#7EF4AE;--red:#E94747;--line:rgba(168,231,255,.18)}
*{box-sizing:border-box}
body{margin:0;font-family:'Inter',system-ui,sans-serif;color:var(--ink);min-height:100vh;
background:radial-gradient(circle at 100% 0%,rgba(14,99,230,.16),transparent 40%),linear-gradient(135deg,var(--bg1),var(--bg2) 58%,var(--bg3))}
a{color:inherit}
.topbar{display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:14px 22px;background:linear-gradient(120deg,#06182f,#0b2c55);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:50}
.brand{display:flex;align-items:center;gap:12px}
.brand img{height:38px;border-radius:8px}
.brand b{font-size:16px;font-weight:900;color:#fff}
.brand span{display:block;font-size:11.5px;color:var(--muted);font-weight:700}
.top-actions{margin-inline-start:auto;display:flex;gap:9px;align-items:center;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border-radius:999px;border:1px solid var(--line);
background:rgba(255,255,255,.08);color:#fff;font-weight:800;font-size:12.5px;cursor:pointer;text-decoration:none;font-family:inherit}
.btn:hover{background:rgba(255,255,255,.16)}
.btn-gold{background:linear-gradient(135deg,#F5C85B,#FFE19A);color:#06202e;border-color:transparent}
.btn-blue{background:#0E63E6;border-color:transparent}
.wrap{max-width:1500px;margin:0 auto;padding:22px}
h2.section{font-size:15px;font-weight:900;margin:26px 4px 12px;color:#fff;letter-spacing:.2px;display:flex;align-items:center;gap:9px}
h2.section:before{content:"";width:9px;height:9px;border-radius:50%;background:var(--green);box-shadow:0 0 14px var(--green)}
/* filters */
.filters{display:flex;gap:10px;flex-wrap:wrap;align-items:end;background:rgba(255,255,255,.04);border:1px solid var(--line);border-radius:18px;padding:14px}
.fld{display:flex;flex-direction:column;gap:5px}
.fld label{font-size:11px;font-weight:900;color:var(--muted);text-transform:uppercase;letter-spacing:.4px}
.fld input,.fld select{min-height:40px;border-radius:11px;border:1px solid var(--line);background:rgba(255,255,255,.06);color:#fff;padding:8px 12px;font-family:inherit;font-weight:700;font-size:13px}
.fld select option{color:#000}
/* KPI cards */
.kpis{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px}
.kpi{background:linear-gradient(135deg,rgba(255,255,255,.07),rgba(255,255,255,.02));border:1px solid var(--line);border-radius:18px;padding:16px 18px}
.kpi .v{font-size:30px;font-weight:900;color:#fff;line-height:1.05;letter-spacing:-.5px}
.kpi .l{font-size:12px;font-weight:800;color:var(--muted);margin-top:4px}
.kpi .s{font-size:11px;font-weight:700;color:var(--accent);margin-top:6px}
/* chart grid */
.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:16px}
.card{background:linear-gradient(135deg,rgba(255,255,255,.06),rgba(255,255,255,.02));border:1px solid var(--line);border-radius:20px;padding:16px}
.card h3{margin:0 0 12px;font-size:13.5px;font-weight:900;color:#fff;display:flex;align-items:center;justify-content:space-between;gap:8px}
.card h3 small{color:var(--muted);font-weight:700;font-size:11px}
.col-12{grid-column:span 12}.col-8{grid-column:span 8}.col-6{grid-column:span 6}.col-4{grid-column:span 4}.col-3{grid-column:span 3}
.chart-box{position:relative;height:280px}
.chart-sm{height:240px}
/* table */
table{width:100%;border-collapse:collapse;font-size:12.5px}
th,td{padding:9px 10px;text-align:start;border-bottom:1px solid var(--line);white-space:nowrap}
th{color:var(--muted);font-weight:900;text-transform:uppercase;font-size:10.5px;letter-spacing:.4px}
td{font-weight:700;color:#eaf6ff}
.table-scroll{overflow:auto;max-height:460px;border-radius:12px}
.rank{display:inline-grid;place-items:center;width:24px;height:24px;border-radius:7px;background:rgba(168,231,255,.14);font-weight:900;font-size:11px}
.pts{color:var(--gold);font-weight:900}
.empty{color:var(--muted);font-weight:700;text-align:center;padding:30px;font-size:13px}
@media(max-width:1100px){.col-8,.col-6,.col-4,.col-3{grid-column:span 6}}
@media(max-width:680px){.col-12,.col-8,.col-6,.col-4,.col-3{grid-column:span 12}.wrap{padding:14px}}
</style>
</head>
<body>

<header class="topbar">
    <div class="brand">
        <img src="<?= $logoPath ?>" alt="CATRION" onerror="this.style.display='none'">
        <div><b>Admin Dashboard</b><span>World Cup 2026 Challenge · insights & results</span></div>
    </div>
    <div class="top-actions">
        <span class="btn" style="cursor:default">👤 <?= h($me['full_name'] ?? 'Admin') ?></span>
        <a class="btn" href="/WC2026/">Home</a>
        <a class="btn" href="/WC2026/matches">Matches</a>
        <form method="POST" action="/WC2026/" style="margin:0">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf'] ?? '') ?>">
            <input type="hidden" name="action" value="logout">
            <button class="btn" type="submit">Logout</button>
        </form>
    </div>
</header>

<div class="wrap">

    <!-- Filters -->
    <form method="GET" class="filters" id="filterForm">
        <div class="fld"><label>From</label><input type="date" name="from" value="<?= h($f_from) ?>"></div>
        <div class="fld"><label>To</label><input type="date" name="to" value="<?= h($f_to) ?>"></div>
        <div class="fld"><label>Department</label><select name="dept"><option value="">All</option>
            <?php foreach ($optDepts as $o): ?><option value="<?= h($o['v']) ?>" <?= $f_dept===$o['v']?'selected':'' ?>><?= h($o['v']) ?></option><?php endforeach; ?></select></div>
        <div class="fld"><label>Location</label><select name="loc"><option value="">All</option>
            <?php foreach ($optLocs as $o): ?><option value="<?= h($o['v']) ?>" <?= $f_loc===$o['v']?'selected':'' ?>><?= h($o['v']) ?></option><?php endforeach; ?></select></div>
        <div class="fld"><label>Role</label><select name="role"><option value="">All</option>
            <?php foreach ($optRoles as $o): ?><option value="<?= h($o['v']) ?>" <?= $f_role===$o['v']?'selected':'' ?>><?= h($o['v']) ?></option><?php endforeach; ?></select></div>
        <div class="fld"><label>Status</label><select name="status"><option value="">All</option>
            <?php foreach ($optStats as $o): ?><option value="<?= h($o['v']) ?>" <?= $f_stat===$o['v']?'selected':'' ?>><?= h($o['v']) ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-blue" type="submit">Apply filters</button>
        <a class="btn" href="/WC2026/admin">Reset</a>
        <span style="flex:1"></span>
        <a class="btn btn-gold" href="?export=users&<?= h(qstr()) ?>">⬇ Export users</a>
        <a class="btn btn-gold" href="?export=participants&<?= h(qstr()) ?>">⬇ Export participants</a>
        <a class="btn btn-gold" href="?export=studio&<?= h(qstr()) ?>">⬇ Export studio</a>
    </form>

    <!-- KPIs -->
    <h2 class="section">Overview</h2>
    <div class="kpis">
        <div class="kpi"><div class="v"><?= number_format($kpiTotalUsers) ?></div><div class="l">Total users</div><div class="s"><?= number_format($kpiActiveUsers) ?> active</div></div>
        <div class="kpi"><div class="v"><?= number_format($kpiParticipants) ?></div><div class="l">Participants</div><div class="s">engaged in any activity</div></div>
        <div class="kpi"><div class="v"><?= number_format($kpiOnline24h) ?></div><div class="l">Active (24h)</div><div class="s">last login</div></div>
        <div class="kpi"><div class="v"><?= number_format($kpiGamePlays) ?></div><div class="l">Game plays</div><div class="s"><?= number_format($kpiGamePoints) ?> pts</div></div>
        <div class="kpi"><div class="v"><?= number_format($kpiPredictions) ?></div><div class="l">Predictions</div><div class="s"><?= number_format($kpiPredPoints) ?> pts</div></div>
        <div class="kpi"><div class="v"><?= number_format($kpiStudioPhotos) ?></div><div class="l">Studio photos</div><div class="s">Fan Wall</div></div>
        <div class="kpi"><div class="v"><?= number_format($kpiFanPosts) ?></div><div class="l">Fan Wall posts</div><div class="s"><?= number_format($kpiFanComments) ?> comments · <?= number_format($kpiFanLikes) ?> likes</div></div>
        <div class="kpi"><div class="v"><?= number_format($kpiReactions) ?></div><div class="l">Match reactions</div><div class="s">like / fire / goal / heart</div></div>
    </div>

    <!-- Trends -->
    <h2 class="section">Activity trends</h2>
    <div class="grid">
        <div class="card col-8"><h3>Registrations & logins <small>per day</small></h3><div class="chart-box"><canvas id="cReg"></canvas></div></div>
        <div class="card col-4"><h3>Users by role</h3><div class="chart-box"><canvas id="cRole"></canvas></div></div>
        <div class="card col-6"><h3>Game plays & points <small>per day</small></h3><div class="chart-box"><canvas id="cGame"></canvas></div></div>
        <div class="card col-6"><h3>Predictions & Fan Wall <small>per day</small></h3><div class="chart-box"><canvas id="cEngage"></canvas></div></div>
    </div>

    <!-- Demographics -->
    <h2 class="section">Users & demographics</h2>
    <div class="grid">
        <div class="card col-6"><h3>Users by department</h3><div class="chart-box"><canvas id="cDept"></canvas></div></div>
        <div class="card col-6"><h3>Users by location</h3><div class="chart-box"><canvas id="cLoc"></canvas></div></div>
        <div class="card col-4"><h3>Users by status</h3><div class="chart-box chart-sm"><canvas id="cStatus"></canvas></div></div>
        <div class="card col-4"><h3>Predicted winners</h3><div class="chart-box chart-sm"><canvas id="cWinner"></canvas></div></div>
        <div class="card col-4"><h3>Match reactions</h3><div class="chart-box chart-sm"><canvas id="cReact"></canvas></div></div>
    </div>

    <!-- Studio insights -->
    <h2 class="section">Fan Filter Studio insights</h2>
    <div class="grid">
        <div class="card col-6"><h3>Studio assets <small>active vs total</small></h3><div class="chart-box"><canvas id="cStudio"></canvas></div></div>
        <div class="card col-6"><h3>Studio photos created <small>per day</small></h3><div class="chart-box"><canvas id="cPhotos"></canvas></div></div>
    </div>

    <!-- Leaderboard -->
    <h2 class="section">Top participants <small style="font-weight:700;color:var(--muted);font-size:11px">(by total points)</small></h2>
    <div class="card col-12">
        <div class="table-scroll">
        <table>
            <thead><tr>
                <th>#</th><th>Name</th><th>Dept</th><th>Location</th>
                <th>Game pts</th><th>Plays</th><th>Predictions</th><th>Pred. pts</th><th>Posts</th><th>Total</th>
            </tr></thead>
            <tbody>
            <?php if ($leaders): foreach ($leaders as $i => $r): ?>
                <tr>
                    <td><span class="rank"><?= $i+1 ?></span></td>
                    <td><?= h($r['full_name']) ?></td>
                    <td><?= h($r['department'] ?: '—') ?></td>
                    <td><?= h($r['location'] ?: '—') ?></td>
                    <td><?= number_format((int)$r['game_points']) ?></td>
                    <td><?= number_format((int)$r['game_plays']) ?></td>
                    <td><?= number_format((int)$r['predictions']) ?></td>
                    <td><?= number_format((int)$r['prediction_points']) ?></td>
                    <td><?= number_format((int)$r['fanwall_posts']) ?></td>
                    <td class="pts"><?= number_format((int)$r['total_points']) ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="10" class="empty">No participant data yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div style="height:30px"></div>
</div>

<script>
const D = <?= json_encode($JS, JSON_UNESCAPED_UNICODE) ?>;
const PAL = ['#3A8BF6','#A8E7FF','#7EF4AE','#F5C85B','#FF8B5B','#E94747','#B78BFF','#1FB573','#FFE19A','#55B7FF','#ff6fae','#9be36b'];
Chart.defaults.color = 'rgba(234,244,255,.78)';
Chart.defaults.font.family = "'Inter',system-ui,sans-serif";
Chart.defaults.font.weight = '700';
const GRID = {grid:{color:'rgba(168,231,255,.10)'},ticks:{color:'rgba(234,244,255,.72)'}};
const noData = c => (!c || !c.labels || c.labels.length===0);
function mk(id, cfg){ const el=document.getElementById(id); if(!el) return; const d=cfg.data; const has=d.datasets&&d.datasets.some(s=>s.data&&s.data.length); if(!has){ el.parentElement.innerHTML='<div class="empty">No data for the selected filters.</div>'; return;} new Chart(el, cfg); }

/* Registrations + Logins (line) */
mk('cReg',{type:'line',data:{labels:D.reg.labels.length?D.reg.labels:D.login.labels,datasets:[
  {label:'Registrations',data:D.reg.data,borderColor:'#3A8BF6',backgroundColor:'rgba(58,139,246,.18)',fill:true,tension:.35,pointRadius:2},
  {label:'Logins',data:D.login.data,borderColor:'#7EF4AE',backgroundColor:'rgba(126,244,174,.12)',fill:true,tension:.35,pointRadius:2}
]},options:{responsive:true,maintainAspectRatio:false,scales:{x:GRID,y:{...GRID,beginAtZero:true}},plugins:{legend:{position:'bottom'}}}});

/* Users by role (doughnut) */
mk('cRole',{type:'doughnut',data:{labels:D.role.labels,datasets:[{data:D.role.data,backgroundColor:PAL,borderColor:'rgba(0,0,0,.2)',borderWidth:1}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}});

/* Game plays + points (mixed bar/line) */
mk('cGame',{data:{labels:D.game.labels,datasets:[
  {type:'bar',label:'Plays',data:D.game.data,backgroundColor:'rgba(58,139,246,.6)',borderRadius:6,yAxisID:'y'},
  {type:'line',label:'Points',data:D.gamePts.data,borderColor:'#F5C85B',backgroundColor:'rgba(245,200,91,.15)',tension:.35,pointRadius:2,yAxisID:'y1'}
]},options:{responsive:true,maintainAspectRatio:false,scales:{x:GRID,y:{...GRID,beginAtZero:true,position:'left'},y1:{...GRID,beginAtZero:true,position:'right',grid:{drawOnChartArea:false}}},plugins:{legend:{position:'bottom'}}}});

/* Predictions + Fan Wall (line) */
mk('cEngage',{type:'line',data:{labels:D.pred.labels.length?D.pred.labels:D.fanwall.labels,datasets:[
  {label:'Predictions',data:D.pred.data,borderColor:'#A8E7FF',backgroundColor:'rgba(168,231,255,.15)',fill:true,tension:.35,pointRadius:2},
  {label:'Fan Wall posts',data:D.fanwall.data,borderColor:'#FF8B5B',backgroundColor:'rgba(255,139,91,.12)',fill:true,tension:.35,pointRadius:2}
]},options:{responsive:true,maintainAspectRatio:false,scales:{x:GRID,y:{...GRID,beginAtZero:true}},plugins:{legend:{position:'bottom'}}}});

/* Department (bar) */
mk('cDept',{type:'bar',data:{labels:D.dept.labels,datasets:[{label:'Users',data:D.dept.data,backgroundColor:PAL,borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,scales:{x:GRID,y:{...GRID,beginAtZero:true}},plugins:{legend:{display:false}}}});

/* Location (horizontal bar) */
mk('cLoc',{type:'bar',data:{labels:D.loc.labels,datasets:[{label:'Users',data:D.loc.data,backgroundColor:'#55B7FF',borderRadius:6}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,scales:{x:{...GRID,beginAtZero:true},y:GRID},plugins:{legend:{display:false}}}});

/* Status (doughnut) */
mk('cStatus',{type:'doughnut',data:{labels:D.status.labels,datasets:[{data:D.status.data,backgroundColor:PAL,borderWidth:1,borderColor:'rgba(0,0,0,.2)'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}});

/* Predicted winner (pie) */
mk('cWinner',{type:'pie',data:{labels:D.winner.labels,datasets:[{data:D.winner.data,backgroundColor:PAL,borderWidth:1,borderColor:'rgba(0,0,0,.2)'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}});

/* Reactions (doughnut) */
mk('cReact',{type:'doughnut',data:{labels:D.reactions.labels,datasets:[{data:D.reactions.data,backgroundColor:PAL,borderWidth:1,borderColor:'rgba(0,0,0,.2)'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}});

/* Studio assets (grouped bar) */
const sA=D.studio||{}; const sLabels=['Platforms','Countries','Frames'];
mk('cStudio',{type:'bar',data:{labels:sLabels,datasets:[
  {label:'Active',data:[(sA.platforms||{}).active||0,(sA.countries||{}).active||0,(sA.frames||{}).active||0],backgroundColor:'#7EF4AE',borderRadius:6},
  {label:'Total',data:[(sA.platforms||{}).total||0,(sA.countries||{}).total||0,(sA.frames||{}).total||0],backgroundColor:'rgba(168,231,255,.35)',borderRadius:6}
]},options:{responsive:true,maintainAspectRatio:false,scales:{x:GRID,y:{...GRID,beginAtZero:true}},plugins:{legend:{position:'bottom'}}}});

/* Studio photos per day (line) */
mk('cPhotos',{type:'line',data:{labels:D.photos.labels,datasets:[{label:'Photos',data:D.photos.data,borderColor:'#F5C85B',backgroundColor:'rgba(245,200,91,.16)',fill:true,tension:.35,pointRadius:2}]},options:{responsive:true,maintainAspectRatio:false,scales:{x:GRID,y:{...GRID,beginAtZero:true}},plugins:{legend:{display:false}}}});
</script>
</body>
</html>
