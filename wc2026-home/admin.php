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

require_once __DIR__ . '/_session.php';

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

/* Return the first column in $cands that actually exists on $table (current DB),
   preserving its real casing; '' if none. Lets us bind to whatever timestamp /
   segment column the schema happens to use instead of hardcoding a name. */
function first_col(string $table, array $cands): string {
    static $cache = [];
    $key = strtolower($table);
    if (!array_key_exists($key, $cache)) {
        $cache[$key] = [];
        foreach (q("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", "s", [$table]) as $r) {
            $cache[$key][strtolower((string)$r['COLUMN_NAME'])] = (string)$r['COLUMN_NAME'];
        }
    }
    foreach ($cands as $c) {
        if (isset($cache[$key][strtolower($c)])) return $cache[$key][strtolower($c)];
    }
    return '';
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

/* Points awarded per distinct day a user uses the Fan Studio (one award/day). */
if (!defined('WC_PTS_PHOTO')) define('WC_PTS_PHOTO', 1000); // Fan Filter photo (once per day) per the scoring rules
if (!defined('WC_STUDIO_DAILY_PTS')) define('WC_STUDIO_DAILY_PTS', WC_PTS_PHOTO); // align studio bonus to the photo rule
if (!defined('WC_PTS_PREDICT_WINNER'))   define('WC_PTS_PREDICT_WINNER', 300);
if (!defined('WC_PTS_PREDICT_SCORE'))    define('WC_PTS_PREDICT_SCORE', 500);
if (!defined('WC_PTS_PREDICT_CHAMPION')) define('WC_PTS_PREDICT_CHAMPION', 5000);

/** Champion/other prediction points = total prediction points beyond exact-score
 *  (+5) and correct-winner (+3) — i.e. the +15 champion picks. */
function predChampionPoints(array $r): int {
    return max(0, (int)($r['pred_points'] ?? 0) - (int)($r['score_pts'] ?? 0) - (int)($r['winner_pts'] ?? 0));
}

/** Studio points = distinct photo days × WC_PTS_PHOTO (+10, once per day). */
function studioPoints(array $r): int {
    return (int)($r['studio_days'] ?? 0) * WC_PTS_PHOTO;
}

/** Total earned points = score + winner + champion + game score + studio.
 *  Equivalent to predictions (points_awarded) + game + studio, but computed from
 *  the same components shown in the table so the columns always sum to Total. */
function behaviorPoints(array $r): int {
    return (int)($r['score_pts'] ?? 0)
         + (int)($r['winner_pts'] ?? 0)
         + predChampionPoints($r)
         + (int)($r['game_score'] ?? 0)
         + studioPoints($r);
}

/* Detect the real timestamp / FK columns so KPIs + charts can date-filter and
   join correctly regardless of the exact schema naming. */
$predTs   = first_col('WC2026_Predictions',    ['created_at','submitted_at','predicted_at','updated_at']);
$reactTs  = first_col('WC2026_Match_Reactions', ['created_at','reacted_at','updated_at']);
$photoTs  = first_col('WC2026_Filter_Photos',   ['created_at','captured_at','saved_at','updated_at']);
$photoCty = first_col('WC2026_Filter_Photos',   ['country_id','filter_country_id','country']);

/* COUNT/SUM over an activity table with the current filters applied: date range
   on the table's own timestamp + user attributes via a join to WC2026_Users. */
function act(string $table, string $alias, string $tsCol, string $expr = 'COUNT(*)', string $extra = ''): int {
    $t = ''; $p = [];
    $uw = uWhere('u', $t, $p);
    $dw = $tsCol !== '' ? dWhere("{$alias}.`{$tsCol}`", $t, $p) : '';
    return (int) qv("SELECT {$expr}
                     FROM {$table} {$alias}
                     JOIN WC2026_Users u ON u.id = {$alias}.user_id
                     WHERE 1=1 {$extra} {$uw} {$dw}", $t, $p);
}

/* ---------- participants-with-results dataset (table + export) ---------- */
function participants(int $limit = 0): array {
    global $photoTs;
    $types = ''; $params = [];
    $uw = uWhere('u', $types, $params);
    $dw = dWhere('u.created_at', $types, $params);
    $lim = $limit > 0 ? (' LIMIT ' . (int)$limit) : '';

    /* Daily Fan Studio bonus: WC_STUDIO_DAILY_PTS per distinct day a user creates
       a studio photo. Only joined when the table/column exist so the query never
       breaks the leaderboard/export. */
    $stSel = '0 AS studio_days, 0 AS studio_points'; $stJoin = ''; $stAdd = '';
    if (!empty($photoTs)) {
        $stJoin = "LEFT JOIN (SELECT user_id, COUNT(DISTINCT DATE(`{$photoTs}`)) days FROM WC2026_Filter_Photos GROUP BY user_id) st ON st.user_id = u.id";
        $stSel  = "COALESCE(st.days,0) AS studio_days, (COALESCE(st.days,0) * " . WC_STUDIO_DAILY_PTS . ") AS studio_points";
        $stAdd  = " + (COALESCE(st.days,0) * " . WC_STUDIO_DAILY_PTS . ")";
    }

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
            {$stSel},
            (COALESCE(gs.pts,0) + COALESCE(pr.pts,0){$stAdd}) AS total_points
        FROM WC2026_Users u
        LEFT JOIN (SELECT user_id, SUM(total_points) pts, COUNT(*) plays FROM WC2026_Game_Sessions GROUP BY user_id) gs ON gs.user_id = u.id
        LEFT JOIN (SELECT user_id, COUNT(*) preds, SUM(points_calculated) scored, SUM(points_awarded) pts FROM WC2026_Predictions GROUP BY user_id) pr ON pr.user_id = u.id
        LEFT JOIN (SELECT user_id, COUNT(*) posts FROM WC2026_Fan_Wall GROUP BY user_id) fw ON fw.user_id = u.id
        {$stJoin}
        WHERE 1=1 {$uw} {$dw}
        ORDER BY total_points DESC, u.created_at DESC
        {$lim}
    ", $types, $params);
}

/* ---------- per-user behaviour & experience (one row per user, all metrics) ---------- */
function userBehavior(int $limit = 500): array {
    global $predTs, $reactTs, $photoTs;
    $types = ''; $params = [];

    /* Date clauses are appended in the SAME order they appear in the SQL below so
       the bound params line up; user-attribute filters bind last (outer WHERE). */
    $dGame = dWhere('played_at', $types, $params);                               // game sessions
    $dPred = $predTs !== '' ? dWhere("`{$predTs}`", $types, $params) : '';        // predictions
    $dRe   = $reactTs !== '' ? dWhere("`{$reactTs}`", $types, $params) : '';      // reactions
    $dCo   = dWhere('created_at', $types, $params);                               // comments
    $dFw   = dWhere('created_at', $types, $params);                               // fan wall
    $dLg   = dWhere('created_at', $types, $params);                               // logins (audit)

    /* Studio photos: only join when the table/timestamp exist (else show 0). */
    $phSel = '0 AS studio_photos, 0 AS studio_days'; $phJoin = '';
    if ($photoTs !== '') {
        $dPh   = dWhere("`{$photoTs}`", $types, $params);
        $phSel = 'COALESCE(ph.cnt,0) AS studio_photos, COALESCE(ph.days,0) AS studio_days';
        $phJoin = "LEFT JOIN (SELECT user_id, COUNT(*) cnt, COUNT(DISTINCT DATE(`{$photoTs}`)) days FROM WC2026_Filter_Photos WHERE 1=1 {$dPh} GROUP BY user_id) ph ON ph.user_id = u.id";
    }

    $uw = uWhere('u', $types, $params); // outer user-attribute filters bind last

    return q("
        SELECT
            u.id, u.full_name, u.prn, u.department, u.location, u.role, u.status, u.last_login_at,
            COALESCE(gs.plays,0)  AS game_plays,
            COALESCE(gs.pts,0)    AS game_score,
            COALESCE(pr.score_pts,0)   AS score_pts,
            COALESCE(pr.winner_pts,0)  AS winner_pts,
            COALESCE(pr.pred_points,0) AS pred_points,
            COALESCE(re.cnt,0)    AS reactions,
            COALESCE(co.cnt,0)    AS comments,
            COALESCE(fw.cnt,0)    AS wall_posts,
            COALESCE(lg.cnt,0)    AS logins,
            {$phSel}
        FROM WC2026_Users u
        LEFT JOIN (SELECT user_id, COUNT(*) plays, COALESCE(SUM(total_points),0) pts FROM WC2026_Game_Sessions WHERE 1=1 {$dGame} GROUP BY user_id) gs ON gs.user_id = u.id
        LEFT JOIN (SELECT user_id, SUM(CASE WHEN points_awarded = " . WC_PTS_PREDICT_SCORE . " THEN points_awarded ELSE 0 END) score_pts, SUM(CASE WHEN points_awarded = " . WC_PTS_PREDICT_WINNER . " THEN points_awarded ELSE 0 END) winner_pts, COALESCE(SUM(points_awarded),0) pred_points FROM WC2026_Predictions WHERE 1=1 {$dPred} GROUP BY user_id) pr ON pr.user_id = u.id
        LEFT JOIN (SELECT user_id, COUNT(*) cnt FROM WC2026_Match_Reactions WHERE 1=1 {$dRe} GROUP BY user_id) re ON re.user_id = u.id
        LEFT JOIN (SELECT user_id, COUNT(*) cnt FROM WC2026_Fan_Wall_Comments WHERE 1=1 {$dCo} GROUP BY user_id) co ON co.user_id = u.id
        LEFT JOIN (SELECT user_id, COUNT(*) cnt FROM WC2026_Fan_Wall WHERE 1=1 {$dFw} GROUP BY user_id) fw ON fw.user_id = u.id
        LEFT JOIN (SELECT user_id, COUNT(*) cnt FROM WC2026_Users_Audit_Log WHERE action_type='LOGIN' {$dLg} GROUP BY user_id) lg ON lg.user_id = u.id
        {$phJoin}
        WHERE 1=1 {$uw}
        ORDER BY (COALESCE(gs.pts,0) + COALESCE(pr.pred_points,0) + COALESCE(re.cnt,0) + COALESCE(co.cnt,0) + COALESCE(fw.cnt,0)) DESC, u.full_name ASC
        " . ($limit > 0 ? ('LIMIT ' . (int)$limit) : '') . "
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

/* ---------- XLSX export (real .xlsx via ZipArchive; no library needed) ---------- */
function xlsx_out(string $filename, array $headers, array $rows): void {
    while (ob_get_level()) { ob_end_clean(); }
    if (!class_exists('ZipArchive')) { // graceful fallback
        csv_out(preg_replace('/\.xlsx$/i', '.csv', $filename), $headers, $rows);
        return;
    }
    $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $colRef = function (int $n): string { $s = ''; $n++; while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); } return $s; };

    $buildRow = function (array $cells, int $rowNum) use ($esc, $colRef): string {
        $out = '<row r="' . $rowNum . '">';
        $ci = 0;
        foreach (array_values($cells) as $v) {
            $ref = $colRef($ci) . $rowNum; $ci++;
            $isNum = is_int($v) || is_float($v) || (is_string($v) && $v !== '' && preg_match('/^-?\d+(\.\d+)?$/', $v));
            if ($isNum) $out .= '<c r="' . $ref . '"><v>' . $esc($v) . '</v></c>';
            else        $out .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $esc($v) . '</t></is></c>';
        }
        return $out . '</row>';
    };

    $sheetData = $buildRow($headers, 1);
    $rn = 2;
    foreach ($rows as $r) { $sheetData .= $buildRow($r, $rn); $rn++; }

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
      . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
      . '<Default Extension="xml" ContentType="application/xml"/>'
      . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
      . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
      . '</Types>');
    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
      . '</Relationships>');
    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
      . '<sheets><sheet name="Data" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
      . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
      . '</Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheetData . '</sheetData></worksheet>');
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
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
                    'Game points','Game plays','Predictions','Predictions scored','Prediction points','Fan Wall posts',
                    'Studio days','Studio points','Total points'];
        $data = array_map(function ($r) {
            return [$r['id'],$r['full_name'],$r['mobile'],$r['email'],$r['department'],$r['location'],$r['role'],$r['status'],
                    $r['created_at'],$r['last_login_at'],$r['game_points'],$r['game_plays'],$r['predictions'],
                    $r['predictions_scored'],$r['prediction_points'],$r['fanwall_posts'],
                    $r['studio_days'] ?? 0,$r['studio_points'] ?? 0,$r['total_points']];
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

    if ($ex === 'behavior') {
        $rows = userBehavior(0); // all users
        usort($rows, fn($a, $b) => behaviorPoints($b) <=> behaviorPoints($a));
        $headers = ['ID','PRN','Full name','Department','Location','Role','Status','Last login',
                    'Score pts','Winner pts','Champion pts','Reactions','Comments','Wall posts','Game score','Game plays','Logins','Studio pts','Total points'];
        $data = array_map(function ($r) {
            return [$r['id'],$r['prn'] ?? '',$r['full_name'],$r['department'],$r['location'],$r['role'],$r['status'],$r['last_login_at'],
                    $r['score_pts'],$r['winner_pts'],predChampionPoints($r),$r['reactions'],$r['comments'],$r['wall_posts'],
                    $r['game_score'],$r['game_plays'],$r['logins'],studioPoints($r),behaviorPoints($r)];
        }, $rows);
        if (strtolower((string)($_GET['fmt'] ?? '')) === 'xlsx') {
            xlsx_out("wc2026_behavior_{$date}.xlsx", $headers, $data);
        }
        csv_out("wc2026_behavior_{$date}.csv", $headers, $data);
    }
}

/* ---------- Re-score predictions to the CURRENT scoring values ----------
   The lazy scorer only re-scores when a user reopens a finished match, so after
   a change to the point values existing points_awarded are stale. This admin
   action recomputes points_awarded for every prediction on a finished match
   (using the actual result from wc_fixtures) at the current constants. */
if (isset($_GET['rescore']) && $_GET['rescore'] === 'predictions') {
    $updated = 0; $checked = 0;
    $rows = q("
        SELECT p.user_id, p.match_id, p.predicted_home_score, p.predicted_away_score,
               COALESCE(f.goals_home, f.ft_home) AS ah, COALESCE(f.goals_away, f.ft_away) AS aa
        FROM WC2026_Predictions p
        JOIN wc_fixtures f ON f.fixture_id = p.match_id
        WHERE f.status_short IN ('FT','AET','PEN')
          AND COALESCE(f.goals_home, f.ft_home) IS NOT NULL
          AND COALESCE(f.goals_away, f.ft_away) IS NOT NULL");
    $up = $conn->prepare("UPDATE WC2026_Predictions SET points_awarded = ?, points_calculated = 1, updated_at = NOW()
                          WHERE user_id = ? AND match_id = ?");
    foreach ($rows as $r) {
        $checked++;
        $ah = (int)$r['ah']; $aa = (int)$r['aa'];
        $ph = (int)$r['predicted_home_score']; $pa = (int)$r['predicted_away_score'];
        $aw = $ah > $aa ? 'H' : ($aa > $ah ? 'A' : 'D');   // actual winner
        $pw = $ph > $pa ? 'H' : ($pa > $ph ? 'A' : 'D');   // predicted winner (from predicted score)
        if ($ph === $ah && $pa === $aa)      $pts = WC_PTS_PREDICT_SCORE;   // exact score
        elseif ($pw === $aw)                 $pts = WC_PTS_PREDICT_WINNER;  // correct winner only
        else                                 $pts = 0;
        if ($up) {
            $uid = (int)$r['user_id']; $mid = (int)$r['match_id'];
            $up->bind_param('iii', $pts, $uid, $mid);
            if ($up->execute()) $updated++;
        }
    }
    if ($up) $up->close();
    header('Location: /WC2026/?view=admin&rescored=' . $updated . '&checked=' . $checked);
    exit;
}

/* Auto re-score: keep prediction points in sync with the CURRENT scoring values
   on every admin load — no button needed. One efficient UPDATE...JOIN that only
   writes rows whose value would actually change (idempotent + cheap). */
try {
    $sc = (int)WC_PTS_PREDICT_SCORE; $wn = (int)WC_PTS_PREDICT_WINNER;
    $caseExpr = "CASE
            WHEN p.predicted_home_score = COALESCE(f.goals_home, f.ft_home)
             AND p.predicted_away_score = COALESCE(f.goals_away, f.ft_away) THEN {$sc}
            WHEN SIGN(p.predicted_home_score - p.predicted_away_score)
               = SIGN(COALESCE(f.goals_home, f.ft_home) - COALESCE(f.goals_away, f.ft_away)) THEN {$wn}
            ELSE 0 END";
    @$conn->query("
        UPDATE WC2026_Predictions p
        JOIN wc_fixtures f ON f.fixture_id = p.match_id
        SET p.points_awarded = {$caseExpr}, p.points_calculated = 1, p.updated_at = NOW()
        WHERE f.status_short IN ('FT','AET','PEN')
          AND COALESCE(f.goals_home, f.ft_home) IS NOT NULL
          AND COALESCE(f.goals_away, f.ft_away) IS NOT NULL
          AND (p.points_calculated = 0 OR p.points_awarded <> ({$caseExpr}))
    ");
} catch (Throwable $e) { /* ignore — predictions/fixtures table may be absent */ }

/* ================= KPIs (all respect the active filters) ================= */
/* Users — user-attribute filters + registration date range. */
$t=''; $p=[]; $uw=uWhere('',$t,$p); $dw=dWhere('created_at',$t,$p);
$kpiTotalUsers   = (int) qv("SELECT COUNT(*) FROM WC2026_Users WHERE 1=1 {$uw} {$dw}", $t,$p);
$t=''; $p=[]; $uw=uWhere('',$t,$p); $dw=dWhere('created_at',$t,$p);
$kpiActiveUsers  = (int) qv("SELECT COUNT(*) FROM WC2026_Users WHERE status='Active' {$uw} {$dw}", $t,$p);
$t=''; $p=[]; $uw=uWhere('',$t,$p);
$kpiOnline24h    = (int) qv("SELECT COUNT(*) FROM WC2026_Users WHERE last_login_at >= NOW() - INTERVAL 24 HOUR {$uw}", $t,$p);

/* Activity — date range on each table's own timestamp + user filters via join. */
$kpiGamePlays    = act('WC2026_Game_Sessions', 'g', 'played_at');
$kpiGamePoints   = act('WC2026_Game_Sessions', 'g', 'played_at', 'COALESCE(SUM(g.total_points),0)');
$kpiPredictions  = act('WC2026_Predictions',   'pr', $predTs);
$kpiPredPoints   = act('WC2026_Predictions',   'pr', $predTs, 'COALESCE(SUM(pr.points_awarded),0)');

$kpiFanPosts     = act('WC2026_Fan_Wall',          'fw', 'created_at', 'COUNT(*)', "AND fw.status='Active'");
$kpiFanComments  = act('WC2026_Fan_Wall_Comments', 'fc', 'created_at', 'COUNT(*)', "AND fc.status='Active'");
$kpiFanLikes     = act('WC2026_Fan_Wall_Likes',    'fl', 'created_at');
$kpiStudioPhotos = act('WC2026_Filter_Photos',     'ph', $photoTs);
$kpiReactions    = act('WC2026_Match_Reactions',   'mr', $reactTs);

/* Participants — distinct users with any activity, honouring the same filters. */
$t=''; $p=[];
$uwG=uWhere('u',$t,$p); $dwG=dWhere('g.played_at',$t,$p);
$uwP=uWhere('u',$t,$p); $dwP=$predTs!==''?dWhere("pr.`{$predTs}`",$t,$p):'';
$uwF=uWhere('u',$t,$p); $dwF=dWhere('fw.created_at',$t,$p);
$kpiParticipants = (int) qv("
    SELECT COUNT(*) FROM (
        SELECT g.user_id  FROM WC2026_Game_Sessions g JOIN WC2026_Users u ON u.id=g.user_id  WHERE 1=1 {$uwG} {$dwG}
        UNION SELECT pr.user_id FROM WC2026_Predictions pr JOIN WC2026_Users u ON u.id=pr.user_id WHERE 1=1 {$uwP} {$dwP}
        UNION SELECT fw.user_id FROM WC2026_Fan_Wall fw JOIN WC2026_Users u ON u.id=fw.user_id WHERE 1=1 {$uwF} {$dwF}
    ) t", $t,$p);

/* ================= chart datasets ================= */
$charts = [];

/* Registrations over time */
$t=''; $p=[]; $dw=dWhere('created_at',$t,$p); $w=uWhere('',$t,$p);
$charts['reg'] = q("SELECT DATE(created_at) d, COUNT(*) c FROM WC2026_Users WHERE 1=1 {$dw} {$w} GROUP BY DATE(created_at) ORDER BY d", $t,$p);

/* Logins over time (audit log) */
$t=''; $p=[]; $dw=dWhere('created_at',$t,$p);
$charts['login'] = q("SELECT DATE(created_at) d, COUNT(*) c FROM WC2026_Users_Audit_Log WHERE action_type='LOGIN' {$dw} GROUP BY DATE(created_at) ORDER BY d", $t,$p);

/* Users by segment — joined to the unified employee master list. The segment
   column name varies across deployments, so detect it; if the master list is
   unavailable the widget simply blanks instead of erroring. */
$mlSegCol = first_col('Unified_Employees_MasterList',
    ['segment','segment_name','department','dept','section','division','business_unit','businessunit','unit','dept_name','sector']);
$t=''; $p=[]; $w=uWhere('u',$t,$p);
if ($mlSegCol !== '') {
    /* WC2026_Users and the master list share a PRN, so join on that (email rarely
       matches). Only users actually present in the master list get a segment. */
    $charts['segment'] = q("
        SELECT COALESCE(NULLIF(m.`{$mlSegCol}`,''),'—') k, COUNT(*) c
        FROM WC2026_Users u
        JOIN Unified_Employees_MasterList m
          ON (u.prn IS NOT NULL AND u.prn <> '' AND m.PRN = u.prn)
        WHERE 1=1 {$w}
        GROUP BY k ORDER BY c DESC LIMIT 15", $t,$p);
} else {
    $charts['segment'] = [];
}

/* Users by location */
$t=''; $p=[]; $w=uWhere('',$t,$p);
$charts['loc']    = q("SELECT COALESCE(NULLIF(location,''),'—') k, COUNT(*) c FROM WC2026_Users WHERE 1=1 {$w} GROUP BY k ORDER BY c DESC LIMIT 12", $t,$p);

/* Site revisit recurrence — distribution of how many times each user has logged
   in (from the audit log), bucketed; replaces the old "users by status" chart. */
$t=''; $p=[]; $dw=dWhere('created_at',$t,$p);
$charts['revisit'] = q("
    SELECT
        CASE WHEN logins = 1      THEN '1 visit'
             WHEN logins <= 3     THEN '2–3 visits'
             WHEN logins <= 6     THEN '4–6 visits'
             WHEN logins <= 12    THEN '7–12 visits'
             ELSE '13+ visits' END AS k,
        COUNT(*) c,
        MIN(logins) ord
    FROM (
        SELECT user_id, COUNT(*) logins
        FROM WC2026_Users_Audit_Log
        WHERE action_type = 'LOGIN' {$dw}
        GROUP BY user_id
    ) t
    GROUP BY k ORDER BY ord", $t,$p);

/* Game sessions per day + predictions per day + fan wall per day */
$t=''; $p=[]; $dw=dWhere('played_at',$t,$p);
$charts['game'] = q("SELECT DATE(played_at) d, COUNT(*) c, COALESCE(SUM(total_points),0) pts FROM WC2026_Game_Sessions WHERE 1=1 {$dw} GROUP BY DATE(played_at) ORDER BY d", $t,$p);
/* Predictions per day — bind to whatever timestamp column the table uses so the
   line actually populates (was blank when the column wasn't named created_at). */
if ($predTs !== '') {
    $t=''; $p=[]; $dw=dWhere("`{$predTs}`",$t,$p);
    $charts['pred'] = q("SELECT DATE(`{$predTs}`) d, COUNT(*) c FROM WC2026_Predictions WHERE 1=1 {$dw} GROUP BY DATE(`{$predTs}`) ORDER BY d", $t,$p);
} else {
    $charts['pred'] = [];
}
$t=''; $p=[]; $dw=dWhere('created_at',$t,$p);
$charts['fanwall'] = q("SELECT DATE(created_at) d, COUNT(*) c FROM WC2026_Fan_Wall WHERE 1=1 {$dw} GROUP BY DATE(created_at) ORDER BY d", $t,$p);

/* Predicted-winner distribution */
$charts['winner'] = q("SELECT predicted_winner k, COUNT(*) c FROM WC2026_Predictions WHERE predicted_winner IS NOT NULL GROUP BY predicted_winner ORDER BY c DESC");

/* Match reaction breakdown */
$charts['reactions'] = q("SELECT reaction k, COUNT(*) c FROM WC2026_Match_Reactions GROUP BY reaction ORDER BY c DESC");

/* Studio photos over time — from the studio table WC2026_Filter_Photos. */
if ($photoTs !== '') {
    $t=''; $p=[]; $dw=dWhere("`{$photoTs}`",$t,$p);
    $charts['photos'] = q("SELECT DATE(`{$photoTs}`) d, COUNT(*) c FROM WC2026_Filter_Photos WHERE 1=1 {$dw} GROUP BY DATE(`{$photoTs}`) ORDER BY d", $t,$p);
} else {
    $charts['photos'] = q("SELECT DATE(created_at) d, COUNT(*) c FROM WC2026_Filter_Photos GROUP BY DATE(created_at) ORDER BY d");
}

/* Studio photos created by country — WC2026_Filter_Photos joined to the
   WC2026_Filter_Countries lookup on the saved country_id. */
if ($photoCty !== '') {
    $t=''; $p=[]; $dw=$photoTs!==''?dWhere("ph.`{$photoTs}`",$t,$p):'';
    $charts['photoCountry'] = q("
        SELECT COALESCE(NULLIF(c.country_name,''),'—') k, COUNT(*) c
        FROM WC2026_Filter_Photos ph
        LEFT JOIN WC2026_Filter_Countries c ON c.id = ph.`{$photoCty}`
        WHERE 1=1 {$dw}
        GROUP BY k ORDER BY c DESC LIMIT 15", $t,$p);
} else {
    $charts['photoCountry'] = [];
}

/* Top participants (leaderboard) */
$leaders = participants(15);

/* Per-user behaviour & experience (all metrics, one row per user — all users) */
$behavior = userBehavior(0);
usort($behavior, fn($a, $b) => behaviorPoints($b) <=> behaviorPoints($a));

/* ---------- actual records: comments / reactions / predictions ---------- */
/* fixture_id -> "Home vs Away" label (defensive: blank if wc_fixtures absent). */
$fxMap = [];
foreach (q("SELECT fixture_id, home_name, away_name FROM wc_fixtures") as $f) {
    $hn = trim((string)($f['home_name'] ?? '')); $an = trim((string)($f['away_name'] ?? ''));
    if ($hn !== '' || $an !== '') $fxMap[(int)$f['fixture_id']] = ($hn ?: 'TBA') . ' vs ' . ($an ?: 'TBA');
}
function adminMatchLabel(array $map, $mid): string {
    $mid = (int)$mid;
    return $map[$mid] ?? ('Match #' . $mid);
}
function adminWhen($ts): string {
    $ts = trim((string)$ts);
    if ($ts === '') return '—';
    $t = strtotime($ts);
    return $t ? date('d M · H:i', $t) : $ts;
}

/* Recent comments — actual rows with author + status. */
$t=''; $p=[]; $dw=dWhere('c.created_at',$t,$p);
$recentComments = q("SELECT c.id, c.post_id, c.author_name, c.body, c.status, c.created_at, u.full_name
                     FROM WC2026_Fan_Wall_Comments c
                     LEFT JOIN WC2026_Users u ON u.id = c.user_id
                     WHERE 1=1 {$dw}
                     ORDER BY c.created_at DESC LIMIT 60", $t,$p);

/* Recent reactions — actual rows with user + match. */
$rTs = $reactTs !== '' ? $reactTs : 'created_at';
$t=''; $p=[]; $dw=dWhere("r.`{$rTs}`",$t,$p);
$recentReactions = q("SELECT r.reaction, r.match_id, r.`{$rTs}` AS ts, u.full_name
                      FROM WC2026_Match_Reactions r
                      LEFT JOIN WC2026_Users u ON u.id = r.user_id
                      WHERE 1=1 {$dw}
                      ORDER BY r.`{$rTs}` DESC LIMIT 60", $t,$p);

/* Recent predictions — actual rows with user + match + points. Only CORRECT ones
   (points_awarded > 0: correct winner / score / champion). */
$pTs = $predTs !== '' ? $predTs : 'id';
$t=''; $p=[]; $dw=$predTs!==''?dWhere("p.`{$predTs}`",$t,$p):'';
$recentPredictions = q("SELECT p.match_id, p.predicted_home_score, p.predicted_away_score, p.predicted_winner,
                               p.points_awarded, p.points_calculated, p.`{$pTs}` AS ts, u.full_name
                        FROM WC2026_Predictions p
                        LEFT JOIN WC2026_Users u ON u.id = p.user_id
                        WHERE p.points_awarded > 0 {$dw}
                        ORDER BY p.`{$pTs}` DESC LIMIT 60", $t,$p);

$reactEmoji = ['like'=>'👍','fire'=>'🔥','goal'=>'⚽','heart'=>'❤️','love'=>'❤️','wow'=>'😮','clap'=>'👏'];

/* ---------- helper to split rows into JS arrays ---------- */
function xy(array $rows, string $kx, string $ky): array {
    $labels=[]; $data=[];
    foreach ($rows as $r) { $labels[] = (string)$r[$kx]; $data[] = (int)$r[$ky]; }
    return ['labels'=>$labels, 'data'=>$data];
}

$JS = [
    'reg'          => xy($charts['reg'], 'd', 'c'),
    'login'        => xy($charts['login'], 'd', 'c'),
    'segment'      => xy($charts['segment'], 'k', 'c'),
    'loc'          => xy($charts['loc'], 'k', 'c'),
    'revisit'      => xy($charts['revisit'], 'k', 'c'),
    'game'         => xy($charts['game'], 'd', 'c'),
    'gamePts'      => xy($charts['game'], 'd', 'pts'),
    'pred'         => xy($charts['pred'], 'd', 'c'),
    'fanwall'      => xy($charts['fanwall'], 'd', 'c'),
    'winner'       => xy($charts['winner'], 'k', 'c'),
    'reactions'    => xy($charts['reactions'], 'k', 'c'),
    'photos'       => xy($charts['photos'], 'd', 'c'),
    'photoCountry' => xy($charts['photoCountry'], 'k', 'c'),
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
.behav-toolbar{display:flex;align-items:center;gap:12px;margin:0 0 12px;flex-wrap:wrap}
.behav-search{flex:1;min-width:220px;min-height:42px;border-radius:12px;border:1px solid var(--line);background:rgba(255,255,255,.06);
    color:#fff;padding:9px 14px;font-family:inherit;font-weight:700;font-size:13px}
.behav-search::placeholder{color:rgba(234,244,255,.5)}
.behav-count{font-size:12px;font-weight:800;color:var(--muted)}
.rescore-note{margin:12px 4px 0;padding:11px 15px;border-radius:13px;font-weight:800;font-size:13px;color:#062417;background:linear-gradient(135deg,#7EF4AE,#22C55E);box-shadow:0 10px 22px rgba(34,197,94,.35)}
#behavTable tbody tr.hide{display:none}
.score-card h3{margin:0 0 10px}
.score-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:9px}
.score-list li{display:flex;align-items:center;gap:11px;padding:9px 11px;border-radius:12px;background:rgba(255,255,255,.04);border:1px solid var(--line)}
.score-ico{font-size:18px;flex:0 0 auto;width:26px;text-align:center}
.score-label{flex:1;font-size:12.5px;font-weight:800;color:#eaf6ff}
.score-pts{flex:0 0 auto;font-weight:900;font-size:12.5px;color:#06202e;background:linear-gradient(135deg,#F5C85B,#FFE19A);padding:4px 11px;border-radius:999px;white-space:nowrap}
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
        <input type="hidden" name="view" value="admin"><!-- keep the admin view when served via /WC2026/?view=admin -->
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
        <a class="btn" href="/WC2026/?view=admin">Reset</a>
        <span style="flex:1"></span>
        <a class="btn btn-gold" href="?export=users&<?= h(qstr()) ?>">⬇ Export users</a>
        <a class="btn btn-gold" href="?export=participants&<?= h(qstr()) ?>">⬇ Export participants</a>
        <a class="btn btn-gold" href="?export=studio&<?= h(qstr()) ?>">⬇ Export studio</a>
        <a class="btn btn-gold" href="?export=behavior&<?= h(qstr()) ?>">⬇ Behaviour CSV</a>
        <a class="btn btn-gold" href="?export=behavior&fmt=xlsx&<?= h(qstr()) ?>">⬇ Behaviour Excel</a>
        <a class="btn btn-blue" href="/WC2026/?view=admin&rescore=predictions"
           onclick="return confirm('Recompute points for ALL finished-match predictions using the current scoring values (winner <?= (int)WC_PTS_PREDICT_WINNER ?> / score <?= (int)WC_PTS_PREDICT_SCORE ?>)?');">♻ Re-score predictions</a>
    </form>
    <?php if (isset($_GET['rescored'])): ?>
        <div class="rescore-note">✅ Re-scored <b><?= (int)$_GET['rescored'] ?></b> of <?= (int)($_GET['checked'] ?? 0) ?> finished-match predictions to the current values (winner <?= (int)WC_PTS_PREDICT_WINNER ?> / score <?= (int)WC_PTS_PREDICT_SCORE ?>).</div>
    <?php endif; ?>

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
        <div class="card col-12"><h3>Registrations & logins <small>per day</small></h3><div class="chart-box"><canvas id="cReg"></canvas></div></div>
        <div class="card col-6"><h3>Game plays & points <small>per day</small></h3><div class="chart-box"><canvas id="cGame"></canvas></div></div>
        <div class="card col-6"><h3>Predictions & Fan Wall <small>per day</small></h3><div class="chart-box"><canvas id="cEngage"></canvas></div></div>
    </div>

    <!-- Demographics -->
    <h2 class="section">Users & demographics</h2>
    <div class="grid">
        <div class="card col-6"><h3>Users by segment <small>unified master list</small></h3><div class="chart-box"><canvas id="cSegment"></canvas></div></div>
        <div class="card col-6"><h3>Users by location</h3><div class="chart-box"><canvas id="cLoc"></canvas></div></div>
        <div class="card col-4"><h3>Site revisit recurrence <small>logins / user</small></h3><div class="chart-box chart-sm"><canvas id="cRevisit"></canvas></div></div>
        <div class="card col-4"><h3>Predicted winners</h3><div class="chart-box chart-sm"><canvas id="cWinner"></canvas></div></div>
        <div class="card col-4"><h3>Match reactions</h3><div class="chart-box chart-sm"><canvas id="cReact"></canvas></div></div>
    </div>

    <!-- Studio photos -->
    <h2 class="section">Studio photos</h2>
    <div class="grid">
        <div class="card col-6"><h3>Studio photos created <small>per day</small></h3><div class="chart-box"><canvas id="cPhotos"></canvas></div></div>
        <div class="card col-6"><h3>Studio photos by country</h3><div class="chart-box"><canvas id="cPhotoCountry"></canvas></div></div>
    </div>

    <!-- Leaderboard -->
    <h2 class="section">Top participants <small style="font-weight:700;color:var(--muted);font-size:11px">(by total points)</small></h2>
    <div class="card col-12">
        <div class="table-scroll">
        <table>
            <thead><tr>
                <th>#</th><th>Name</th><th>Dept</th><th>Location</th>
                <th>Game pts</th><th>Plays</th><th>Predictions</th><th>Pred. pts</th><th>Posts</th><th>Studio pts</th><th>Total</th>
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
                    <td><?= number_format((int)($r['studio_points'] ?? 0)) ?></td>
                    <td class="pts"><?= number_format((int)$r['total_points']) ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="11" class="empty">No participant data yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- Scoring system reference -->
    <?php
        $scoringGroups = [
            'Daily Game' => [
                ['🥅', 'Score a goal (by zone)', '+10 / +20 / +30'],
                ['⭐', 'Golden ball goal (bonus)', '+50'],
                ['🔥', 'Combo streak (every 3 / 5 goals)', '+20 / +50'],
                ['🎁', 'Daily food bonus roll', '+10 → +100'],
            ],
            'Predictions' => [
                ['🎯', 'Predict the match winner', '+' . number_format(WC_PTS_PREDICT_WINNER)],
                ['✅', 'Predict the correct score', '+' . number_format(WC_PTS_PREDICT_SCORE)],
                ['🏆', 'Predict the champion (Final only)', '+' . number_format(WC_PTS_PREDICT_CHAMPION)],
            ],
            'Fan Studio' => [
                ['📸', 'Fan Filter photo (once per day)', '+' . number_format(WC_PTS_PHOTO)],
            ],
        ];
    ?>
    <h2 class="section">Scoring system <small style="font-weight:700;color:var(--muted);font-size:11px">(how points are earned)</small></h2>
    <div class="grid">
        <?php foreach ($scoringGroups as $groupName => $rules): ?>
            <div class="card col-4 score-card">
                <h3><?= h($groupName) ?></h3>
                <ul class="score-list">
                    <?php foreach ($rules as $rule): ?>
                        <li>
                            <span class="score-ico"><?= $rule[0] ?></span>
                            <span class="score-label"><?= h($rule[1]) ?></span>
                            <span class="score-pts"><?= h($rule[2]) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- User behaviour & experience -->
    <h2 class="section">User behaviour &amp; experience</h2>
    <div class="card col-12">
        <div class="behav-toolbar">
            <input type="search" id="behavSearch" class="behav-search" placeholder="🔎 Search name / PRN / department / location…" autocomplete="off">
            <span class="behav-count" id="behavCount"><?= count($behavior) ?> users</span>
            <a class="btn btn-gold" href="?export=behavior&fmt=xlsx&<?= h(qstr()) ?>">⬇ Save as Excel (.xlsx)</a>
            <a class="btn" href="?export=behavior&<?= h(qstr()) ?>">⬇ CSV</a>
        </div>
        <div class="table-scroll">
        <table id="behavTable">
            <thead><tr>
                <th>#</th><th>User</th><th>PRN</th><th>Dept</th><th>Location</th>
                <th title="Points from exact correct scores (+5 each)">Score pts</th><th title="Points from correct winners (+3 each)">Winner pts</th><th title="Champion pick (+15) / other prediction points">Champion pts</th>
                <th>Reactions</th><th>Comments</th><th>Wall</th>
                <th title="Game score (points)">Game score</th><th title="Game plays">Plays</th>
                <th>Logins</th><th title="Studio photo points (once/day +10)">Studio pts</th>
                <th title="Score + Winner + Champion + Game score + Studio">Total points</th>
            </tr></thead>
            <tbody>
            <?php if ($behavior): foreach ($behavior as $i => $r): ?>
                <tr data-search="<?= h(strtolower(($r['full_name'] ?? '').' '.($r['prn'] ?? '').' '.($r['department'] ?? '').' '.($r['location'] ?? ''))) ?>">
                    <td><span class="rank"><?= $i+1 ?></span></td>
                    <td><?= h($r['full_name'] ?: '—') ?></td>
                    <td><?= h($r['prn'] ?: '—') ?></td>
                    <td><?= h($r['department'] ?: '—') ?></td>
                    <td><?= h($r['location'] ?: '—') ?></td>
                    <td><?= number_format((int)$r['score_pts']) ?></td>
                    <td><?= number_format((int)$r['winner_pts']) ?></td>
                    <td><?= number_format(predChampionPoints($r)) ?></td>
                    <td><?= number_format((int)$r['reactions']) ?></td>
                    <td><?= number_format((int)$r['comments']) ?></td>
                    <td><?= number_format((int)$r['wall_posts']) ?></td>
                    <td class="pts"><?= number_format((int)$r['game_score']) ?></td>
                    <td><?= number_format((int)$r['game_plays']) ?></td>
                    <td><?= number_format((int)$r['logins']) ?></td>
                    <td><?= number_format((int)($r['studio_days'] ?? 0) * WC_PTS_PHOTO) ?></td>
                    <td class="pts"><?= number_format(behaviorPoints($r)) ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="16" class="empty">No user data for the selected filters.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        <div class="behav-empty empty" id="behavNoMatch" style="display:none">No users match your search.</div>
    </div>

    <!-- Actual records: predictions / reactions / comments -->
    <h2 class="section">Correct predictions <small style="font-weight:700;color:var(--muted);font-size:11px">(scored only · latest 60)</small></h2>
    <div class="card col-12">
        <div class="table-scroll">
        <table>
            <thead><tr><th>#</th><th>User</th><th>Match</th><th>Prediction</th><th>Winner</th><th>Points</th><th>Scored</th><th>When</th></tr></thead>
            <tbody>
            <?php if ($recentPredictions): foreach ($recentPredictions as $i => $r): ?>
                <tr>
                    <td><span class="rank"><?= $i+1 ?></span></td>
                    <td><?= h($r['full_name'] ?: '—') ?></td>
                    <td><?= h(adminMatchLabel($fxMap, $r['match_id'])) ?></td>
                    <td><?= (int)$r['predicted_home_score'] ?> - <?= (int)$r['predicted_away_score'] ?></td>
                    <td><?= h($r['predicted_winner'] ?: '—') ?></td>
                    <td class="pts"><?= number_format((int)$r['points_awarded']) ?></td>
                    <td><?= ((int)$r['points_calculated'] === 1) ? '✅' : '⏳' ?></td>
                    <td><?= h(adminWhen($r['ts'])) ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="8" class="empty">No correct predictions yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <h2 class="section">Match reactions <small style="font-weight:700;color:var(--muted);font-size:11px">(latest 60)</small></h2>
    <div class="card col-12">
        <div class="table-scroll">
        <table>
            <thead><tr><th>#</th><th>User</th><th>Match</th><th>Reaction</th><th>When</th></tr></thead>
            <tbody>
            <?php if ($recentReactions): foreach ($recentReactions as $i => $r): ?>
                <?php $rk = strtolower(trim((string)$r['reaction'])); ?>
                <tr>
                    <td><span class="rank"><?= $i+1 ?></span></td>
                    <td><?= h($r['full_name'] ?: '—') ?></td>
                    <td><?= h(adminMatchLabel($fxMap, $r['match_id'])) ?></td>
                    <td><?= ($reactEmoji[$rk] ?? '') ?> <?= h($r['reaction'] ?: '—') ?></td>
                    <td><?= h(adminWhen($r['ts'])) ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="5" class="empty">No reactions yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <h2 class="section">Fan Wall comments <small style="font-weight:700;color:var(--muted);font-size:11px">(latest 60)</small></h2>
    <div class="card col-12">
        <div class="table-scroll">
        <table>
            <thead><tr><th>#</th><th>Author</th><th>Comment</th><th>Post</th><th>Status</th><th>When</th></tr></thead>
            <tbody>
            <?php if ($recentComments): foreach ($recentComments as $i => $r): ?>
                <tr>
                    <td><span class="rank"><?= $i+1 ?></span></td>
                    <td><?= h(($r['full_name'] ?: $r['author_name']) ?: '—') ?></td>
                    <td style="white-space:normal;max-width:520px"><?= h(mb_strimwidth((string)$r['body'], 0, 160, '…')) ?></td>
                    <td>#<?= (int)$r['post_id'] ?></td>
                    <td><?= h($r['status'] ?: '—') ?></td>
                    <td><?= h(adminWhen($r['created_at'])) ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="6" class="empty">No comments yet.</td></tr>
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

/* Segment — unified master list (bar) */
mk('cSegment',{type:'bar',data:{labels:D.segment.labels,datasets:[{label:'Users',data:D.segment.data,backgroundColor:PAL,borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,scales:{x:GRID,y:{...GRID,beginAtZero:true}},plugins:{legend:{display:false}}}});

/* Location (horizontal bar) */
mk('cLoc',{type:'bar',data:{labels:D.loc.labels,datasets:[{label:'Users',data:D.loc.data,backgroundColor:'#55B7FF',borderRadius:6}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,scales:{x:{...GRID,beginAtZero:true},y:GRID},plugins:{legend:{display:false}}}});

/* Site revisit recurrence (bar) */
mk('cRevisit',{type:'bar',data:{labels:D.revisit.labels,datasets:[{label:'Users',data:D.revisit.data,backgroundColor:'#7EF4AE',borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,scales:{x:GRID,y:{...GRID,beginAtZero:true,ticks:{precision:0}}},plugins:{legend:{display:false}}}});

/* Predicted winner (pie) */
mk('cWinner',{type:'pie',data:{labels:D.winner.labels,datasets:[{data:D.winner.data,backgroundColor:PAL,borderWidth:1,borderColor:'rgba(0,0,0,.2)'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}});

/* Reactions (doughnut) */
mk('cReact',{type:'doughnut',data:{labels:D.reactions.labels,datasets:[{data:D.reactions.data,backgroundColor:PAL,borderWidth:1,borderColor:'rgba(0,0,0,.2)'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}});

/* Studio photos per day (line) */
mk('cPhotos',{type:'line',data:{labels:D.photos.labels,datasets:[{label:'Photos',data:D.photos.data,borderColor:'#F5C85B',backgroundColor:'rgba(245,200,91,.16)',fill:true,tension:.35,pointRadius:2}]},options:{responsive:true,maintainAspectRatio:false,scales:{x:GRID,y:{...GRID,beginAtZero:true,ticks:{precision:0}}},plugins:{legend:{display:false}}}});

/* Studio photos by country (horizontal bar) */
mk('cPhotoCountry',{type:'bar',data:{labels:D.photoCountry.labels,datasets:[{label:'Photos',data:D.photoCountry.data,backgroundColor:PAL,borderRadius:6}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,scales:{x:{...GRID,beginAtZero:true,ticks:{precision:0}},y:GRID},plugins:{legend:{display:false}}}});

/* User behaviour table — live client-side search */
(function(){
  var input=document.getElementById('behavSearch'),
      table=document.getElementById('behavTable'),
      count=document.getElementById('behavCount'),
      noMatch=document.getElementById('behavNoMatch');
  if(!input||!table) return;
  var rows=[].slice.call(table.querySelectorAll('tbody tr[data-search]'));
  input.addEventListener('input',function(){
    var q=input.value.trim().toLowerCase(), shown=0;
    rows.forEach(function(tr){
      var hit = q==='' || (tr.getAttribute('data-search')||'').indexOf(q)!==-1;
      tr.classList.toggle('hide', !hit);
      if(hit) shown++;
    });
    if(count) count.textContent = shown + ' / ' + rows.length + ' users';
    if(noMatch) noMatch.style.display = (shown===0 && rows.length>0) ? 'block' : 'none';
  });
})();
</script>
</body>
</html>
