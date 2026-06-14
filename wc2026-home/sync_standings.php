<?php
/**
 * sync_standings.php — Sync FIFA World Cup group standings from API-Football
 * into db_form.wc_standings (so the home Group Stage table + Knockout Bracket
 * projections stay up to date, the same way wc_fixtures / wc_events are synced).
 *
 * HOW TO RUN
 *   CLI / cron (recommended — every ~10 min during the group stage):
 *     php /path/to/WC2026/sync_standings.php
 *     php /path/to/WC2026/sync_standings.php 1 2026      # league season override
 *   HTTP (guarded by a shared token; set SYNC_TOKEN in .env):
 *     https://your-site/WC2026/sync_standings.php?token=XXXX[&league=1&season=2026]
 *
 * CONFIG (.env, same loader/keys as worldcup_agent.php)
 *   APISPORTS_KEY=...        # API-Football key (x-apisports-key)
 *   WC_LEAGUE_ID=1           # FIFA World Cup league id (default 1)
 *   WC_SEASON=2026           # season (default 2026)
 *   SYNC_TOKEN=...           # required for HTTP runs; CLI runs are unrestricted
 *
 * BEHAVIOUR
 *   Full refresh per (league_id, season) inside a transaction: the rows for that
 *   league+season are replaced with the freshly fetched standings. Only columns
 *   that actually exist on wc_standings are written, so it adapts to the schema
 *   (grp / rank_pos / gd / gf / played / points / won / draw / lost / ga / form /
 *   team_logo / updated_at are filled when present).
 */

declare(strict_types=1);

$IS_CLI = (PHP_SAPI === 'cli');

/* ---- .env loader (same convention as worldcup_agent.php / EOL-review) ---- */
(function () {
    foreach (['/var/secrets/.env', __DIR__ . '/.env'] as $f) {
        if (!is_file($f) || !is_readable($f)) continue;
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $t = trim($line);
            if ($t === '' || $t[0] === '#' || strpos($t, '=') === false) continue;
            [$k, $v] = array_map('trim', explode('=', $t, 2));
            $v = trim($v, "\"'");
            if ($k !== '' && getenv($k) === false) { $_ENV[$k] = $v; putenv("$k=$v"); }
        }
    }
})();

define('APISPORTS_KEY', (string)(getenv('APISPORTS_KEY') ?: ($_ENV['APISPORTS_KEY'] ?? '')));
define('APISPORTS_BASE', 'https://v3.football.api-sports.io');
define('WC_LEAGUE_ID', (int)(getenv('WC_LEAGUE_ID') ?: ($_ENV['WC_LEAGUE_ID'] ?? 1)));
define('WC_SEASON',    (int)(getenv('WC_SEASON')    ?: ($_ENV['WC_SEASON']    ?? 2026)));
$SYNC_TOKEN = (string)(getenv('SYNC_TOKEN') ?: ($_ENV['SYNC_TOKEN'] ?? ''));

/** Emit a JSON result (or plain text on CLI) and exit. */
function out(array $d, int $code = 200): void {
    global $IS_CLI;
    if (!$IS_CLI) { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit;
}

/* ---- access control: CLI is open; HTTP requires the shared token ---- */
if (!$IS_CLI) {
    if ($SYNC_TOKEN === '' || !hash_equals($SYNC_TOKEN, (string)($_GET['token'] ?? ''))) {
        out(['ok' => false, 'error' => 'Forbidden. Run from CLI or pass a valid ?token= (SYNC_TOKEN).'], 403);
    }
}

if (APISPORTS_KEY === '') {
    out(['ok' => false, 'error' => 'APISPORTS_KEY is not configured in .env'], 503);
}

/* ---- league / season (optional overrides: GET params or CLI args) ---- */
$argLeague = ($IS_CLI && isset($argv[1])) ? $argv[1] : null;
$argSeason = ($IS_CLI && isset($argv[2])) ? $argv[2] : null;
$league = (int)($_GET['league'] ?? $argLeague ?? WC_LEAGUE_ID); if ($league <= 0) $league = WC_LEAGUE_ID;
$season = (int)($_GET['season'] ?? $argSeason ?? WC_SEASON);     if ($season <= 0) $season = WC_SEASON;

/* ---- DB connection (server-provided; same include as the rest of the app) ---- */
require __DIR__ . '/connections/config.php'; // provides $conn (mysqli)
if (!isset($conn) || !($conn instanceof mysqli)) {
    out(['ok' => false, 'error' => 'Database connection ($conn) not available'], 500);
}

/** GET an API-Football URL with the key header. */
function wc_fetch(string $url): string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => ['x-apisports-key: ' . APISPORTS_KEY],
        ]);
        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($res === false || $code >= 400) {
            out(['ok' => false, 'error' => 'API request failed' . ($err ? ': ' . $err : ''), 'http' => $code], 502);
        }
        return (string)$res;
    }
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 20,
        'header' => 'x-apisports-key: ' . APISPORTS_KEY . "\r\n"]]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res === false) out(['ok' => false, 'error' => 'API request failed (no curl)'], 502);
    return (string)$res;
}

/* ---- fetch standings ---- */
$url  = APISPORTS_BASE . '/standings?league=' . $league . '&season=' . $season;
$raw  = wc_fetch($url);
$json = json_decode($raw, true);
if (!is_array($json) || !array_key_exists('response', $json)) {
    out(['ok' => false, 'error' => 'Unexpected API response', 'raw' => mb_substr($raw, 0, 400)], 502);
}

/* API-Football: response[].league.standings is an array of groups, each an
   array of team entries. Flatten to a single list of entries. */
$entries = [];
foreach ((array)$json['response'] as $resp) {
    foreach ((array)($resp['league']['standings'] ?? []) as $group) {
        foreach ((array)$group as $entry) $entries[] = $entry;
    }
}
if (!$entries) {
    out(['ok' => true, 'synced' => 0, 'league' => $league, 'season' => $season,
         'note' => 'API returned no standings yet for this league/season.']);
}

/* ---- detect the columns that actually exist on wc_standings ---- */
$cols = [];
if ($res = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wc_standings'")) {
    while ($r = $res->fetch_assoc()) $cols[strtolower((string)$r['COLUMN_NAME'])] = (string)$r['COLUMN_NAME'];
    $res->free();
}
if (!$cols) out(['ok' => false, 'error' => 'wc_standings table/columns not found'], 500);

/** First candidate column name that exists (real casing), '' if none. */
function realcol(array $cols, array $cands): string {
    foreach ($cands as $c) if (isset($cols[strtolower($c)])) return $cols[strtolower($c)];
    return '';
}

/* Logical field => [candidate column names, type char, value extractor]. */
$nf = fn($v) => is_null($v) ? 0 : (int)$v; // default 0 so NOT NULL numeric columns are safe
$fieldMap = [
    'league_id' => [['league_id', 'league'],                    'i', fn($e) => $league],
    'season'    => [['season', 'year'],                         'i', fn($e) => $season],
    'grp'       => [['grp', 'group_name', 'group'],             's', fn($e) => (string)($e['group'] ?? '')],
    'rank_pos'  => [['rank_pos', 'rank', 'position'],           'i', fn($e) => $nf($e['rank'] ?? null)],
    'team_id'   => [['team_id', 'teamid'],                      'i', fn($e) => $nf($e['team']['id'] ?? null)],
    'team_name' => [['team_name', 'team'],                      's', fn($e) => (string)($e['team']['name'] ?? '')],
    'team_logo' => [['team_logo', 'logo'],                      's', fn($e) => (string)($e['team']['logo'] ?? '')],
    'played'    => [['played', 'games_played', 'matches_played'], 'i', fn($e) => $nf($e['all']['played'] ?? null)],
    'won'       => [['won', 'win', 'wins'],                     'i', fn($e) => $nf($e['all']['win'] ?? null)],
    'draw'      => [['draw', 'draws', 'drawn'],                 'i', fn($e) => $nf($e['all']['draw'] ?? null)],
    'lost'      => [['lost', 'lose', 'loses', 'losses'],        'i', fn($e) => $nf($e['all']['lose'] ?? null)],
    'gf'        => [['gf', 'goals_for', 'goalsfor'],            'i', fn($e) => $nf($e['all']['goals']['for'] ?? null)],
    'ga'        => [['ga', 'goals_against', 'goalsagainst'],    'i', fn($e) => $nf($e['all']['goals']['against'] ?? null)],
    'gd'        => [['gd', 'goal_difference', 'goalsdiff'],     'i', fn($e) => $nf($e['goalsDiff'] ?? null)],
    'points'    => [['points', 'pts'],                          'i', fn($e) => $nf($e['points'] ?? null)],
    'form'      => [['form'],                                   's', fn($e) => (string)($e['form'] ?? '')],
];

/* Resolve the concrete columns to write (preserving a stable order). */
$plan = []; // each: ['col'=>realName,'type'=>char,'val'=>callable]
foreach ($fieldMap as $logical => [$cands, $type, $getter]) {
    $rc = realcol($cols, $cands);
    if ($rc !== '') $plan[] = ['col' => $rc, 'type' => $type, 'val' => $getter];
}
$tsCol = realcol($cols, ['updated_at', 'last_api_sync', 'synced_at']); // written as NOW()

$leagueCol = realcol($cols, ['league_id', 'league']);
$seasonCol = realcol($cols, ['season', 'year']);
if ($plan === [] || realcol($cols, ['team_id', 'teamid']) === '') {
    out(['ok' => false, 'error' => 'wc_standings is missing the expected columns (need at least team_id).'], 500);
}

/* ---- build the INSERT ---- */
$colNames     = array_map(fn($p) => '`' . $p['col'] . '`', $plan);
$placeholders = array_fill(0, count($plan), '?');
if ($tsCol !== '') { $colNames[] = '`' . $tsCol . '`'; $placeholders[] = 'NOW()'; }
$insertSql = 'INSERT INTO `wc_standings` (' . implode(',', $colNames) . ') VALUES (' . implode(',', $placeholders) . ')';

$conn->begin_transaction();
try {
    /* Full refresh: clear this league+season, then insert the fresh set. */
    if ($leagueCol !== '' && $seasonCol !== '') {
        $del = $conn->prepare("DELETE FROM `wc_standings` WHERE `{$leagueCol}` = ? AND `{$seasonCol}` = ?");
        $del->bind_param('ii', $league, $season);
        $del->execute();
        $del->close();
    }

    $ins = $conn->prepare($insertSql);
    if (!$ins) throw new RuntimeException('prepare failed: ' . $conn->error);

    $types = implode('', array_map(fn($p) => $p['type'], $plan));
    $synced = 0; $skipped = 0;

    foreach ($entries as $e) {
        $vals = [];
        foreach ($plan as $p) $vals[] = ($p['val'])($e);
        // require a team id
        $tIdx = null;
        foreach ($plan as $i => $p) if (strtolower($p['col']) === 'team_id' || strtolower($p['col']) === 'teamid') { $tIdx = $i; break; }
        if ($tIdx !== null && empty($vals[$tIdx])) { $skipped++; continue; }
        $ins->bind_param($types, ...$vals);
        $ins->execute();
        $synced++;
    }
    $ins->close();
    $conn->commit();

    out(['ok' => true, 'league' => $league, 'season' => $season,
         'synced' => $synced, 'skipped' => $skipped,
         'columns' => array_map(fn($p) => $p['col'], $plan),
         'timestamp_col' => $tsCol ?: null,
         'at' => date('c')]);
} catch (Throwable $ex) {
    $conn->rollback();
    out(['ok' => false, 'error' => 'Sync failed: ' . $ex->getMessage()], 500);
}
