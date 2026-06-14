<?php
declare(strict_types=1);
/**
 * sync.php — World Cup 2026 ingestion (API-Football v3 → MySQL)
 * ------------------------------------------------------------------
 * Modes (CLI):  php sync.php schedule | live | standings | auto
 *   schedule   Full fixture pull (league=1, season=2026). Cheap relative to value.
 *              Run 1–2×/day. Captures final scores once matches reach FT.
 *   live       fixtures?live=1 — in-play scores + events. Goal events fire a
 *              Taqnyat SMS. ONLY viable on a paid plan (see quota note below).
 *   standings  standings?league=1&season=2026. Run hourly on matchdays.
 *   auto       Self-selects: live if a fixture is inside its window, else a
 *              once-per-day schedule refresh. Safe to run every minute from cron.
 *
 * QUOTA REALITY (free plan = 100 req/day + a per-minute cap):
 *   Per-minute live polling is impossible on free. This script reads the
 *   x-ratelimit headers after every call and refuses to spend a request when
 *   the daily remainder drops below SAFETY_FLOOR. On free, lean on `schedule`
 *   + `standings`; switch to `live` only with a paid key.
 *
 * API rules honoured (from API-Football v3 docs):
 *   - GET only; the ONLY header sent is x-apisports-key (extra headers error out).
 *   - /status does not count toward quota.
 *   - live=1 returns in-play fixtures for league 1 (World Cup).
 *
 * SECURITY: the keys you shared earlier are compromised — rotate WC_API_KEY,
 * the Taqnyat tokens and the DB password before running this in production.
 */

const SAFETY_FLOOR = 5;   // keep this many daily requests in reserve
const LIVE_PAD_PRE  = 10 * 60;   // start polling 10 min before kickoff
const LIVE_PAD_POST = 135 * 60;  // keep polling 2h15 after a kickoff
const HTTP_TIMEOUT  = 8;

// ---------------------------------------------------------------------
//  Bootstrap
// ---------------------------------------------------------------------
$ENV  = load_env(getenv('WC_ENV_PATH') ?: '/var/secrets/.env');
$mode = $argv[1] ?? 'auto';

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $ENV['DB_HOST'], $ENV['DB_PORT'] ?? '3306', $ENV['DB_NAME']),
    $ENV['DB_USER'], $ENV['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$API_BASE = 'https://' . trim($ENV['WC_URL'], '/');   // v3.football.api-sports.io
$API_KEY  = $ENV['WC_API_KEY'];
$LEAGUE   = (int)($ENV['WC_LEAGUE'] ?? 1);
$SEASON   = (int)($ENV['WC_SEASON'] ?? 2026);

if ($mode === 'auto') {
    $mode = pick_mode($pdo);
}

try {
    switch ($mode) {
        case 'schedule':  run_schedule($pdo, $API_BASE, $API_KEY, $LEAGUE, $SEASON); break;
        case 'live':      run_live($pdo, $ENV, $API_BASE, $API_KEY, $LEAGUE);        break;
        case 'standings': run_standings($pdo, $API_BASE, $API_KEY, $LEAGUE, $SEASON);break;
        case 'skip':      log_sync($pdo, 'skip', null, 0, 'outside all windows');    break;
        default: fwrite(STDERR, "unknown mode: $mode\n"); exit(2);
    }
} catch (Throwable $e) {
    log_sync($pdo, $mode, null, null, 'ERROR: ' . substr($e->getMessage(), 0, 200));
    fwrite(STDERR, '[sync] ' . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------------
//  Mode selection (auto)
// ---------------------------------------------------------------------
function pick_mode(PDO $pdo): string
{
    $now = time();
    // Any fixture whose live window covers "now"?  (kickoff-10m .. kickoff+135m)
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM wc_fixtures
          WHERE kickoff_ts BETWEEN :lo AND :hi"
    );
    $st->execute([':lo' => $now - LIVE_PAD_POST, ':hi' => $now + LIVE_PAD_PRE]);
    if ((int)$st->fetchColumn() > 0) {
        return 'live';
    }

    $lastSched = last_schedule_ts($pdo);

    // Post-match catch-up: a match that should already be over but is NOT yet in a
    // final status in our DB. live=1 stops returning a match ~5-20 min after FT, so
    // the final score is captured via a schedule pull. Throttled to once / 10 min
    // so it can't loop while waiting for the API to post the final result.
    $stale = $pdo->prepare(
        "SELECT COUNT(*) FROM wc_fixtures
          WHERE kickoff_ts < :cut
            AND status_short NOT IN ('FT','AET','PEN','PST','CANC','ABD','AWD','WO','TBD')"
    );
    $stale->execute([':cut' => $now - LIVE_PAD_POST]);   // ended > window-length ago
    if ((int)$stale->fetchColumn() > 0 && (!$lastSched || $lastSched < $now - 600)) {
        return 'schedule';
    }

    // Otherwise refresh the schedule at most once per ~20h.
    if (!$lastSched || $lastSched < $now - 20 * 3600) {
        return 'schedule';
    }
    return 'skip';
}

/** Unix ts of the last successful-or-attempted schedule run, or null. */
function last_schedule_ts(PDO $pdo): ?int
{
    $v = $pdo->query(
        "SELECT run_at FROM wc_sync_log
          WHERE mode='schedule' ORDER BY id DESC LIMIT 1"
    )->fetchColumn();
    return $v ? strtotime($v) : null;
}

// ---------------------------------------------------------------------
//  Modes
// ---------------------------------------------------------------------
function run_schedule(PDO $pdo, string $base, string $key, int $league, int $season): void
{
    if (!quota_ok($pdo)) { log_sync($pdo, 'schedule', null, 0, 'quota floor reached'); return; }
    [$code, $data, $q] = api_get($base, $key, '/fixtures', ['league' => $league, 'season' => $season]);
    save_quota($pdo, $q);
    $n = upsert_fixtures($pdo, $data['response'] ?? []);
    log_sync($pdo, 'schedule', $code, $n, "upserted $n fixtures", $q);
}

function run_live(PDO $pdo, array $env, string $base, string $key, int $league): void
{
    if (!quota_ok($pdo)) { log_sync($pdo, 'live', null, 0, 'quota floor reached'); return; }
    // The API rejects a bare single id for `live` (regex wants "all" or "id-id-..").
    // Request all in-play fixtures (still one request) and filter to our league.
    [$code, $data, $q] = api_get($base, $key, '/fixtures', ['live' => 'all']);
    save_quota($pdo, $q);
    $resp = array_values(array_filter(
        $data['response'] ?? [],
        static fn ($r) => (int)($r['league']['id'] ?? 0) === $league
    ));
    $n = upsert_fixtures($pdo, $resp);
    $goals = ingest_events($pdo, $resp);
    foreach ($goals as $g) {
        notify_goal($pdo, $env, $g);
    }
    log_sync($pdo, 'live', $code, $n, "live=$n goals_new=" . count($goals), $q);
}

function run_standings(PDO $pdo, string $base, string $key, int $league, int $season): void
{
    if (!quota_ok($pdo)) { log_sync($pdo, 'standings', null, 0, 'quota floor reached'); return; }
    [$code, $data, $q] = api_get($base, $key, '/standings', ['league' => $league, 'season' => $season]);
    save_quota($pdo, $q);
    $rows = 0;
    foreach (($data['response'][0]['league']['standings'] ?? []) as $group) {
        foreach ($group as $r) {
            $pdo->prepare(
                "REPLACE INTO wc_standings
                 (league_id,season,grp,rank_pos,team_id,team_name,played,win,draw,lose,gf,ga,gd,points)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([
                $league, $season, $r['group'] ?? '', $r['rank'] ?? 0,
                $r['team']['id'] ?? 0, $r['team']['name'] ?? null,
                $r['all']['played'] ?? null, $r['all']['win'] ?? null,
                $r['all']['draw'] ?? null, $r['all']['lose'] ?? null,
                $r['all']['goals']['for'] ?? null, $r['all']['goals']['against'] ?? null,
                $r['goalsDiff'] ?? null, $r['points'] ?? null,
            ]);
            $rows++;
        }
    }
    log_sync($pdo, 'standings', $code, $rows, "rows=$rows", $q);
}

// ---------------------------------------------------------------------
//  Upserts
// ---------------------------------------------------------------------
function upsert_fixtures(PDO $pdo, array $resp): int
{
    $sql = "INSERT INTO wc_fixtures
        (fixture_id,league_id,season,round,status_short,status_long,elapsed,
         kickoff_utc,kickoff_ts,venue_name,venue_city,
         home_id,home_name,away_id,away_name,goals_home,goals_away,
         ht_home,ht_away,ft_home,ft_away,et_home,et_away,pen_home,pen_away)
        VALUES
        (:fid,:lg,:se,:rd,:ss,:sl,:el,:kut,:kts,:vn,:vc,
         :hi,:hn,:ai,:an,:gh,:ga,:hh,:ha,:fh,:fa,:eh,:ea,:ph,:pa)
        ON DUPLICATE KEY UPDATE
         status_short=VALUES(status_short), status_long=VALUES(status_long),
         elapsed=VALUES(elapsed), round=VALUES(round),
         kickoff_utc=VALUES(kickoff_utc), kickoff_ts=VALUES(kickoff_ts),
         venue_name=VALUES(venue_name), venue_city=VALUES(venue_city),
         home_id=VALUES(home_id), home_name=VALUES(home_name),
         away_id=VALUES(away_id), away_name=VALUES(away_name),
         goals_home=VALUES(goals_home), goals_away=VALUES(goals_away),
         ht_home=VALUES(ht_home), ht_away=VALUES(ht_away),
         ft_home=VALUES(ft_home), ft_away=VALUES(ft_away),
         et_home=VALUES(et_home), et_away=VALUES(et_away),
         pen_home=VALUES(pen_home), pen_away=VALUES(pen_away)";
    $st = $pdo->prepare($sql);
    $n = 0;
    foreach ($resp as $row) {
        $fx = $row['fixture'] ?? []; $lg = $row['league'] ?? [];
        $tm = $row['teams'] ?? [];  $go = $row['goals'] ?? []; $sc = $row['score'] ?? [];
        if (empty($fx['id'])) continue;
        $ts = $fx['timestamp'] ?? strtotime($fx['date'] ?? 'now');
        $st->execute([
            ':fid' => $fx['id'], ':lg' => $lg['id'] ?? 1, ':se' => $lg['season'] ?? 2026,
            ':rd'  => $lg['round'] ?? null,
            ':ss'  => $fx['status']['short'] ?? 'NS', ':sl' => $fx['status']['long'] ?? null,
            ':el'  => $fx['status']['elapsed'] ?? null,
            ':kut' => gmdate('Y-m-d H:i:s', (int)$ts), ':kts' => (int)$ts,
            ':vn'  => $fx['venue']['name'] ?? null, ':vc' => $fx['venue']['city'] ?? null,
            ':hi'  => $tm['home']['id'] ?? null, ':hn' => $tm['home']['name'] ?? null,
            ':ai'  => $tm['away']['id'] ?? null, ':an' => $tm['away']['name'] ?? null,
            ':gh'  => $go['home'] ?? null, ':ga' => $go['away'] ?? null,
            ':hh'  => $sc['halftime']['home'] ?? null, ':ha' => $sc['halftime']['away'] ?? null,
            ':fh'  => $sc['fulltime']['home'] ?? null, ':fa' => $sc['fulltime']['away'] ?? null,
            ':eh'  => $sc['extratime']['home'] ?? null, ':ea' => $sc['extratime']['away'] ?? null,
            ':ph'  => $sc['penalty']['home'] ?? null, ':pa' => $sc['penalty']['away'] ?? null,
        ]);
        $n++;
    }
    return $n;
}

/** Insert events from a live payload; return the new GOAL events for alerting. */
function ingest_events(PDO $pdo, array $resp): array
{
    $ins = $pdo->prepare(
        "INSERT IGNORE INTO wc_events
          (fixture_id,team_id,team_name,player_name,assist_name,type,detail,minute,extra_minute)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );
    $newGoals = [];
    foreach ($resp as $row) {
        $fid = $row['fixture']['id'] ?? null;
        if (!$fid) continue;
        foreach (($row['events'] ?? []) as $e) {
            $ins->execute([
                $fid, $e['team']['id'] ?? null, $e['team']['name'] ?? null,
                $e['player']['name'] ?? null, $e['assist']['name'] ?? null,
                $e['type'] ?? '', $e['detail'] ?? null,
                $e['time']['elapsed'] ?? null, $e['time']['extra'] ?? null,
            ]);
            if ($ins->rowCount() > 0 && ($e['type'] ?? '') === 'Goal'
                && stripos($e['detail'] ?? '', 'missed') === false) {
                $newGoals[] = [
                    'fixture_id' => $fid,
                    'team'   => $e['team']['name'] ?? '',
                    'player' => $e['player']['name'] ?? '',
                    'minute' => $e['time']['elapsed'] ?? '',
                    'home'   => $row['teams']['home']['name'] ?? '',
                    'away'   => $row['teams']['away']['name'] ?? '',
                    'gh'     => $row['goals']['home'] ?? '',
                    'ga'     => $row['goals']['away'] ?? '',
                ];
            }
        }
    }
    return $newGoals;
}

// ---------------------------------------------------------------------
//  Taqnyat goal alert  (sender CATRION-IT / TAQNYAT_TOKEN_RPA)
// ---------------------------------------------------------------------
function notify_goal(PDO $pdo, array $env, array $g): void
{
    $recipients = array_filter(array_map('trim', explode(',', $env['WC_ALERT_RECIPIENTS'] ?? '')));
    if (!$recipients) return;   // nothing configured → skip silently
    $msg = sprintf("GOAL %d' — %s%s\n%s %s-%s %s",
        $g['minute'], $g['team'], $g['player'] ? " ({$g['player']})" : '',
        $g['home'], $g['gh'], $g['ga'], $g['away']);

    $payload = json_encode([
        'recipients' => array_values($recipients),
        'body'       => $msg,
        'sender'     => $env['TAQNYAT_SENDER_RPA'] ?? 'CATRION-IT',
    ]);
    $ch = curl_init($env['TAQNYAT_API']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . ($env['TAQNYAT_TOKEN_RPA'] ?? ''),
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
    $pdo->prepare("UPDATE wc_events SET alerted=1
                   WHERE fixture_id=? AND type='Goal' AND minute=? AND alerted=0")
        ->execute([$g['fixture_id'], $g['minute']]);
}

// ---------------------------------------------------------------------
//  Transport (GET, x-apisports-key ONLY) + quota
// ---------------------------------------------------------------------
function api_get(string $base, string $key, string $path, array $params): array
{
    $url = $base . $path . ($params ? '?' . http_build_query($params) : '');
    $hdr = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => HTTP_TIMEOUT,
        CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
        // IMPORTANT: send ONLY this header — api-sports rejects any extras.
        CURLOPT_HTTPHEADER     => ['x-apisports-key: ' . $key],
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$hdr) {
            $p = explode(':', $line, 2);
            if (count($p) === 2) $hdr[strtolower(trim($p[0]))] = trim($p[1]);
            return strlen($line);
        },
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new RuntimeException("cURL: $err");

    $data = json_decode((string)$body, true) ?: [];
    if (!empty($data['errors']) && (is_array($data['errors']) ? count($data['errors']) : true)) {
        // API returns 200 with an errors payload (bad key, quota, bad param...)
        throw new RuntimeException('API errors: ' . json_encode($data['errors']));
    }
    $q = [
        'day_limit'     => isset($hdr['x-ratelimit-requests-limit'])     ? (int)$hdr['x-ratelimit-requests-limit']     : null,
        'day_remaining' => isset($hdr['x-ratelimit-requests-remaining']) ? (int)$hdr['x-ratelimit-requests-remaining'] : null,
        'min_limit'     => isset($hdr['x-ratelimit-limit'])              ? (int)$hdr['x-ratelimit-limit']              : null,
        'min_remaining' => isset($hdr['x-ratelimit-remaining'])          ? (int)$hdr['x-ratelimit-remaining']          : null,
    ];
    return [$code, $data, $q];
}

function quota_ok(PDO $pdo): bool
{
    $row = $pdo->query("SELECT day_remaining, min_remaining FROM wc_api_quota WHERE id=1")->fetch();
    if (!$row || $row['day_remaining'] === null) return true;  // unknown yet → allow first call
    if ((int)$row['day_remaining'] <= SAFETY_FLOOR) return false;
    if ($row['min_remaining'] !== null && (int)$row['min_remaining'] <= 1) return false;
    return true;
}

function save_quota(PDO $pdo, array $q): void
{
    $pdo->prepare(
        "UPDATE wc_api_quota
            SET day_limit=:dl, day_remaining=:dr, min_limit=:ml, min_remaining=:mr
          WHERE id=1"
    )->execute([':dl' => $q['day_limit'], ':dr' => $q['day_remaining'],
                ':ml' => $q['min_limit'], ':mr' => $q['min_remaining']]);
}

function log_sync(PDO $pdo, string $mode, ?int $code, ?int $results, string $note, array $q = []): void
{
    $pdo->prepare(
        "INSERT INTO wc_sync_log (mode,http_code,results,day_remaining,min_remaining,note)
         VALUES (?,?,?,?,?,?)"
    )->execute([$mode, $code, $results, $q['day_remaining'] ?? null, $q['min_remaining'] ?? null, $note]);
}

// ---------------------------------------------------------------------
//  Minimal .env loader (KEY=VALUE, ignores comments/blank lines)
// ---------------------------------------------------------------------
function load_env(string $path): array
{
    if (!is_readable($path)) throw new RuntimeException(".env not readable: $path");
    $env = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v);
    }
    return $env;
}
