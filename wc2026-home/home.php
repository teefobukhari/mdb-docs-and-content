<?php
// /WC2026/home.php

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
$mobile = $_SESSION['MOBILE'] ?? '';

$logoPath = "/WC2026/partials/CATRION%20logo.png";
$iconPath = "/WC2026/partials/CATRION%20Icon.png";

/* Top background banner (Key Visual 3800x700). If the file is missing the
   gradient hero is used as a graceful fallback. */
$bannerPath = "/WC2026/WC-2026-KV.jpg";

/* ----------------------------------------------------------------------
 * Scoring rules (single source of truth). Applied server-side wherever
 * points are awarded (photo save endpoint + predictions scoring job).
 *  - Photobooth capture .... 10 pts (once per day)
 *  - Predict match winner .... 3 pts
 *  - Predict correct score ... 5 pts
 *  - Predict champion ........ 15 pts (FINAL only)
 * -------------------------------------------------------------------- */
const WC_PTS_PHOTO            = 10;
const WC_PTS_PREDICT_WINNER   = 3;
const WC_PTS_PREDICT_SCORE    = 5;
const WC_PTS_PREDICT_CHAMPION = 15;
const WC_PHOTO_DAILY_CAP      = 1; // photo points can be earned once per day

/* Server-side prediction lock: a prediction may only be submitted BEFORE
   kickoff. Enforce this in the predictions endpoint, e.g.:
       if (!wc_prediction_open($kickoffDatetime)) { reject('Match already started'); }
   Champion predictions follow the same rule against the Final kickoff. */
function wc_prediction_open(?string $kickoffDatetime): bool {
    if (!$kickoffDatetime) return false;
    $ts = strtotime((string)$kickoffDatetime);
    if ($ts === false) return false;
    return time() < $ts; // closed the moment the match starts
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

function wc_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types && $params) $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) return [];
    $res = $stmt->get_result();
    return $res ? ($res->fetch_all(MYSQLI_ASSOC) ?: []) : [];
}

function wc_has_column(mysqli $conn, string $table, string $column): bool {
    $sql = "SHOW COLUMNS FROM `" . $conn->real_escape_string($table) . "` LIKE ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param("s", $column);
    if (!$stmt->execute()) return false;
    $res = $stmt->get_result();
    return $res && $res->num_rows > 0;
}

function wc_stage_key(?string $roundName): string {
    $r = strtoupper((string)$roundName);

    // Order matters: "Quarter Finals" / "Semi Finals" both contain "FINAL",
    // so check the more specific rounds BEFORE the generic Final.
    if (str_contains($r, 'THIRD') || str_contains($r, 'PLAY-OFF')) return 'Third Place';
    if (str_contains($r, 'QUARTER')) return 'Quarter Finals';
    if (str_contains($r, 'SEMI')) return 'Semi Finals';
    if (str_contains($r, '16') || str_contains($r, 'LAST_16') || str_contains($r, 'ROUND OF 16')) return 'Round of 16';
    if (str_contains($r, '32') || str_contains($r, 'LAST_32') || str_contains($r, 'ROUND OF 32')) return 'Round of 32';
    if (str_contains($r, 'FINAL')) return 'Final';

    return 'Knockout';
}

function wc_match_status_label(array $m): string {
    if ((int)($m['is_live'] ?? 0) === 1) return 'Live';
    if ((int)($m['is_finished'] ?? 0) === 1) return 'Finished';

    $short = strtoupper((string)($m['status_short'] ?? ''));
    if (in_array($short, ['LIVE','1H','2H','HT'], true)) return 'Live';
    if (in_array($short, ['FT','AET','PEN'], true)) return 'Finished';

    return 'Upcoming';
}

function wc_safe_team($team): string {
    $team = trim((string)$team);
    return $team !== '' ? $team : 'TBA';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    if (hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $_SESSION = [];
        session_destroy();
    }
    header("Location: /WC2026/");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start_daily_game') {
    header('Content-Type: application/json; charset=utf-8');

    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        echo json_encode(['ok' => false, 'message' => 'Invalid request.']);
        exit;
    }

    $todaySession = wc_rows(
        $conn,
        "SELECT id, status, started_at, completed_at
         FROM WC2026_Game_Sessions
         WHERE user_id=? AND play_date=CURDATE()
         LIMIT 1",
        "i",
        [$userId]
    );

    if (!empty($todaySession) && ($todaySession[0]['status'] ?? '') === 'Completed') {
        echo json_encode(['ok' => false, 'message' => 'You already played today. Come back tomorrow!']);
        exit;
    }

    /*
     * If the user opened the game then refreshed/closed the browser before finishing,
     * the row remains Started. Because the table has UNIQUE(user_id, play_date),
     * we safely reset the Started row and allow the game to start again.
     * Completed sessions are never reset.
     */
    $stmt = $conn->prepare("
        INSERT INTO WC2026_Game_Sessions
            (
                user_id,
                play_date,
                goals,
                goal_points,
                target_points,
                golden_goals,
                golden_points,
                combo_bonus,
                mystery_bonus,
                bonus_question_id,
                bonus_answer,
                bonus_correct,
                bonus_points,
                total_points,
                duration_seconds,
                status,
                started_at,
                completed_at,
                played_at
            )
        VALUES
            (
                ?,
                CURDATE(),
                0,
                0,
                0,
                0,
                0,
                0,
                0,
                NULL,
                NULL,
                0,
                0,
                0,
                30,
                'Started',
                NOW(),
                NULL,
                NOW()
            )
        ON DUPLICATE KEY UPDATE
            goals = 0,
            goal_points = 0,
            target_points = 0,
            golden_goals = 0,
            golden_points = 0,
            combo_bonus = 0,
            mystery_bonus = 0,
            bonus_question_id = NULL,
            bonus_answer = NULL,
            bonus_correct = 0,
            bonus_points = 0,
            total_points = 0,
            duration_seconds = 30,
            status = 'Started',
            started_at = NOW(),
            completed_at = NULL,
            played_at = NOW()
    ");

    if (!$stmt) {
        echo json_encode(['ok' => false, 'message' => 'Unable to start the game.']);
        exit;
    }

    $stmt->bind_param("i", $userId);

    if (!$stmt->execute()) {
        echo json_encode(['ok' => false, 'message' => 'Unable to start the game. Please try again.']);
        exit;
    }

    echo json_encode(['ok' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'finish_daily_game') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
            echo json_encode(['ok' => false, 'message' => 'Invalid request.']);
            exit;
        }

        $startedToday = (int)wc_scalar(
            $conn,
            "SELECT COUNT(*) FROM WC2026_Game_Sessions WHERE user_id=? AND play_date=CURDATE() AND status='Started'",
            "i",
            [$userId]
        );

        if ($startedToday <= 0) {
            echo json_encode(['ok' => false, 'message' => 'This game is already completed or not started. Please refresh.']);
            exit;
        }

        $goals = max(0, min(120, (int)($_POST['goals'] ?? 0)));
        $duration = max(1, min(30, (int)($_POST['duration_seconds'] ?? 30)));
        $questionId = max(0, (int)($_POST['bonus_question_id'] ?? 0));
        $answerRaw = strtoupper(trim((string)($_POST['bonus_answer'] ?? '')));
        $answer = in_array($answerRaw, ['A','B','C','D'], true) ? $answerRaw : '';

        $targetPoints = max(0, min(3000, (int)($_POST['target_points'] ?? 0)));
        $goldenGoals  = max(0, min(30, (int)($_POST['golden_goals'] ?? 0)));
        $goldenPoints = max(0, min(1500, (int)($_POST['golden_points'] ?? 0)));
        $comboBonus   = max(0, min(1500, (int)($_POST['combo_bonus'] ?? 0)));
        $mysteryBonus = max(0, min(100, (int)($_POST['mystery_bonus'] ?? 0)));

        $goalPoints = $targetPoints + $goldenPoints + $comboBonus + $mysteryBonus;
        $bonusCorrect = 0;
        $bonusPoints = 0;

        if ($questionId > 0 && $answer !== '') {
            $q = wc_rows(
                $conn,
                "SELECT correct_option, points FROM WC2026_Game_Questions WHERE id=? AND status='Active' LIMIT 1",
                "i",
                [$questionId]
            );

            if (!empty($q) && strtoupper((string)$q[0]['correct_option']) === $answer) {
                $bonusCorrect = 1;
                $bonusPoints = (int)$q[0]['points'];
            }
        }

        $totalPoints = $goalPoints + $bonusPoints;

        $stmt = $conn->prepare("
            UPDATE WC2026_Game_Sessions
            SET
                goals = ?,
                goal_points = ?,
                target_points = ?,
                golden_goals = ?,
                golden_points = ?,
                combo_bonus = ?,
                mystery_bonus = ?,
                bonus_question_id = ?,
                bonus_answer = ?,
                bonus_correct = ?,
                bonus_points = ?,
                total_points = ?,
                duration_seconds = ?,
                status = 'Completed',
                completed_at = NOW()
            WHERE user_id = ?
              AND play_date = CURDATE()
              AND status = 'Started'
        ");

        if (!$stmt) {
            echo json_encode(['ok' => false, 'message' => 'Prepare failed: ' . $conn->error]);
            exit;
        }

        $stmt->bind_param(
            "iiiiiiiisiiiii",
            $goals,
            $goalPoints,
            $targetPoints,
            $goldenGoals,
            $goldenPoints,
            $comboBonus,
            $mysteryBonus,
            $questionId,
            $answer,
            $bonusCorrect,
            $bonusPoints,
            $totalPoints,
            $duration,
            $userId
        );

        if (!$stmt->execute()) {
            error_log('WC2026 GAME SAVE ERROR: ' . $stmt->error);
            echo json_encode(['ok' => false, 'message' => 'Execute failed: ' . $stmt->error]);
            exit;
        }

        if ($stmt->affected_rows < 1) {
            error_log('WC2026 GAME SAVE WARNING: No started row updated for user_id=' . $userId);
            echo json_encode(['ok' => false, 'message' => 'No active started game found to update. Please refresh.']);
            exit;
        }

        echo json_encode([
            'ok' => true,
            'goals' => $goals,
            'goal_points' => $goalPoints,
            'target_points' => $targetPoints,
            'golden_goals' => $goldenGoals,
            'golden_points' => $goldenPoints,
            'combo_bonus' => $comboBonus,
            'mystery_bonus' => $mysteryBonus,
            'bonus_correct' => $bonusCorrect,
            'bonus_points' => $bonusPoints,
            'total_points' => $totalPoints
        ]);
        exit;
    } catch (Throwable $e) {
        error_log('WC2026 GAME SAVE FATAL: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'message' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}

$todayPlayed = (int)wc_scalar(
    $conn,
    "SELECT COUNT(*) FROM WC2026_Game_Sessions WHERE user_id=? AND play_date=CURDATE() AND status='Completed'",
    "i",
    [$userId]
);

$todayGame = wc_rows(
    $conn,
    "SELECT goals, goal_points, bonus_correct, bonus_points, total_points, status, started_at, completed_at, played_at
     FROM WC2026_Game_Sessions
     WHERE user_id=? AND play_date=CURDATE()
     ORDER BY id DESC
     LIMIT 1",
    "i",
    [$userId]
);

$participantsCount = (int)wc_scalar($conn, "SELECT COUNT(*) FROM WC2026_Users WHERE status='Active'");
$gamesToday        = (int)wc_scalar($conn, "SELECT COUNT(*) FROM WC2026_Game_Sessions WHERE play_date=CURDATE()");
$myGamePoints      = (int)wc_scalar($conn, "SELECT COALESCE(SUM(total_points),0) FROM WC2026_Game_Sessions WHERE user_id=?", "i", [$userId]);
$myBestScore       = (int)wc_scalar($conn, "SELECT COALESCE(MAX(total_points),0) FROM WC2026_Game_Sessions WHERE user_id=?", "i", [$userId]);
$playedDays        = (int)wc_scalar(
    $conn,
    "SELECT COUNT(*) FROM WC2026_Game_Sessions WHERE user_id=? AND status='Completed'",
    "i",
    [$userId]
);


$WC_FIXTURES_SUBQUERY = "
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

$matchesCount = (int)wc_scalar($conn, "SELECT COUNT(*) FROM ({$WC_FIXTURES_SUBQUERY}) WC2026_Matches");
$liveMatchesCount = (int)wc_scalar($conn, "SELECT COUNT(*) FROM ({$WC_FIXTURES_SUBQUERY}) WC2026_Matches WHERE is_live=1 OR status_short IN ('LIVE','1H','2H','HT')");
$finishedMatchesCount = (int)wc_scalar($conn, "SELECT COUNT(*) FROM ({$WC_FIXTURES_SUBQUERY}) WC2026_Matches WHERE is_finished=1 OR status_short IN ('FT','AET','PEN')");
$upcomingMatchesCount = (int)wc_scalar($conn, "SELECT COUNT(*) FROM ({$WC_FIXTURES_SUBQUERY}) WC2026_Matches WHERE (is_finished=0 OR is_finished IS NULL) AND match_datetime >= NOW()");
$lastApiSync = wc_scalar($conn, "SELECT MAX(last_api_sync) FROM ({$WC_FIXTURES_SUBQUERY}) WC2026_Matches");

$liveMatches = wc_rows($conn, "
    SELECT id, home_team, away_team, home_logo, away_logo, match_datetime, stadium, city,
           status_short, status_long, elapsed, home_score, away_score, is_live, is_finished
    FROM ({$WC_FIXTURES_SUBQUERY}) WC2026_Matches
    WHERE is_live=1 OR status_short IN ('LIVE','1H','2H','HT')
    ORDER BY match_datetime ASC
    LIMIT 3
");

$nextMatch = wc_rows($conn, "
    SELECT id, home_team, away_team, home_logo, away_logo, match_datetime, stadium, city,
           status_short, status_long, elapsed, home_score, away_score, is_live, is_finished
    FROM ({$WC_FIXTURES_SUBQUERY}) WC2026_Matches
    WHERE (is_finished=0 OR is_finished IS NULL)
      AND match_datetime >= NOW()
    ORDER BY match_datetime ASC
    LIMIT 1
");

$upcomingMatches = wc_rows($conn, "
    SELECT id, home_team, away_team, home_logo, away_logo, match_datetime, stadium, city,
           status_short, status_long, elapsed, home_score, away_score, is_live, is_finished
    FROM ({$WC_FIXTURES_SUBQUERY}) WC2026_Matches
    WHERE (is_finished=0 OR is_finished IS NULL)
      AND match_datetime >= NOW()
    ORDER BY match_datetime ASC
    LIMIT 4
");

$latestResults = wc_rows($conn, "
    SELECT id, home_team, away_team, home_logo, away_logo, match_datetime, stadium, city,
           status_short, status_long, elapsed, home_score, away_score, is_live, is_finished
    FROM ({$WC_FIXTURES_SUBQUERY}) WC2026_Matches
    WHERE is_finished=1 OR status_short IN ('FT','AET','PEN')
    ORDER BY match_datetime DESC
    LIMIT 3
");

$newsMatches = wc_rows($conn, "
    SELECT id, home_team, away_team, home_logo, away_logo, match_datetime, stadium, city,
           round_name, status_short, status_long, elapsed, home_score, away_score, is_live, is_finished
    FROM ({$WC_FIXTURES_SUBQUERY}) WC2026_Matches
    ORDER BY
        CASE
            WHEN is_live = 1 OR status_short IN ('LIVE','1H','2H','HT') THEN 0
            WHEN is_finished = 0 AND match_datetime >= NOW() THEN 1
            ELSE 2
        END,
        match_datetime ASC
    LIMIT 6
");

$mapMatches = wc_rows($conn, "
    SELECT id, home_team, away_team, home_logo, away_logo, match_datetime, stadium, city,
           round_name, status_short, status_long, elapsed, home_score, away_score, is_live, is_finished
    FROM ({$WC_FIXTURES_SUBQUERY}) WC2026_Matches
    ORDER BY
        CASE
            WHEN is_live = 1 OR status_short IN ('LIVE','1H','2H','HT') THEN 0
            WHEN is_finished = 0 AND match_datetime >= NOW() THEN 1
            ELSE 2
        END,
        match_datetime ASC
    LIMIT 5
");

$standingsRows = wc_rows($conn, "
    SELECT
        grp AS group_name,
        rank_pos AS position,
        team_id,
        team_name,
        NULL AS team_logo,
        played,
        points,
        gd AS goal_difference,
        gf AS goals_for
    FROM wc_standings
    WHERE league_id = 1
      AND season = 2026
    ORDER BY grp ASC, rank_pos ASC
");

/* ---- Country flags from WC2026_Filter_Countries (matched by name or code) ---- */
$flagByName = [];
$flagByCode = [];
foreach (wc_rows($conn, "SELECT country_name, country_code, flag_path FROM WC2026_Filter_Countries WHERE status='Active'") as $fr) {
    $fp = trim((string)($fr['flag_path'] ?? ''));
    if ($fp === '') continue;
    $flagByName[mb_strtolower(trim((string)($fr['country_name'] ?? '')))] = $fp;
    $flagByCode[strtolower(trim((string)($fr['country_code'] ?? '')))]   = $fp;
}

function wc_flag(string $team, array $byName, array $byCode): string {
    $k = mb_strtolower(trim($team));
    if ($k === '') return '';
    if (isset($byName[$k])) return $byName[$k];
    if (isset($byCode[$k])) return $byCode[$k];
    return '';
}

function wc_attach_flags(array &$rows, array $byName, array $byCode): void {
    foreach ($rows as &$r) {
        if (empty($r['home_logo'])) { $f = wc_flag((string)($r['home_team'] ?? ''), $byName, $byCode); if ($f !== '') $r['home_logo'] = $f; }
        if (empty($r['away_logo'])) { $f = wc_flag((string)($r['away_team'] ?? ''), $byName, $byCode); if ($f !== '') $r['away_logo'] = $f; }
    }
    unset($r);
}

wc_attach_flags($liveMatches, $flagByName, $flagByCode);
wc_attach_flags($nextMatch, $flagByName, $flagByCode);
wc_attach_flags($upcomingMatches, $flagByName, $flagByCode);
wc_attach_flags($latestResults, $flagByName, $flagByCode);
wc_attach_flags($newsMatches, $flagByName, $flagByCode);
wc_attach_flags($mapMatches, $flagByName, $flagByCode);

function wc_group_letter_from_name(?string $groupName): string {
    $g = strtoupper(trim((string)$groupName));

    if (preg_match('/GROUP\s+([A-Z])/', $g, $m)) return $m[1];
    if (preg_match('/\b([A-L])\b/', $g, $m)) return $m[1];

    return '';
}

foreach ($standingsRows as &$standingRow) {
    $standingRow['group_letter'] = wc_group_letter_from_name($standingRow['group_name'] ?? '');
}
unset($standingRow);

function wc_projected_team_by_position(array $standingsRows, string $groupLetter, int $position): array {
    foreach ($standingsRows as $row) {
        if (($row['group_letter'] ?? '') === $groupLetter && (int)($row['position'] ?? 0) === $position) {
            return [
                'team_id' => $row['team_id'] ?? null,
                'team_name' => $row['team_name'] ?? ($position . $groupLetter),
                'team_logo' => $row['team_logo'] ?? null,
            ];
        }
    }

    return [
        'team_id' => null,
        'team_name' => $position . $groupLetter,
        'team_logo' => null,
    ];
}

function wc_projected_best_thirds(array $standingsRows): array {
    $thirds = [];

    foreach ($standingsRows as $row) {
        if ((int)($row['position'] ?? 0) === 3) {
            $thirds[] = $row;
        }
    }

    usort($thirds, function($a, $b) {
        return
            ((int)($b['points'] ?? 0) <=> (int)($a['points'] ?? 0)) ?:
            ((int)($b['goal_difference'] ?? 0) <=> (int)($a['goal_difference'] ?? 0)) ?:
            ((int)($b['goals_for'] ?? 0) <=> (int)($a['goals_for'] ?? 0)) ?:
            strcmp((string)($a['team_name'] ?? ''), (string)($b['team_name'] ?? ''));
    });

    return array_slice($thirds, 0, 8);
}

function wc_projected_placeholder(string $label): array {
    return [
        'team_id' => null,
        'team_name' => $label,
        'team_logo' => null,
    ];
}

$bestThirds = wc_projected_best_thirds($standingsRows);

$projectedR32Slots = [
    ['R32-01', 'Round of 32', ['A', 1], ['B', 2], '1A vs 2B', '2026-06-28 22:00:00'],
    ['R32-02', 'Round of 32', ['C', 1], ['D', 2], '1C vs 2D', '2026-06-29 02:00:00'],
    ['R32-03', 'Round of 32', ['E', 1], ['F', 2], '1E vs 2F', '2026-06-29 22:00:00'],
    ['R32-04', 'Round of 32', ['G', 1], ['H', 2], '1G vs 2H', '2026-06-30 02:00:00'],
    ['R32-05', 'Round of 32', ['I', 1], ['J', 2], '1I vs 2J', '2026-06-30 22:00:00'],
    ['R32-06', 'Round of 32', ['K', 1], ['L', 2], '1K vs 2L', '2026-07-01 02:00:00'],
    ['R32-07', 'Round of 32', ['B', 1], ['A', 2], '1B vs 2A', '2026-07-01 22:00:00'],
    ['R32-08', 'Round of 32', ['D', 1], ['C', 2], '1D vs 2C', '2026-07-02 02:00:00'],
    ['R32-09', 'Round of 32', ['F', 1], ['E', 2], '1F vs 2E', '2026-07-02 22:00:00'],
    ['R32-10', 'Round of 32', ['H', 1], ['G', 2], '1H vs 2G', '2026-07-03 02:00:00'],
    ['R32-11', 'Round of 32', ['J', 1], ['I', 2], '1J vs 2I', '2026-07-03 22:00:00'],
    ['R32-12', 'Round of 32', ['L', 1], ['K', 2], '1L vs 2K', '2026-07-04 02:00:00'],
    ['R32-13', 'Round of 32', ['A', 1], ['3RD', 1], '1A vs Best 3rd #1', '2026-07-04 22:00:00'],
    ['R32-14', 'Round of 32', ['C', 1], ['3RD', 2], '1C vs Best 3rd #2', '2026-07-05 02:00:00'],
    ['R32-15', 'Round of 32', ['E', 1], ['3RD', 3], '1E vs Best 3rd #3', '2026-07-05 22:00:00'],
    ['R32-16', 'Round of 32', ['G', 1], ['3RD', 4], '1G vs Best 3rd #4', '2026-07-06 02:00:00'],
];

/*
 * Correct bracket cascade (counts now match a real World Cup knockout):
 *   Round of 32 = 16 ties -> Round of 16 = 8 -> Quarter Finals = 4
 *   -> Semi Finals = 2 -> Final = 1 (+ Third Place = 1)
 * Pairings follow the standard winner-feeds-next-slot order so the
 * connector lines line up correctly between columns.
 */
$projectedNextSlots = [
    ['Round of 16', 'Winner R32-01', 'Winner R32-02', 'W R32-01 vs W R32-02', '2026-07-07 02:00:00'],
    ['Round of 16', 'Winner R32-03', 'Winner R32-04', 'W R32-03 vs W R32-04', '2026-07-07 22:00:00'],
    ['Round of 16', 'Winner R32-05', 'Winner R32-06', 'W R32-05 vs W R32-06', '2026-07-08 02:00:00'],
    ['Round of 16', 'Winner R32-07', 'Winner R32-08', 'W R32-07 vs W R32-08', '2026-07-08 22:00:00'],
    ['Round of 16', 'Winner R32-09', 'Winner R32-10', 'W R32-09 vs W R32-10', '2026-07-09 02:00:00'],
    ['Round of 16', 'Winner R32-11', 'Winner R32-12', 'W R32-11 vs W R32-12', '2026-07-09 22:00:00'],
    ['Round of 16', 'Winner R32-13', 'Winner R32-14', 'W R32-13 vs W R32-14', '2026-07-10 02:00:00'],
    ['Round of 16', 'Winner R32-15', 'Winner R32-16', 'W R32-15 vs W R32-16', '2026-07-10 22:00:00'],
    ['Quarter Finals', 'Winner R16-01', 'Winner R16-02', 'W R16-01 vs W R16-02', '2026-07-11 22:00:00'],
    ['Quarter Finals', 'Winner R16-03', 'Winner R16-04', 'W R16-03 vs W R16-04', '2026-07-12 02:00:00'],
    ['Quarter Finals', 'Winner R16-05', 'Winner R16-06', 'W R16-05 vs W R16-06', '2026-07-12 22:00:00'],
    ['Quarter Finals', 'Winner R16-07', 'Winner R16-08', 'W R16-07 vs W R16-08', '2026-07-13 02:00:00'],
    ['Semi Finals', 'Winner QF-01', 'Winner QF-02', 'W QF-01 vs W QF-02', '2026-07-15 02:00:00'],
    ['Semi Finals', 'Winner QF-03', 'Winner QF-04', 'W QF-03 vs W QF-04', '2026-07-16 02:00:00'],
    ['Final', 'Winner SF-01', 'Winner SF-02', 'W SF-01 vs W SF-02', '2026-07-19 22:00:00'],
    ['Third Place', 'Loser SF-01', 'Loser SF-02', 'L SF-01 vs L SF-02', '2026-07-18 22:00:00'],
];

$knockoutMatches = [];

foreach ($projectedR32Slots as $slot) {
    [$slotId, $roundName, $homeRule, $awayRule, $sourceRule, $matchDatetime] = $slot;

    $homeTeam = $homeRule[0] === '3RD'
        ? ($bestThirds[$homeRule[1] - 1] ?? wc_projected_placeholder('Best 3rd #' . $homeRule[1]))
        : wc_projected_team_by_position($standingsRows, $homeRule[0], $homeRule[1]);

    $awayTeam = $awayRule[0] === '3RD'
        ? ($bestThirds[$awayRule[1] - 1] ?? wc_projected_placeholder('Best 3rd #' . $awayRule[1]))
        : wc_projected_team_by_position($standingsRows, $awayRule[0], $awayRule[1]);

    $knockoutMatches[] = [
        'id' => 0,
        'home_team' => $homeTeam['team_name'] ?? $sourceRule,
        'away_team' => $awayTeam['team_name'] ?? $sourceRule,
        'home_logo' => $homeTeam['team_logo'] ?? null,
        'away_logo' => $awayTeam['team_logo'] ?? null,
        'match_datetime' => $matchDatetime,
        'stadium' => 'Projected Path',
        'city' => '',
        'round_name' => $roundName,
        'status_short' => 'NS',
        'status_long' => 'Projected',
        'elapsed' => null,
        'home_score' => null,
        'away_score' => null,
        'is_live' => 0,
        'is_finished' => 0,
        'winner_team_id' => null,
        'source_rule' => $sourceRule,
    ];
}

$slotNo = 1;
foreach ($projectedNextSlots as $slot) {
    [$roundName, $homeName, $awayName, $sourceRule, $matchDatetime] = $slot;

    $knockoutMatches[] = [
        'id' => 0,
        'home_team' => $homeName,
        'away_team' => $awayName,
        'home_logo' => null,
        'away_logo' => null,
        'match_datetime' => $matchDatetime,
        'stadium' => 'Projected Path',
        'city' => '',
        'round_name' => $roundName,
        'status_short' => 'NS',
        'status_long' => 'Projected',
        'elapsed' => null,
        'home_score' => null,
        'away_score' => null,
        'is_live' => 0,
        'is_finished' => 0,
        'winner_team_id' => null,
        'source_rule' => $sourceRule,
    ];

    $slotNo++;
}

wc_attach_flags($knockoutMatches, $flagByName, $flagByCode);

$knockoutStages = [
    'Round of 32' => [],
    'Round of 16' => [],
    'Quarter Finals' => [],
    'Semi Finals' => [],
    'Final' => [],
    'Third Place' => [],
    'Knockout' => []
];

foreach ($knockoutMatches as $km) {
    $stageKey = wc_stage_key($km['round_name'] ?? '');
    if (!isset($knockoutStages[$stageKey])) {
        $knockoutStages[$stageKey] = [];
    }
    $knockoutStages[$stageKey][] = $km;
}

$knockoutTotal = count($knockoutMatches);
$knockoutFinished = 0;

$leaderboard = wc_rows($conn, "
    SELECT 
        u.full_name,
        u.location,
        COALESCE(SUM(g.total_points),0) AS total_points,
        COALESCE(SUM(g.goals),0) AS goals
    FROM WC2026_Users u
    JOIN WC2026_Game_Sessions g ON g.user_id = u.id
    WHERE u.status='Active'
    GROUP BY u.id, u.full_name, u.location
    ORDER BY total_points DESC, goals DESC, u.full_name ASC
    LIMIT 5
");

$myRank = '--';
$rankRow = wc_rows($conn, "
    SELECT rank_no FROM (
        SELECT 
            user_id,
            DENSE_RANK() OVER (ORDER BY COALESCE(SUM(total_points),0) DESC, COALESCE(SUM(goals),0) DESC) AS rank_no
        FROM WC2026_Game_Sessions
        GROUP BY user_id
    ) r
    WHERE user_id=?
    LIMIT 1
", "i", [$userId]);

if ($rankRow) $myRank = '#' . (int)$rankRow[0]['rank_no'];

$bonusQuestion = wc_rows($conn, "
    SELECT id, question_text, option_a, option_b, option_c, option_d
    FROM WC2026_Game_Questions
    WHERE status='Active'
    ORDER BY RAND()
    LIMIT 1
");

$bonusQuestionJson = !empty($bonusQuestion)
    ? json_encode($bonusQuestion[0], JSON_UNESCAPED_UNICODE)
    : 'null';

/* ---- Weekly + overall score (current ISO week vs all-time) ---- */
$overallScore = (int)$myGamePoints;
$weeklyScore  = (int)wc_scalar(
    $conn,
    "SELECT COALESCE(SUM(total_points),0)
     FROM WC2026_Game_Sessions
     WHERE user_id=? AND YEARWEEK(play_date,3)=YEARWEEK(CURDATE(),3)",
    "i",
    [$userId]
);
$weeklyRank = '--';
$wr = wc_rows($conn, "
    SELECT rank_no FROM (
        SELECT user_id,
               DENSE_RANK() OVER (ORDER BY COALESCE(SUM(total_points),0) DESC) AS rank_no
        FROM WC2026_Game_Sessions
        WHERE YEARWEEK(play_date,3)=YEARWEEK(CURDATE(),3)
        GROUP BY user_id
    ) r WHERE user_id=? LIMIT 1
", "i", [$userId]);
if ($wr) $weeklyRank = '#' . (int)$wr[0]['rank_no'];

/* ---- Next World Cup matches within the next 24 hours ---- */
$next24Matches = wc_rows($conn, "
    SELECT id, home_team, away_team, home_logo, away_logo, match_datetime, stadium, city,
           round_name, status_short, status_long, elapsed, home_score, away_score, is_live, is_finished
    FROM ({$WC_FIXTURES_SUBQUERY}) WC2026_Matches
    WHERE (is_finished=0 OR is_finished IS NULL)
      AND match_datetime >= NOW()
      AND match_datetime <= DATE_ADD(NOW(), INTERVAL 24 HOUR)
    ORDER BY match_datetime ASC
    LIMIT 8
");
wc_attach_flags($next24Matches, $flagByName, $flagByCode);
/* fall back to the very next fixture if nothing is within 24h */
$next24Fallback = (empty($next24Matches) && !empty($nextMatch)) ? $nextMatch : [];
wc_attach_flags($next24Fallback, $flagByName, $flagByCode);

/* ---- Recently-online users (activity in the last 15 minutes) ---- */
$onlineUsers = wc_rows($conn, "
    SELECT u.full_name, u.location, MAX(g.played_at) AS last_seen
    FROM WC2026_Game_Sessions g
    JOIN WC2026_Users u ON u.id = g.user_id
    WHERE u.status='Active' AND g.played_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    GROUP BY u.id, u.full_name, u.location
    ORDER BY last_seen DESC
    LIMIT 24
");
$onlineCount = count($onlineUsers);

/* ---- (8) Native Fan Filter studio data (platforms + frames + countries) ---- */
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CATRION FIFA World Cup 2026 Challenge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link rel="icon" type="image/png" href="<?= htmlspecialchars($iconPath, ENT_QUOTES, 'UTF-8') ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($iconPath, ENT_QUOTES, 'UTF-8') ?>">
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
    min-height:420px;
    color:#fff;
    padding:26px 38px 130px;
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

.hero:after{
    content:"\26BD";
    position:absolute;
    right:70px;
    bottom:16px;
    font-size:180px;
    opacity:.10;
}

.nav{
    position:relative;
    z-index:2;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:18px;
}

.brand{
    display:flex;
    align-items:center;
    gap:14px;
}

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

.logo-card img{
    max-width:122px;
    max-height:36px;
}

.brand-title{
    font-size:18px;
    font-weight:900;
    letter-spacing:-.4px;
}

.brand-sub{
    margin-top:3px;
    font-size:12px;
    color:rgba(255,255,255,.68);
    font-weight:700;
}

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

.hero-content{
    position:relative;
    z-index:2;
    max-width:1220px;
    margin:70px auto 0;
    display:grid;
    grid-template-columns:1.1fr .9fr;
    gap:28px;
    align-items:end;
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
    width:8px;
    height:8px;
    border-radius:50%;
    background:var(--gold);
    box-shadow:0 0 18px rgba(245,200,91,.9);
}

.hero h1{
    margin:0;
    font-size:54px;
    line-height:1;
    letter-spacing:-2px;
    font-weight:900;
}

.hero h1 span{
    color:#A8E7FF;
}

.hero p{
    margin:18px 0 0;
    max-width:650px;
    color:rgba(255,255,255,.78);
    line-height:1.8;
    font-size:15px;
}

.daily-card{
    background:rgba(255,255,255,.13);
    border:1px solid rgba(255,255,255,.22);
    backdrop-filter:blur(16px);
    border-radius:28px;
    padding:24px;
}

.daily-title{
    font-size:12px;
    color:rgba(255,255,255,.72);
    text-transform:uppercase;
    letter-spacing:.5px;
    font-weight:900;
    margin-bottom:12px;
}

.daily-prize{
    font-size:34px;
    font-weight:900;
    letter-spacing:-1px;
}

.daily-sub{
    margin-top:8px;
    color:rgba(255,255,255,.70);
    font-size:13px;
    line-height:1.6;
}

.container{
    max-width:1220px;
    margin:-82px auto 44px;
    padding:0 24px;
    position:relative;
    z-index:5;
}

.stats{
    display:grid;
    grid-template-columns:repeat(5,1fr);
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

.layout{
    display:grid;
    grid-template-columns:1.35fr .65fr;
    gap:18px;
}

.card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:28px;
    padding:24px;
    box-shadow:0 18px 40px rgba(7,42,85,.07);
}

.card + .card{
    margin-top:18px;
}

.card-title{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin:0 0 16px;
    font-size:21px;
    font-weight:900;
    color:var(--deep);
}

.card-title small{
    font-size:12px;
    color:var(--muted);
    font-weight:800;
}


.match-dashboard{
    display:grid;
    grid-template-columns:1.05fr .95fr;
    gap:18px;
    margin-bottom:18px;
}

.match-card-premium{
    position:relative;
    overflow:hidden;
    min-height:255px;
    color:#fff;
    border:0;
    background:
        radial-gradient(circle at 82% 12%, rgba(245,200,91,.24), transparent 28%),
        radial-gradient(circle at 20% 95%, rgba(85,183,255,.22), transparent 34%),
        linear-gradient(135deg,#071A35,#0B2C55 55%,#0E63E6);
}

.match-card-premium:after{
    content:"\26BD";
    position:absolute;
    right:22px;
    bottom:-28px;
    font-size:130px;
    opacity:.10;
}

.match-kicker{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:8px 12px;
    border-radius:999px;
    background:rgba(255,255,255,.14);
    border:1px solid rgba(255,255,255,.18);
    font-size:11px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.5px;
}

.match-kicker i{
    width:8px;
    height:8px;
    border-radius:50%;
    background:var(--gold);
    box-shadow:0 0 18px rgba(245,200,91,.95);
}

.match-teams{
    position:relative;
    z-index:2;
    display:grid;
    grid-template-columns:1fr auto 1fr;
    gap:16px;
    align-items:center;
    margin-top:22px;
}

.match-team{
    text-align:center;
}

.team-logo{
    width:58px;
    height:58px;
    display:grid;
    place-items:center;
    margin:0 auto 10px;
    border-radius:18px;
    background:rgba(255,255,255,.92);
    box-shadow:0 16px 32px rgba(0,0,0,.22);
    overflow:hidden;
}

.team-logo img{
    width:42px;
    height:42px;
    object-fit:contain;
}

.team-logo span{
    color:#0B2C55;
    font-size:18px;
    font-weight:900;
}

.match-team strong{
    display:block;
    color:#fff;
    font-size:15px;
    line-height:1.35;
}

.score-box{
    min-width:92px;
    text-align:center;
    padding:13px 14px;
    border-radius:20px;
    background:rgba(255,255,255,.14);
    border:1px solid rgba(255,255,255,.20);
    box-shadow:0 18px 34px rgba(0,0,0,.16);
}

.score-box b{
    display:block;
    color:#fff;
    font-size:28px;
    font-weight:900;
    letter-spacing:-1px;
}

.score-box span{
    display:block;
    margin-top:5px;
    color:rgba(255,255,255,.72);
    font-size:10px;
    font-weight:900;
    text-transform:uppercase;
}

.match-meta-premium{
    position:relative;
    z-index:2;
    margin-top:18px;
    padding:14px 16px;
    border-radius:18px;
    background:rgba(255,255,255,.10);
    border:1px solid rgba(255,255,255,.14);
    color:rgba(255,255,255,.78);
    font-size:13px;
    font-weight:800;
    line-height:1.7;
}

.live-badge,
.status-badge{
    display:inline-flex;
    align-items:center;
    gap:7px;
    padding:7px 10px;
    border-radius:999px;
    font-size:11px;
    font-weight:900;
    text-transform:uppercase;
}

.live-badge{
    color:#fff;
    background:rgba(233,71,71,.95);
    box-shadow:0 0 24px rgba(233,71,71,.35);
}

.status-badge{
    color:#0B2C55;
    background:#EAF4FF;
}

.live-badge:before{
    content:"";
    width:7px;
    height:7px;
    border-radius:50%;
    background:#fff;
    animation:livePulse 1.1s ease-in-out infinite;
}

.match-list-card{
    min-height:255px;
}

.fixture-row{
    display:grid;
    grid-template-columns:1fr auto;
    gap:14px;
    align-items:center;
    padding:13px 0;
    border-bottom:1px solid #EDF2F7;
}

.fixture-row:last-child{
    border-bottom:0;
}

.fixture-main{
    display:flex;
    align-items:center;
    gap:10px;
    min-width:0;
}

.fixture-logos{
    display:flex;
    align-items:center;
    flex-shrink:0;
}

.fixture-logos img,
.fixture-logo-fallback{
    width:26px;
    height:26px;
    border-radius:50%;
    object-fit:contain;
    background:#fff;
    border:1px solid #E4ECF5;
    display:grid;
    place-items:center;
    color:#0B2C55;
    font-size:10px;
    font-weight:900;
}

.fixture-logos img + img,
.fixture-logo-fallback + .fixture-logo-fallback,
.fixture-logos img + .fixture-logo-fallback,
.fixture-logo-fallback + img{
    margin-left:-8px;
}

.fixture-title{
    color:var(--deep);
    font-weight:900;
    font-size:13px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}

.fixture-sub{
    margin-top:3px;
    color:var(--muted);
    font-size:11px;
    font-weight:800;
}

.fixture-score{
    min-width:70px;
    text-align:center;
    color:var(--deep);
    font-size:15px;
    font-weight:900;
    padding:8px 10px;
    border-radius:13px;
    background:#F3F8FE;
}

.fixture-score.live{
    color:#fff;
    background:var(--red);
}

.match-links{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:16px;
}

.match-link{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:40px;
    padding:10px 14px;
    border-radius:14px;
    text-decoration:none;
    font-size:12px;
    font-weight:900;
}

.match-link.primary{
    color:#071A35;
    background:linear-gradient(135deg,var(--gold),var(--gold2));
}

.match-link.soft{
    color:#fff;
    background:rgba(255,255,255,.13);
    border:1px solid rgba(255,255,255,.18);
}

@keyframes livePulse{
    0%,100%{opacity:.55;transform:scale(.88)}
    50%{opacity:1;transform:scale(1.12)}
}

.game-panel{
    border-radius:30px;
    overflow:hidden;
    background:#071A35;
    border:1px solid rgba(7,42,85,.12);
    position:relative;
    min-height:520px;
    box-shadow:inset 0 0 0 1px rgba(255,255,255,.08);
}

.game-top{
    position:absolute;
    z-index:15;
    top:18px;
    left:110px;
    right:110px;
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:0;
    overflow:hidden;
    border-radius:20px;
    background:rgba(7,26,53,.78);
    border:1px solid rgba(255,255,255,.18);
    backdrop-filter:blur(14px);
    box-shadow:0 18px 38px rgba(0,0,0,.25);
}

.game-pill{
    color:#fff;
    padding:16px 12px;
    text-align:center;
    border-right:1px solid rgba(255,255,255,.22);
}

.game-pill:last-child{
    border-right:0;
}

.game-pill span{
    display:block;
    color:rgba(255,255,255,.76);
    font-size:10px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.7px;
}

.game-pill b{
    display:inline-block;
    margin-top:4px;
    font-size:34px;
    line-height:1;
    font-weight:900;
    letter-spacing:-1px;
}

.game-pill:first-child b:after{
    content:" SEC";
    font-size:13px;
    margin-left:5px;
    color:rgba(255,255,255,.78);
    letter-spacing:0;
}

.field{
    position:absolute;
    inset:0;
    overflow:hidden;
    background:
        radial-gradient(circle at 15% 15%, rgba(255,255,255,.34), transparent 8%),
        radial-gradient(circle at 85% 15%, rgba(255,255,255,.34), transparent 8%),
        linear-gradient(180deg,#081B36 0%,#0C2445 26%,#163B39 44%,#187A36 68%,#0B5F2C 100%);
}

.field:before{
    content:"";
    position:absolute;
    inset:0;
    background:
        linear-gradient(180deg, rgba(255,255,255,.04), transparent 32%),
        repeating-linear-gradient(90deg, rgba(255,255,255,.08) 0 2px, transparent 2px 72px);
    opacity:.75;
}

.stadium{
    position:absolute;
    left:0;
    right:0;
    top:0;
    height:250px;
    overflow:hidden;
    background:
        radial-gradient(circle at 15% 14%, rgba(255,255,255,.9), transparent 5%),
        radial-gradient(circle at 85% 14%, rgba(255,255,255,.9), transparent 5%),
        linear-gradient(180deg,#071A35 0%,#112A46 70%,transparent 100%);
}

.stadium:before{
    content:"";
    position:absolute;
    left:-8%;
    right:-8%;
    bottom:4px;
    height:150px;
    border-radius:50% 50% 0 0;
    background:
        repeating-linear-gradient(90deg, rgba(255,255,255,.18) 0 3px, transparent 3px 14px),
        linear-gradient(180deg, rgba(255,255,255,.12), rgba(0,0,0,.28));
    opacity:.72;
}

.stadium:after{
    content:"CATRION        CATRION        CATRION";
    position:absolute;
    left:0;
    right:0;
    bottom:0;
    height:34px;
    display:flex;
    align-items:center;
    justify-content:center;
    background:rgba(7,26,53,.75);
    color:rgba(255,255,255,.88);
    font-weight:900;
    letter-spacing:9px;
    font-size:13px;
}

.light-beam{
    position:absolute;
    top:-120px;
    width:360px;
    height:460px;
    background:linear-gradient(180deg,rgba(255,255,255,.32),transparent 74%);
    filter:blur(14px);
    opacity:.8;
    pointer-events:none;
}

.light-beam.left{
    left:30px;
    transform:rotate(22deg);
    transform-origin:top center;
}

.light-beam.right{
    right:30px;
    transform:rotate(-22deg);
    transform-origin:top center;
}

.pitch-lines{
    position:absolute;
    left:0;
    right:0;
    bottom:0;
    height:310px;
    background:
        linear-gradient(90deg, transparent 49.7%, rgba(255,255,255,.18) 49.7% 50.3%, transparent 50.3%),
        radial-gradient(ellipse at 50% 30%, transparent 0 90px, rgba(255,255,255,.22) 92px 95px, transparent 97px),
        repeating-linear-gradient(90deg, rgba(255,255,255,.055) 0 55px, rgba(255,255,255,.025) 55px 110px);
}

.goal{
    position:absolute;
    z-index:3;
    left:50%;
    top:160px;
    transform:translateX(-50%);
    width:430px;
    height:170px;
    border:10px solid rgba(255,255,255,.96);
    border-bottom:0;
    border-radius:8px 8px 0 0;
    background:
        repeating-linear-gradient(90deg,rgba(255,255,255,.24) 0 2px,transparent 2px 22px),
        repeating-linear-gradient(0deg,rgba(255,255,255,.22) 0 2px,transparent 2px 22px),
        rgba(255,255,255,.06);
    box-shadow:0 12px 30px rgba(0,0,0,.26);
}

.goal:after{
    content:"";
    position:absolute;
    left:50%;
    bottom:-50px;
    width:110%;
    height:5px;
    transform:translateX(-50%);
    background:rgba(255,255,255,.9);
    border-radius:999px;
}

.keeper{
    position:absolute;
    z-index:5;
    top:242px;
    width:92px;
    height:116px;
    transform:translateX(-50%);
    filter:drop-shadow(0 13px 16px rgba(0,0,0,.30));
}

.keeper:before{
    content:"";
    position:absolute;
    left:50%;
    top:0;
    transform:translateX(-50%);
    width:30px;
    height:30px;
    border-radius:50%;
    background:#F2C49B;
    box-shadow:0 0 0 4px #173019;
}

.keeper:after{
    content:"";
    position:absolute;
    left:50%;
    top:32px;
    transform:translateX(-50%);
    width:58px;
    height:58px;
    border-radius:16px 16px 20px 20px;
    background:linear-gradient(180deg,#0D8E52,#07683B);
    box-shadow:
        -46px 13px 0 -17px #0D8E52,
        46px 13px 0 -17px #0D8E52,
        -18px 72px 0 -13px #102033,
        18px 72px 0 -13px #102033;
}

.ball{
    position:absolute;
    z-index:10;
    left:50%;
    bottom:78px;
    transform:translateX(-50%);
    width:78px;
    height:78px;
    border:0;
    background:transparent;
    border-radius:50%;
    display:grid;
    place-items:center;
    font-size:70px;
    line-height:1;
    padding:0;
    filter:drop-shadow(0 16px 18px rgba(0,0,0,.35));
    transition:.36s cubic-bezier(.2,.85,.2,1.05);
    cursor:pointer;
    user-select:none;
    -webkit-tap-highlight-color:transparent;
    animation:ballPulse 1.45s ease-in-out infinite;
}

.ball::before{
    content:"\26BD";
}

.ball.disabled{
    cursor:not-allowed;
    opacity:.72;
    animation:none;
}

.ball.shooting{
    transform:translateX(-50%) scale(.82);
}

.tap-hint{
    position:absolute;
    z-index:12;
    left:32px;
    right:32px;
    bottom:18px;
    min-height:54px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:12px;
    border-radius:18px;
    background:rgba(7,26,53,.72);
    color:#fff;
    font-size:17px;
    font-weight:900;
    box-shadow:0 14px 30px rgba(0,0,0,.25);
    backdrop-filter:blur(10px);
}

.tap-hint small{
    display:block;
    color:rgba(255,255,255,.72);
    font-size:11px;
    font-weight:800;
    margin-top:2px;
}

.aim{
    position:absolute;
    z-index:9;
    left:50%;
    bottom:166px;
    transform:translateX(-50%);
    width:62%;
    height:8px;
    border-radius:999px;
    background:rgba(255,255,255,.26);
    overflow:visible;
}

.aim:before{
    content:"Target line";
    position:absolute;
    left:50%;
    bottom:15px;
    transform:translateX(-50%);
    color:rgba(255,255,255,.68);
    font-size:10px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.6px;
}

.aim-dot{
    position:absolute;
    top:50%;
    transform:translate(-50%,-50%);
    width:24px;
    height:24px;
    border-radius:50%;
    background:var(--gold);
    box-shadow:0 0 20px rgba(245,200,91,.9);
}

.goal-flash{
    position:absolute;
    inset:0;
    z-index:18;
    display:none;
    align-items:center;
    justify-content:center;
    font-size:72px;
    font-weight:900;
    color:#fff;
    text-shadow:0 12px 30px rgba(0,0,0,.45);
    background:rgba(17,163,106,.24);
    animation:goalPop .75s ease;
}

.goal-flash.active{
    display:flex;
}

.miss-flash{
    position:absolute;
    inset:0;
    z-index:18;
    display:none;
    align-items:center;
    justify-content:center;
    font-size:52px;
    font-weight:900;
    color:#fff;
    text-shadow:0 12px 30px rgba(0,0,0,.45);
    background:rgba(233,71,71,.18);
    animation:goalPop .55s ease;
}

.miss-flash.active{
    display:flex;
}

.countdown-overlay{
    position:absolute;
    inset:0;
    z-index:19;
    display:none;
    align-items:center;
    justify-content:center;
    background:rgba(7,26,53,.48);
    color:#fff;
    font-size:94px;
    font-weight:900;
    text-shadow:0 18px 40px rgba(0,0,0,.45);
}

.countdown-overlay.active{
    display:flex;
}

@keyframes goalPop{
    0%{transform:scale(.85);opacity:0}
    35%{transform:scale(1.06);opacity:1}
    100%{transform:scale(1);opacity:0}
}

@keyframes ballPulse{
    0%,100%{transform:translateX(-50%) scale(1)}
    50%{transform:translateX(-50%) scale(1.08)}
}

.game-actions{
    display:flex;
    gap:12px;
    margin-top:18px;
    align-items:center;
    flex-wrap:wrap;
}

.primary-btn,
.secondary-btn{
    border:0;
    min-height:48px;
    border-radius:16px;
    padding:12px 18px;
    font-size:14px;
    font-weight:900;
    cursor:pointer;
}

.primary-btn{
    background:linear-gradient(135deg,var(--gold),var(--gold2));
    color:#071A35;
}

.secondary-btn{
    background:#EAF4FF;
    color:var(--deep);
}

.hidden-shoot{
    display:none;
}

.game-tip{
    color:var(--muted);
    font-size:13px;
    font-weight:800;
}

.disabled-box{
    padding:24px;
    border-radius:22px;
    background:#FFF8E4;
    border:1px solid #F3DFA2;
    color:#755B14;
    line-height:1.7;
    font-size:14px;
}

.leader-row,
.profile-line{
    border-bottom:1px solid #EDF2F7;
}

.leader-row{
    display:grid;
    grid-template-columns:34px 1fr auto;
    gap:11px;
    align-items:center;
    padding:13px 0;
}

.leader-row:last-child,
.profile-line:last-child{
    border-bottom:0;
}

.rank{
    width:30px;
    height:30px;
    display:grid;
    place-items:center;
    border-radius:11px;
    background:#EAF4FF;
    color:var(--deep);
    font-weight:900;
    font-size:12px;
}

.leader-name{
    font-size:13px;
    font-weight:900;
    color:var(--deep);
}

.leader-loc{
    margin-top:3px;
    color:var(--muted);
    font-size:11px;
}

.points{
    color:var(--green);
    font-weight:900;
}

.profile-line{
    display:flex;
    justify-content:space-between;
    gap:12px;
    padding:13px 0;
    font-size:14px;
}

.profile-line span:first-child{
    color:var(--muted);
    font-weight:700;
}

.profile-line span:last-child{
    color:var(--deep);
    font-weight:900;
    text-align:right;
}

.modal{
    position:fixed;
    inset:0;
    z-index:100;
    background:rgba(7,26,53,.72);
    display:none;
    align-items:center;
    justify-content:center;
    padding:22px;
}

.modal.active{
    display:flex;
}

.modal-card{
    width:100%;
    max-width:520px;
    background:#fff;
    border-radius:28px;
    padding:28px;
    box-shadow:0 30px 80px rgba(0,0,0,.35);
}

.modal-kicker{
    color:var(--blue);
    font-size:12px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.5px;
    margin-bottom:10px;
}

.modal-card h3{
    margin:0 0 18px;
    font-size:25px;
    color:var(--deep);
    letter-spacing:-.8px;
}

.answers{
    display:grid;
    gap:10px;
}

.answer-btn{
    border:1px solid var(--border);
    background:#F7FAFE;
    padding:14px;
    border-radius:16px;
    text-align:left;
    cursor:pointer;
    font-weight:800;
    color:var(--deep);
}

.answer-btn:hover{
    border-color:var(--blue);
    background:#EAF4FF;
}

.result-box{
    display:none;
    margin-top:14px;
    padding:14px;
    border-radius:16px;
    font-weight:900;
}

.result-box.ok{
    display:block;
    color:var(--green);
    background:#EFFFF7;
}

.result-box.bad{
    display:block;
    color:var(--red);
    background:#FFF1F0;
}


.target-zone{
    position:absolute;
    z-index:4;
    display:grid;
    place-items:center;
    border-radius:999px;
    color:#071A35;
    font-size:11px;
    font-weight:900;
    background:rgba(255,225,154,.92);
    border:2px solid rgba(255,255,255,.94);
    box-shadow:0 0 24px rgba(245,200,91,.65);
    pointer-events:none;
}

.target-zone.high{width:48px;height:48px;top:180px}
.target-zone.mid{width:42px;height:42px;top:238px;background:rgba(168,231,255,.90)}
.target-zone.center{width:54px;height:54px;top:223px;background:rgba(255,255,255,.86)}

.target-zone.left{left:calc(50% - 190px)}
.target-zone.right{left:calc(50% + 142px)}
.target-zone.center{left:calc(50% - 27px)}

.golden-banner,
.combo-banner{
    position:absolute;
    z-index:16;
    left:50%;
    transform:translateX(-50%);
    padding:10px 16px;
    border-radius:999px;
    font-size:13px;
    font-weight:900;
    letter-spacing:.4px;
    display:none;
    box-shadow:0 16px 34px rgba(0,0,0,.22);
}

.golden-banner{
    bottom:128px;
    color:#071A35;
    background:linear-gradient(135deg,#F5C85B,#FFE19A);
}

.combo-banner{
    top:96px;
    color:#fff;
    background:rgba(14,99,230,.82);
    border:1px solid rgba(255,255,255,.28);
}

.golden-banner.active,
.combo-banner.active{
    display:block;
}

.ball.golden::before{
    content:"\1F7E1";
}

.ball.golden{
    box-shadow:0 0 0 8px rgba(245,200,91,.18), 0 0 44px rgba(245,200,91,.95);
    animation:goldenPulse .75s ease-in-out infinite;
}

.mystery-grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:14px;
    margin-top:18px;
}

.food-roll-machine{
    padding:18px;
    border-radius:26px;
    background:
        radial-gradient(circle at 20% 0%, rgba(245,200,91,.28), transparent 30%),
        linear-gradient(135deg,#071A35,#0B2C55);
    border:1px solid rgba(255,255,255,.14);
    box-shadow:0 22px 50px rgba(7,42,85,.25);
}

.food-slot{
    height:112px;
    border-radius:24px;
    display:grid;
    place-items:center;
    font-size:56px;
    background:#fff;
    border:3px solid rgba(245,200,91,.75);
    box-shadow:
        inset 0 -10px 18px rgba(7,42,85,.08),
        0 16px 26px rgba(0,0,0,.18);
    overflow:hidden;
    user-select:none;
}

.food-slot.rolling{
    animation:slotShake .16s linear infinite;
}

.food-roll-btn{
    width:100%;
    min-height:52px;
    margin-top:16px;
    border:0;
    border-radius:18px;
    cursor:pointer;
    color:#071A35;
    font-size:15px;
    font-weight:900;
    background:linear-gradient(135deg,#F5C85B,#FFE19A);
    box-shadow:0 16px 30px rgba(7,42,85,.18);
}

.food-roll-btn:disabled{
    opacity:.65;
    cursor:not-allowed;
}

.food-roll-result{
    display:none;
    margin-top:16px;
    padding:14px 16px;
    border-radius:18px;
    text-align:center;
    color:#071A35;
    font-weight:900;
    background:#EFFFF7;
    border:1px solid rgba(17,163,106,.25);
    animation:rollResultPop .45s ease;
}

.food-roll-result.active{
    display:block;
}

.food-roll-result strong{
    display:block;
    margin-top:5px;
    font-size:30px;
    color:var(--green);
}

.mystery-note{
    color:var(--muted);
    font-weight:800;
    line-height:1.6;
}

@keyframes slotShake{
    0%{transform:translateY(0) scale(1)}
    50%{transform:translateY(-4px) scale(1.04)}
    100%{transform:translateY(0) scale(1)}
}

@keyframes rollResultPop{
    0%{opacity:0;transform:scale(.86) translateY(8px)}
    100%{opacity:1;transform:scale(1) translateY(0)}
}

@keyframes goldenPulse{
    0%,100%{transform:translateX(-50%) scale(1)}
    50%{transform:translateX(-50%) scale(1.14)}
}


@media(max-width:1050px){
    .hero-content,
    .layout,
    .match-dashboard{
        grid-template-columns:1fr;
    }

    .stats{
        grid-template-columns:repeat(2,1fr);
    }
}

@media(max-width:640px){
    .hero{
        padding:22px 18px 120px;
    }

    .nav{
        align-items:flex-start;
        flex-direction:column;
    }

    .hero-content{
        margin-top:46px;
    }

    .hero h1{
        font-size:38px;
    }

    .stats{
        grid-template-columns:1fr;
    }

    .game-top{
        grid-template-columns:1fr 1fr 1fr;
    }

    .goal{
        width:280px;
        top:170px;
    }

    .game-top{
        left:14px;
        right:14px;
    }

    .game-pill b{
        font-size:24px;
    }

    .keeper{
        top:252px;
    }

    .ball{
        width:82px;
        height:82px;
        font-size:62px;
    }

    .tap-hint{
        left:18px;
        right:18px;
        font-size:14px;
    }

    .game-actions{
        flex-direction:column;
    }
}


/* ==========================================================
   Premium WC2026 polish - keeps the same layout, improves scale/icons/motion
   ========================================================== */
body{
    min-height:100vh;
    background-attachment:fixed;
}
.hero{
    min-height:455px;
    padding-bottom:145px;
}
.hero:before{
    background:
        repeating-linear-gradient(90deg,rgba(255,255,255,.045) 0 1px,transparent 1px 76px),
        radial-gradient(circle at 50% 110%,rgba(255,255,255,.18),transparent 36%),
        linear-gradient(135deg,rgba(255,255,255,.08),transparent 45%);
}
.nav{
    max-width:1220px;
    margin:0 auto;
}
.top-actions{
    position:relative;
    z-index:3;
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
}
.top-link{
    color:#fff;
    text-decoration:none;
    font-weight:900;
    font-size:13px;
    padding:12px 16px;
    border-radius:999px;
    background:rgba(255,255,255,.12);
    border:1px solid rgba(255,255,255,.20);
    transition:.22s ease;
}
.top-link:hover,
.top-link.active{
    background:#fff;
    color:var(--deep);
    transform:translateY(-1px);
}
.logout{
    min-height:43px;
    transition:.22s ease;
}
.logout:hover{
    background:rgba(255,255,255,.22);
    transform:translateY(-1px);
}
.logo-card{
    width:164px;
    height:62px;
    border-radius:20px;
}
.logo-card img{
    max-width:134px;
    max-height:40px;
}
.brand-title{
    font-size:19px;
}
.brand-sub{
    color:rgba(255,255,255,.78);
}
.hero-content{
    grid-template-columns:1.05fr .95fr;
    align-items:center;
}
.hero h1{
    font-size:58px;
    line-height:.98;
}
.daily-card{
    position:relative;
    overflow:hidden;
    padding:28px;
    box-shadow:0 28px 70px rgba(0,0,0,.20);
}
.daily-card:before{
    content:"\1F3C6";
    position:absolute;
    right:24px;
    top:16px;
    font-size:76px;
    opacity:.12;
}
.hero-mini-grid{
    display:grid;
    grid-template-columns:repeat(3,1fr);
    gap:10px;
    margin-top:18px;
}
.hero-mini-grid div{
    padding:12px 10px;
    border-radius:18px;
    background:rgba(255,255,255,.12);
    border:1px solid rgba(255,255,255,.16);
    text-align:center;
}
.hero-mini-grid b{
    display:block;
    font-size:20px;
    color:#fff;
    font-weight:900;
}
.hero-mini-grid span{
    display:block;
    margin-top:3px;
    font-size:10px;
    font-weight:900;
    color:rgba(255,255,255,.66);
    text-transform:uppercase;
}
.stats{
    gap:18px;
}
.stat{
    position:relative;
    overflow:hidden;
    min-height:140px;
    padding:24px 22px 22px;
    transition:.24s ease;
}
.stat:hover,
.card:hover{
    transform:translateY(-2px);
    box-shadow:0 22px 48px rgba(7,42,85,.10);
}
.stat:before{
    position:absolute;
    right:18px;
    top:15px;
    width:42px;
    height:42px;
    display:grid;
    place-items:center;
    border-radius:16px;
    background:#EAF4FF;
    font-size:21px;
    box-shadow:inset 0 -8px 14px rgba(7,42,85,.04);
}
.stat:nth-child(1):before{content:"\2B50";}
.stat:nth-child(2):before{content:"\1F3C5";}
.stat:nth-child(3):before{content:"\1F525";}
.stat:nth-child(4):before{content:"\1F30D";}
.stat:nth-child(5):before{content:"\1F3C6";background:#FFF4CF;}
.stat-value{
    font-size:34px;
}
.card{
    transition:.24s ease;
}
.match-dashboard{
    align-items:stretch;
}
.match-card-premium{
    border-radius:30px;
    min-height:290px;
    padding:28px;
}
.match-list-card{
    min-height:290px;
}
.match-teams{
    gap:20px;
}
.team-logo{
    width:66px;
    height:66px;
    border-radius:22px;
}
.team-logo img{
    width:48px;
    height:48px;
}
.score-box{
    min-width:102px;
    padding:15px 16px;
}
.fixture-row{
    padding:14px 0;
}
.fixture-title{
    max-width:245px;
}
.game-panel{
    min-height:555px;
    border-radius:32px;
}
.game-top{
    left:88px;
    right:88px;
}
.goal{
    top:168px;
    width:450px;
    height:178px;
}
.keeper{
    top:254px;
}
.ball{
    bottom:70px;
    width:86px;
    height:86px;
    font-size:76px;
}
.aim{
    bottom:174px;
}
.tap-hint{
    bottom:20px;
    min-height:60px;
}
.leader-row{
    padding:15px 0;
}
.rank{
    width:34px;
    height:34px;
    border-radius:13px;
}
.points{
    padding:6px 10px;
    border-radius:999px;
    background:#EFFFF7;
}
.profile-line{
    padding:15px 0;
}
.food-roll-machine{
    border-radius:28px;
}
.food-slot{
    height:120px;
    font-size:60px;
}
@media(max-width:1050px){
    .hero-content{
        align-items:start;
    }
    .hero h1{
        font-size:48px;
    }
}
@media(max-width:640px){
    .top-actions{
        width:100%;
    }
    .top-link,.logout{
        padding:10px 13px;
        font-size:12px;
    }
    .logo-card{
        width:150px;
        height:58px;
    }
    .hero-mini-grid{
        grid-template-columns:1fr;
    }
    .stat{
        min-height:118px;
    }
    .match-card-premium{
        padding:22px;
    }
    .match-teams{
        grid-template-columns:1fr;
    }
    .score-box{
        margin:0 auto;
    }
    .fixture-row{
        grid-template-columns:1fr;
    }
    .fixture-score{
        width:max-content;
    }
    .game-panel{
        min-height:520px;
    }
    .food-slot{
        height:92px;
        font-size:46px;
    }
}


/* ================= MOBILE FIXES ================= */
@media (max-width:768px){

.hero{
    padding:18px 14px 100px !important;
    min-height:auto !important;
}

.hero-content,
.layout,
.match-dashboard{
    grid-template-columns:1fr !important;
    gap:14px !important;
}

.hero h1{
    font-size:34px !important;
    line-height:1.05 !important;
}

.container{
    padding:0 12px !important;
    margin-top:-55px !important;
}

.stats{
    grid-template-columns:1fr !important;
    gap:10px !important;
}

.stat{
    min-height:auto !important;
    padding:16px !important;
}

.logo-card{
    width:120px !important;
    height:50px !important;
}

.logo-card img{
    max-width:95px !important;
}

.match-card-premium,
.match-list-card{
    min-height:auto !important;
}

.match-teams{
    grid-template-columns:1fr auto 1fr !important;
    gap:8px !important;
}

.team-logo{
    width:48px !important;
    height:48px !important;
}

.team-logo img{
    width:34px !important;
    height:34px !important;
}

.score-box{
    min-width:70px !important;
    padding:10px !important;
}

.score-box b{
    font-size:20px !important;
}

.game-panel{
    min-height:430px !important;
}

.game-top{
    left:10px !important;
    right:10px !important;
    top:10px !important;
}

.game-pill{
    padding:10px 6px !important;
}

.game-pill b{
    font-size:20px !important;
}

.goal{
    width:250px !important;
    height:120px !important;
    top:145px !important;
}

.keeper{
    top:215px !important;
    width:70px !important;
}

.ball{
    width:66px !important;
    height:66px !important;
    font-size:58px !important;
    bottom:60px;
}

.aim{
    width:75% !important;
    bottom:130px !important;
}

.tap-hint{
    left:10px !important;
    right:10px !important;
    font-size:12px !important;
    min-height:44px !important;
}

.food-slot{
    height:80px !important;
    font-size:42px !important;
}

.modal{
    padding:10px !important;
}

.modal-card{
    max-width:100% !important;
    border-radius:22px !important;
    padding:18px !important;
}
}

/* ================= DAILY GOAL RUSH STABILITY FIXES ================= */
.game-panel{
    isolation:isolate;
}

.game-panel .field{
    border-radius:32px;
}

.game-panel .goal,
.game-panel .target-zone,
.game-panel .keeper,
.game-panel .ball,
.game-panel .aim,
.game-panel .tap-hint,
.game-panel .goal-flash,
.game-panel .miss-flash,
.game-panel .countdown-overlay{
    will-change:transform;
}

.food-roll-machine,
.food-slot,
.food-roll-result{
    backface-visibility:hidden;
}

.food-slot{
    line-height:1;
}

@media(max-width:768px){
    .target-zone.high{
        width:38px !important;
        height:38px !important;
        top:152px !important;
    }

    .target-zone.mid{
        width:34px !important;
        height:34px !important;
        top:196px !important;
    }

    .target-zone.center{
        width:42px !important;
        height:42px !important;
        top:188px !important;
        left:calc(50% - 21px) !important;
    }

    .target-zone.left{
        left:calc(50% - 112px) !important;
    }

    .target-zone.right{
        left:calc(50% + 74px) !important;
    }

    .golden-banner{
        bottom:108px !important;
        font-size:11px !important;
    }

    .combo-banner{
        top:76px !important;
        font-size:11px !important;
    }

    .mystery-grid{
        gap:8px !important;
    }

    .food-roll-machine{
        padding:12px !important;
    }
}
/* ================= END DAILY GOAL RUSH STABILITY FIXES ================= */

/* ================= END MOBILE FIXES ================= */


/* ================= 30 SEC PROFILE MOBILE SAFETY ================= */
@media (max-width:768px){
    .profile-line{
        align-items:flex-start !important;
    }
}
/* ================= END 30 SEC PROFILE MOBILE SAFETY ================= */


/* ================= LIVE MAP + NEWS + BRACKET ================= */
.news-ticker{
    max-width:1220px;
    margin:-28px auto 18px;
    padding:0 24px;
    position:relative;
    z-index:6;
}
.news-ticker-inner{
    display:flex;
    align-items:center;
    gap:14px;
    min-height:48px;
    overflow:hidden;
    border-radius:18px;
    background:linear-gradient(135deg,#071A35,#0B2C55);
    color:#fff;
    border:1px solid rgba(255,255,255,.14);
    box-shadow:0 18px 38px rgba(7,42,85,.16);
}
.news-label{
    flex:0 0 auto;
    align-self:stretch;
    display:flex;
    align-items:center;
    gap:8px;
    padding:0 18px;
    background:#E94747;
    font-size:12px;
    font-weight:900;
    text-transform:uppercase;
    letter-spacing:.3px;
}
.news-track{min-width:0;white-space:nowrap;overflow:hidden;flex:1;}
.news-track span{
    display:inline-block;
    padding-right:34px;
    color:rgba(255,255,255,.92);
    font-size:13px;
    font-weight:800;
    animation:newsMove 42s linear infinite;
}
.news-view{
    flex:0 0 auto;
    color:#fff;
    text-decoration:none;
    font-size:12px;
    font-weight:900;
    padding:0 16px;
}
@keyframes newsMove{from{transform:translateX(0)}to{transform:translateX(-50%)}}
.live-map-card,.knockout-card{
    border:0;
    color:#fff;
    background:
        radial-gradient(circle at 80% 18%, rgba(85,183,255,.26), transparent 30%),
        radial-gradient(circle at 20% 82%, rgba(17,163,106,.18), transparent 30%),
        linear-gradient(135deg,#071A35,#0B2C55 58%,#0E63E6);
    overflow:hidden;
    position:relative;
    margin-bottom:18px;
}
.live-map-card:before{
    content:"";
    position:absolute;
    inset:0;
    background:
        radial-gradient(circle at 12% 42%, rgba(255,255,255,.18) 0 2px, transparent 3px),
        radial-gradient(circle at 28% 55%, rgba(85,183,255,.60) 0 3px, transparent 5px),
        radial-gradient(circle at 45% 34%, rgba(17,163,106,.70) 0 4px, transparent 7px),
        radial-gradient(circle at 62% 46%, rgba(14,99,230,.70) 0 3px, transparent 6px),
        radial-gradient(circle at 78% 38%, rgba(245,200,91,.75) 0 3px, transparent 6px),
        repeating-linear-gradient(90deg,rgba(255,255,255,.035) 0 1px,transparent 1px 64px),
        repeating-linear-gradient(0deg,rgba(255,255,255,.025) 0 1px,transparent 1px 58px);
    pointer-events:none;
}
.live-map-bg{
    position:absolute;
    inset:58px 24px 24px;
    border-radius:26px;
    opacity:.34;
    background:
        radial-gradient(ellipse at 18% 35%, rgba(168,231,255,.55) 0 10%, transparent 12%),
        radial-gradient(ellipse at 43% 42%, rgba(168,231,255,.50) 0 13%, transparent 16%),
        radial-gradient(ellipse at 68% 38%, rgba(168,231,255,.48) 0 18%, transparent 21%),
        radial-gradient(ellipse at 52% 68%, rgba(168,231,255,.42) 0 10%, transparent 13%),
        linear-gradient(135deg,rgba(255,255,255,.08),rgba(255,255,255,.02));
}
.world-lines{
    position:absolute;
    inset:90px 95px 55px;
    border-radius:50%;
    border:1px dashed rgba(255,255,255,.28);
    opacity:.65;
    transform:rotate(-8deg);
}
.map-head,.bracket-head{
    position:relative;
    z-index:2;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:16px;
    margin-bottom:18px;
}
.map-title,.bracket-title{
    display:flex;
    align-items:center;
    gap:9px;
    color:#fff;
    font-size:19px;
    font-weight:900;
    letter-spacing:-.4px;
}
.map-dot{
    width:9px;height:9px;border-radius:50%;
    background:#22C55E;
    box-shadow:0 0 18px rgba(34,197,94,.9);
}
.map-legend{
    display:flex;
    gap:14px;
    flex-wrap:wrap;
    color:rgba(255,255,255,.82);
    font-size:12px;
    font-weight:800;
}
.legend-item{display:inline-flex;align-items:center;gap:6px;}
.legend-bullet{width:10px;height:10px;border-radius:50%;}
.legend-bullet.live{background:#22C55E}
.legend-bullet.upcoming{background:#7C3AED}
.legend-bullet.finished{background:#64748B}
.map-stage{
    position:relative;
    z-index:2;
    min-height:320px;
    display:grid;
    grid-template-columns:1fr 260px;
    gap:18px;
}
.map-pins{position:relative;min-height:320px;}
.map-pin-card{
    position:absolute;
    width:210px;
    min-height:82px;
    padding:12px;
    border-radius:18px;
    background:rgba(7,26,53,.68);
    border:1px solid rgba(255,255,255,.16);
    box-shadow:0 22px 42px rgba(0,0,0,.24);
    backdrop-filter:blur(12px);
}
.map-pin-card:nth-child(1){left:4%;top:16%}
.map-pin-card:nth-child(2){left:32%;top:2%}
.map-pin-card:nth-child(3){left:46%;top:46%}
.map-pin-card:nth-child(4){right:9%;top:28%}
.map-pin-card:nth-child(5){left:14%;bottom:8%}
.map-pin-status{
    display:inline-flex;
    align-items:center;
    padding:5px 8px;
    border-radius:999px;
    color:#fff;
    font-size:10px;
    font-weight:900;
    text-transform:uppercase;
    background:rgba(124,58,237,.9);
}
.map-pin-status.Live{background:#11A36A}
.map-pin-status.Finished{background:#64748B}
.map-pin-teams{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    margin-top:9px;
    color:#fff;
    font-size:13px;
    font-weight:900;
}
.map-team-mini{display:flex;align-items:center;gap:6px;min-width:0;}
.map-team-mini img,.map-team-fallback{
    width:25px;height:25px;border-radius:50%;
    background:#fff;object-fit:contain;
    display:grid;place-items:center;
    color:#071A35;font-size:10px;flex:0 0 auto;
}
.map-score-mini{flex:0 0 auto;font-size:18px;color:#fff;font-weight:900;}
.map-pin-time{
    margin-top:7px;
    color:rgba(255,255,255,.66);
    font-size:11px;
    font-weight:800;
    text-align:center;
}
.tournament-progress{
    position:relative;
    z-index:2;
    padding:18px;
    border-radius:22px;
    background:rgba(255,255,255,.08);
    border:1px solid rgba(255,255,255,.14);
    backdrop-filter:blur(12px);
}
.progress-title{
    color:#fff;
    font-size:13px;
    font-weight:900;
    text-transform:uppercase;
    margin-bottom:14px;
}
.progress-line{
    display:flex;
    gap:10px;
    padding:11px 0;
    border-bottom:1px solid rgba(255,255,255,.10);
}
.progress-line:last-child{border-bottom:0;}
.progress-node{
    width:14px;height:14px;margin-top:2px;border-radius:50%;
    background:#22C55E;
    box-shadow:0 0 0 5px rgba(34,197,94,.12);
    flex:0 0 auto;
}
.progress-line.pending .progress-node{
    background:transparent;
    border:2px solid rgba(255,255,255,.55);
    box-shadow:none;
}
.progress-copy b{display:block;color:#fff;font-size:12px;font-weight:900;}
.progress-copy span{display:block;margin-top:3px;color:rgba(255,255,255,.62);font-size:11px;font-weight:800;}
.bracket-shell{position:relative;z-index:2;overflow-x:auto;padding-bottom:8px;}
.bracket-grid{
    min-width:1120px;
    display:grid;
    grid-template-columns:repeat(6, 1fr);
    gap:16px;
    align-items:start;
}
.bracket-stage-title{
    color:rgba(255,255,255,.86);
    font-size:12px;
    font-weight:900;
    text-transform:uppercase;
    margin-bottom:12px;
    text-align:center;
}
.bracket-match{
    position:relative;
    padding:12px;
    margin-bottom:13px;
    min-height:96px;
    border-radius:16px;
    background:rgba(255,255,255,.08);
    border:1px solid rgba(255,255,255,.14);
}
.bracket-match:after{
    content:"";
    position:absolute;
    right:-16px;
    top:50%;
    width:16px;
    height:1px;
    background:rgba(255,255,255,.20);
}
.bracket-col:last-child .bracket-match:after{display:none;}
.bracket-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    padding:5px 0;
    color:#fff;
    font-size:12px;
    font-weight:900;
}
.bracket-team{display:flex;align-items:center;gap:7px;min-width:0;}
.bracket-team img,.bracket-team-fallback{
    width:20px;height:20px;border-radius:50%;
    background:#fff;object-fit:contain;
    display:grid;place-items:center;
    color:#071A35;font-size:9px;flex:0 0 auto;
}
.bracket-team span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.bracket-score{color:#fff;font-weight:900;flex:0 0 auto;}
.bracket-status{
    margin-top:8px;
    color:#7EF4AE;
    font-size:10px;
    font-weight:900;
    text-align:center;
}
.bracket-status.upcoming{color:rgba(255,255,255,.62);}
.bracket-empty{
    color:rgba(255,255,255,.72);
    font-weight:800;
    font-size:13px;
    line-height:1.7;
    padding:16px;
    border-radius:18px;
    background:rgba(255,255,255,.08);
    border:1px solid rgba(255,255,255,.12);
}
@media(max-width:768px){
    .news-ticker{margin:-20px auto 14px;padding:0 12px;}
    .news-ticker-inner{border-radius:14px;}
    .news-label{padding:0 12px;font-size:10px;}
    .news-view{display:none;}
    .map-head,.bracket-head{align-items:flex-start;flex-direction:column;}
    .map-stage{grid-template-columns:1fr;min-height:auto;}
    .map-pins{min-height:auto;display:grid;gap:10px;}
    .map-pin-card{
        position:relative;
        left:auto !important;right:auto !important;top:auto !important;bottom:auto !important;
        width:100%;
    }
    .live-map-bg,.world-lines{display:none;}
    .tournament-progress{padding:14px;}
    .bracket-grid{min-width:980px;gap:12px;}
    .bracket-match{padding:10px;}
}
/* ================= END LIVE MAP + NEWS + BRACKET ================= */


/* ================= FINAL WC2026 DASHBOARD REFINEMENT ================= */
:root{
    --wc-dark-1:#05162F;
    --wc-dark-2:#08234A;
    --wc-dark-3:#0E63E6;
    --wc-panel:rgba(7,31,66,.92);
    --wc-panel-2:rgba(10,45,92,.82);
    --wc-line:rgba(168,231,255,.16);
    --wc-text:#FFFFFF;
    --wc-soft:rgba(255,255,255,.68);
}

html{
    background:#05162F !important;
}

body{
    background:
        radial-gradient(circle at 12% 7%, rgba(85,183,255,.16), transparent 28%),
        radial-gradient(circle at 88% 4%, rgba(14,99,230,.26), transparent 32%),
        radial-gradient(circle at 54% 84%, rgba(245,200,91,.07), transparent 28%),
        linear-gradient(180deg,#05162F 0%,#08234A 48%,#05162F 100%) !important;
    color:var(--wc-text) !important;
}

body:before{
    content:"";
    position:fixed;
    inset:0;
    z-index:-1;
    pointer-events:none;
    background:
        repeating-linear-gradient(90deg,rgba(255,255,255,.035) 0 1px,transparent 1px 82px),
        repeating-linear-gradient(0deg,rgba(255,255,255,.024) 0 1px,transparent 1px 72px),
        radial-gradient(circle at 50% -8%,rgba(255,255,255,.10),transparent 38%);
}

.hero{
    min-height:390px !important;
    padding:24px 38px 118px !important;
    background:
        radial-gradient(circle at 72% 10%, rgba(14,99,230,.32), transparent 34%),
        radial-gradient(circle at 16% 30%, rgba(85,183,255,.18), transparent 32%),
        linear-gradient(135deg,#05162F 0%,#08234A 50%,#0E63E6 100%) !important;
    box-shadow:0 26px 80px rgba(0,0,0,.16);
}

.hero:before{
    background:
        repeating-linear-gradient(90deg,rgba(255,255,255,.045) 0 1px,transparent 1px 76px),
        repeating-linear-gradient(0deg,rgba(255,255,255,.024) 0 1px,transparent 1px 76px),
        radial-gradient(circle at 50% 110%,rgba(255,255,255,.12),transparent 36%) !important;
}

.nav,
.hero-content,
.container{
    max-width:1180px !important;
}

.nav{
    align-items:center !important;
}

.logo-card{
    width:150px !important;
    height:56px !important;
    border-radius:18px !important;
}

.logo-card img{
    max-width:126px !important;
    max-height:38px !important;
}

.brand-title{
    font-size:18px !important;
    line-height:1.1 !important;
    color:#fff !important;
}

.brand-sub{
    font-size:11px !important;
    color:rgba(255,255,255,.78) !important;
}

.top-link,
.logout{
    min-height:40px !important;
    padding:10px 15px !important;
    font-size:12px !important;
}

.hero-content{
    margin:52px auto 0 !important;
    grid-template-columns:1.08fr .92fr !important;
    gap:28px !important;
}

.badge{
    margin-bottom:14px !important;
    font-size:11px !important;
}

.hero h1{
    font-size:50px !important;
    line-height:.99 !important;
    letter-spacing:-2px !important;
}

.hero p{
    max-width:620px !important;
    font-size:14px !important;
    line-height:1.75 !important;
    color:rgba(255,255,255,.84) !important;
}

.daily-card{
    padding:24px !important;
    border-radius:24px !important;
    background:linear-gradient(135deg,rgba(255,255,255,.13),rgba(255,255,255,.065)) !important;
    border:1px solid rgba(168,231,255,.18) !important;
    box-shadow:0 22px 64px rgba(0,0,0,.24) !important;
}

.daily-prize{
    font-size:30px !important;
}

.daily-sub{
    font-size:13px !important;
    color:rgba(255,255,255,.75) !important;
}

.hero-mini-grid div{
    border-radius:16px !important;
    background:rgba(255,255,255,.12) !important;
    border:1px solid rgba(168,231,255,.15) !important;
}

.container{
    margin:-64px auto 46px !important;
    padding:0 20px !important;
}

.stats{
    grid-template-columns:repeat(5,minmax(0,1fr)) !important;
    gap:14px !important;
    margin-bottom:18px !important;
}

.stat{
    min-height:118px !important;
    padding:19px 18px !important;
    border-radius:22px !important;
    background:linear-gradient(180deg,#FFFFFF 0%,#F7FAFF 100%) !important;
    border:1px solid rgba(255,255,255,.72) !important;
    box-shadow:0 18px 48px rgba(0,0,0,.18) !important;
}

.stat:before{
    width:38px !important;
    height:38px !important;
    top:16px !important;
    right:16px !important;
    border-radius:15px !important;
}

.stat-label{
    font-size:11px !important;
    letter-spacing:.25px !important;
}

.stat-value{
    font-size:30px !important;
    line-height:1 !important;
}

.stat-note{
    font-size:11px !important;
}

.news-ticker{
    max-width:1180px !important;
    margin:0 auto 18px !important;
    padding:0 !important;
}

.news-ticker-inner{
    min-height:48px !important;
    border-radius:18px !important;
    background:linear-gradient(135deg,#061A36,#071F42 58%,#0A3A76) !important;
    border:1px solid rgba(168,231,255,.16) !important;
    box-shadow:0 20px 50px rgba(0,0,0,.22) !important;
}

.news-label{
    min-width:122px !important;
    justify-content:center !important;
    background:linear-gradient(135deg,#EF4444,#DC2626) !important;
    border-radius:18px 0 0 18px !important;
    font-size:11px !important;
}

.news-track span{
    font-size:12px !important;
    color:rgba(255,255,255,.92) !important;
}

.news-view{
    font-size:11px !important;
}

.live-map-card,
.knockout-card,
.match-card-premium,
.match-list-card,
.layout .card{
    background:
        radial-gradient(circle at 100% 0%, rgba(14,99,230,.22), transparent 34%),
        radial-gradient(circle at 0% 100%, rgba(85,183,255,.10), transparent 34%),
        linear-gradient(135deg,#061A36 0%,#08254D 58%,#0A3A76 100%) !important;
    color:#fff !important;
    border:1px solid var(--wc-line) !important;
    box-shadow:
        0 26px 64px rgba(0,0,0,.24),
        inset 0 1px 0 rgba(255,255,255,.06) !important;
}

.live-map-card{
    border-radius:28px !important;
    padding:22px !important;
    margin-bottom:18px !important;
}

.map-head,
.bracket-head{
    margin-bottom:18px !important;
}

.map-title,
.bracket-title{
    font-size:19px !important;
    line-height:1.2 !important;
    color:#fff !important;
}

.map-legend{
    gap:12px !important;
    font-size:11px !important;
    color:rgba(255,255,255,.78) !important;
}

.map-stage{
    min-height:300px !important;
    grid-template-columns:1fr 246px !important;
    gap:16px !important;
}

.live-map-bg{
    inset:64px 26px 26px !important;
    opacity:.68 !important;
    background:
        radial-gradient(ellipse at 16% 35%, rgba(85,183,255,.36) 0 12%, transparent 15%),
        radial-gradient(ellipse at 44% 42%, rgba(85,183,255,.32) 0 14%, transparent 18%),
        radial-gradient(ellipse at 70% 38%, rgba(85,183,255,.30) 0 18%, transparent 23%),
        radial-gradient(ellipse at 54% 68%, rgba(85,183,255,.24) 0 12%, transparent 15%),
        repeating-radial-gradient(circle at 50% 50%, rgba(168,231,255,.16) 0 1px, transparent 1px 5px) !important;
}

.world-lines{
    border-color:rgba(168,231,255,.26) !important;
    opacity:.76 !important;
}

.map-pin-card{
    width:190px !important;
    min-height:78px !important;
    padding:11px !important;
    border-radius:17px !important;
    background:rgba(6,26,54,.88) !important;
    border:1px solid rgba(168,231,255,.20) !important;
    box-shadow:0 22px 46px rgba(0,0,0,.30), inset 0 1px 0 rgba(255,255,255,.08) !important;
}

.map-pin-status{
    font-size:9px !important;
    padding:5px 8px !important;
}

.map-pin-teams{
    font-size:12px !important;
}

.map-score-mini{
    font-size:17px !important;
}

.map-pin-time{
    font-size:10px !important;
}

.tournament-progress{
    padding:17px !important;
    border-radius:22px !important;
    background:linear-gradient(180deg,rgba(255,255,255,.12),rgba(255,255,255,.065)) !important;
    border:1px solid rgba(168,231,255,.18) !important;
    box-shadow:0 20px 44px rgba(0,0,0,.18) !important;
}

.progress-title{
    font-size:12px !important;
}

.progress-copy b{
    font-size:11px !important;
}

.progress-copy span{
    font-size:10px !important;
}

.knockout-card{
    border-radius:28px !important;
    padding:0 !important;
    margin:18px 0 !important;
    overflow:hidden !important;
}

.knockout-card .bracket-head{
    padding:20px 22px 15px !important;
    margin:0 !important;
    border-bottom:1px solid rgba(168,231,255,.12) !important;
    background:linear-gradient(180deg,rgba(255,255,255,.075),rgba(255,255,255,.025)) !important;
}

.bracket-title-wrap{
    min-width:0;
}

.bracket-subtitle{
    margin-top:5px;
    color:rgba(255,255,255,.62);
    font-size:12px;
    font-weight:800;
    line-height:1.45;
}

.bracket-head-actions{
    display:flex;
    align-items:center;
    gap:14px;
    flex-wrap:wrap;
    justify-content:flex-end;
}

.bracket-more-btn,
.bracket-show-full{
    border:1px solid rgba(168,231,255,.20);
    color:#fff;
    background:linear-gradient(135deg,rgba(255,255,255,.16),rgba(255,255,255,.07));
    min-height:40px;
    padding:10px 15px;
    border-radius:14px;
    font-size:12px;
    font-weight:900;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:9px;
    transition:.22s ease;
    font-family:inherit;
    box-shadow:0 14px 32px rgba(0,0,0,.18);
}

.bracket-more-btn:hover,
.bracket-show-full:hover{
    transform:translateY(-1px);
    background:linear-gradient(135deg,rgba(255,255,255,.22),rgba(255,255,255,.11));
}

.bracket-shell{
    padding:18px 22px 20px !important;
    position:relative !important;
    background:
        radial-gradient(circle at 58% 48%, rgba(14,99,230,.12), transparent 36%),
        linear-gradient(180deg,rgba(255,255,255,.015),rgba(255,255,255,0)) !important;
}

.bracket-grid{
    min-width:1060px !important;
    gap:14px !important;
    align-items:start !important;
}

.bracket-stage-title{
    margin-bottom:14px !important;
    color:rgba(255,255,255,.88) !important;
    letter-spacing:.35px !important;
    font-size:11px !important;
}

.bracket-match{
    background:linear-gradient(135deg,rgba(255,255,255,.12),rgba(255,255,255,.06)) !important;
    border:1px solid rgba(168,231,255,.18) !important;
    box-shadow:0 17px 38px rgba(0,0,0,.18), inset 0 1px 0 rgba(255,255,255,.08) !important;
    min-height:96px !important;
    margin-bottom:14px !important;
    border-radius:17px !important;
    transition:.22s ease;
}

.bracket-match:hover{
    transform:translateY(-2px);
    border-color:rgba(168,231,255,.38) !important;
}

.bracket-row{
    font-size:11px !important;
    color:#fff !important;
}

.bracket-team img,
.bracket-team-fallback{
    width:20px !important;
    height:20px !important;
    box-shadow:0 8px 16px rgba(0,0,0,.14);
}

.bracket-status{
    padding-top:8px !important;
    border-top:1px solid rgba(255,255,255,.08) !important;
    font-size:9px !important;
}

.bracket-match:after{
    background:rgba(168,231,255,.22) !important;
}

.bracket-preview-mode{
    max-height:520px !important;
}

.bracket-preview-mode .bracket-shell{
    max-height:390px !important;
    overflow:hidden !important;
}

.bracket-preview-mode .bracket-shell:after{
    content:"";
    position:absolute;
    left:0;
    right:0;
    bottom:0;
    height:120px;
    pointer-events:none;
    background:linear-gradient(180deg,rgba(6,26,54,0),rgba(6,26,54,.90) 70%,#061A36);
}

.bracket-preview-footer{
    display:flex;
    align-items:center;
    justify-content:center;
    padding:0 24px 24px;
    position:relative;
    z-index:4;
}

.bracket-show-full{
    min-width:210px;
}

.knockout-card.expanded{
    max-height:none !important;
}

.knockout-card.expanded .bracket-shell{
    max-height:none !important;
    overflow-x:auto !important;
    overflow-y:visible !important;
}

.knockout-card.expanded .bracket-shell:after{
    display:none !important;
}

.knockout-card.expanded .bracket-preview-footer{
    display:none !important;
}

.knockout-card.expanded .bracket-more-btn{
    background:linear-gradient(135deg,rgba(17,163,106,.35),rgba(17,163,106,.18)) !important;
}

.match-dashboard{
    gap:18px !important;
}

.match-card-premium,
.match-list-card{
    min-height:265px !important;
    border-radius:28px !important;
}

.card-title,
.fixture-title,
.leader-name,
.profile-line span:last-child{
    color:#fff !important;
}

.card-title small,
.fixture-sub,
.profile-line span:first-child,
.leader-loc,
.game-tip{
    color:rgba(255,255,255,.64) !important;
}

.fixture-row,
.leader-row,
.profile-line{
    border-bottom:1px solid rgba(168,231,255,.11) !important;
}

.fixture-score{
    background:rgba(255,255,255,.10) !important;
    color:#fff !important;
    border:1px solid rgba(168,231,255,.12);
}

.layout{
    margin-top:18px !important;
}

.rank{
    background:rgba(255,255,255,.12) !important;
    color:#fff !important;
}

.points{
    background:rgba(17,163,106,.15) !important;
    color:#7EF4AE !important;
}

.disabled-box{
    background:rgba(245,200,91,.12) !important;
    border-color:rgba(245,200,91,.28) !important;
    color:#FFE19A !important;
}

.game-panel{
    border:1px solid rgba(168,231,255,.18) !important;
    box-shadow:0 26px 64px rgba(0,0,0,.26), inset 0 0 0 1px rgba(255,255,255,.08) !important;
}

@media(max-width:1050px){
    .hero-content{
        grid-template-columns:1fr !important;
    }

    .stats{
        grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    }

    .map-stage{
        grid-template-columns:1fr !important;
    }
}

@media(max-width:768px){
    .hero{
        padding:18px 14px 92px !important;
    }

    .container{
        margin-top:-48px !important;
        padding:0 12px !important;
    }

    .hero h1{
        font-size:35px !important;
    }

    .hero p{
        font-size:13px !important;
    }

    .stats{
        grid-template-columns:1fr !important;
        gap:12px !important;
    }

    .stat{
        min-height:104px !important;
        border-radius:20px !important;
    }

    .news-ticker{
        margin-bottom:14px !important;
    }

    .news-ticker-inner{
        min-height:46px !important;
    }

    .news-label{
        min-width:auto !important;
        padding:0 12px !important;
        font-size:10px !important;
    }

    .live-map-card,
    .knockout-card,
    .match-card-premium,
    .match-list-card,
    .layout .card{
        border-radius:22px !important;
    }

    .map-head,
    .bracket-head{
        flex-direction:column !important;
        align-items:flex-start !important;
    }

    .bracket-head-actions{
        width:100%;
        justify-content:space-between;
    }

    .map-pin-card{
        width:100% !important;
    }

    .bracket-grid{
        min-width:940px !important;
    }

    .bracket-preview-mode{
        max-height:560px !important;
    }

    .bracket-preview-mode .bracket-shell{
        max-height:410px !important;
    }

    .bracket-shell{
        padding:16px !important;
    }

    .bracket-show-full{
        width:100%;
    }
}
/* ================= END FINAL WC2026 DASHBOARD REFINEMENT ================= */


/* ================= WIDE PROFESSIONAL DASHBOARD LAYOUT ================= */
:root{
    --wide-max:1680px;
    --wide-pad:clamp(18px,3.2vw,48px);
    --wc-bg-1:#04142B;
    --wc-bg-2:#071F42;
    --wc-bg-3:#0B4FB8;
    --wc-card:rgba(7,31,66,.90);
    --wc-card-2:rgba(10,45,92,.72);
    --wc-border:rgba(168,231,255,.18);
    --wc-white:#FFFFFF;
    --wc-muted:rgba(255,255,255,.68);
}

html{
    background:#04142B !important;
}

body{
    background:
        radial-gradient(circle at 8% 8%, rgba(85,183,255,.18), transparent 30%),
        radial-gradient(circle at 92% 8%, rgba(14,99,230,.30), transparent 34%),
        radial-gradient(circle at 50% 76%, rgba(245,200,91,.07), transparent 30%),
        linear-gradient(180deg,#04142B 0%,#071F42 45%,#04142B 100%) !important;
    color:#fff !important;
    overflow-x:hidden;
}

body:before{
    content:"";
    position:fixed;
    inset:0;
    z-index:-1;
    background:
        repeating-linear-gradient(90deg,rgba(255,255,255,.036) 0 1px,transparent 1px 90px),
        repeating-linear-gradient(0deg,rgba(255,255,255,.024) 0 1px,transparent 1px 78px),
        radial-gradient(circle at 50% -10%,rgba(255,255,255,.11),transparent 34%);
    pointer-events:none;
}

/* Wider page */
.nav,
.hero-content,
.container,
.news-ticker{
    width:min(var(--wide-max), calc(100vw - (var(--wide-pad) * 2))) !important;
    max-width:none !important;
}

.hero{
    padding-left:var(--wide-pad) !important;
    padding-right:var(--wide-pad) !important;
    min-height:405px !important;
    padding-bottom:116px !important;
    background:
        radial-gradient(circle at 72% 18%, rgba(14,99,230,.38), transparent 32%),
        radial-gradient(circle at 15% 28%, rgba(85,183,255,.16), transparent 30%),
        linear-gradient(135deg,#04142B 0%,#08254D 48%,#0E63E6 100%) !important;
}

.hero:after{
    right:clamp(36px,7vw,130px) !important;
    bottom:18px !important;
    font-size:clamp(120px,10vw,210px) !important;
    opacity:.09 !important;
}

.nav{
    margin:0 auto !important;
}

.hero-content{
    margin:54px auto 0 !important;
    grid-template-columns:minmax(520px,1.05fr) minmax(420px,.72fr) !important;
    align-items:center !important;
    gap:clamp(36px,6vw,110px) !important;
}

.hero h1{
    font-size:clamp(48px,4.4vw,72px) !important;
    line-height:.96 !important;
    letter-spacing:-2.8px !important;
}

.hero p{
    max-width:720px !important;
    font-size:15px !important;
    color:rgba(255,255,255,.86) !important;
}

.daily-card{
    max-width:530px !important;
    margin-left:auto !important;
    padding:28px !important;
    border-radius:28px !important;
    background:linear-gradient(135deg,rgba(255,255,255,.14),rgba(255,255,255,.065)) !important;
    border:1px solid rgba(168,231,255,.20) !important;
    box-shadow:0 28px 70px rgba(0,0,0,.24) !important;
}

.container{
    margin:-58px auto 54px !important;
    padding:0 !important;
}

/* Stats use all horizontal space */
.stats{
    grid-template-columns:repeat(5,minmax(180px,1fr)) !important;
    gap:clamp(14px,1.4vw,24px) !important;
    margin-bottom:22px !important;
}

.stat{
    min-height:124px !important;
    padding:22px 22px !important;
    border-radius:24px !important;
    background:linear-gradient(180deg,#FFFFFF 0%,#F7FAFF 100%) !important;
    box-shadow:0 22px 55px rgba(0,0,0,.20) !important;
}

/* Ticker */
.news-ticker{
    margin:0 auto 20px !important;
    padding:0 !important;
}

.news-ticker-inner{
    min-height:52px !important;
    border-radius:18px !important;
    background:linear-gradient(135deg,#061A36,#071F42 58%,#0B3D78) !important;
    border:1px solid var(--wc-border) !important;
    box-shadow:0 22px 55px rgba(0,0,0,.24) !important;
}

.news-label{
    min-width:132px !important;
    justify-content:center !important;
    background:linear-gradient(135deg,#EF4444,#DC2626) !important;
    border-radius:18px 0 0 18px !important;
}

/* Dark panels */
.live-map-card,
.knockout-card,
.match-card-premium,
.match-list-card,
.layout .card{
    color:#fff !important;
    background:
        radial-gradient(circle at 100% 0%, rgba(14,99,230,.24), transparent 34%),
        radial-gradient(circle at 0% 100%, rgba(85,183,255,.10), transparent 34%),
        linear-gradient(135deg,#061A36 0%,#08254D 58%,#0A3A76 100%) !important;
    border:1px solid var(--wc-border) !important;
    box-shadow:0 28px 70px rgba(0,0,0,.26), inset 0 1px 0 rgba(255,255,255,.06) !important;
}

/* Make map use the wide space */
.live-map-card{
    border-radius:30px !important;
    padding:28px !important;
    margin-bottom:22px !important;
}

.map-title{
    font-size:22px !important;
}

.map-stage{
    min-height:430px !important;
    grid-template-columns:1fr 340px !important;
    gap:26px !important;
}

.map-pins{
    min-height:430px !important;
}

.live-map-bg{
    inset:72px 32px 32px !important;
    opacity:.72 !important;
}

.world-lines{
    inset:100px 130px 70px !important;
}

.map-pin-card{
    width:240px !important;
    min-height:96px !important;
    padding:14px !important;
    border-radius:20px !important;
    background:rgba(6,26,54,.88) !important;
    border:1px solid rgba(168,231,255,.22) !important;
    box-shadow:0 26px 55px rgba(0,0,0,.34), inset 0 1px 0 rgba(255,255,255,.08) !important;
}

.map-pin-card:nth-child(1){left:4% !important;top:18% !important}
.map-pin-card:nth-child(2){left:33% !important;top:6% !important}
.map-pin-card:nth-child(3){left:48% !important;top:52% !important}
.map-pin-card:nth-child(4){right:10% !important;top:30% !important}
.map-pin-card:nth-child(5){left:14% !important;bottom:8% !important}

.map-pin-status{
    font-size:10px !important;
}

.map-pin-teams{
    font-size:14px !important;
}

.map-team-mini img,
.map-team-fallback{
    width:28px !important;
    height:28px !important;
}

.map-score-mini{
    font-size:20px !important;
}

.map-pin-time{
    font-size:11px !important;
}

.tournament-progress{
    padding:22px !important;
    border-radius:24px !important;
    background:linear-gradient(180deg,rgba(255,255,255,.12),rgba(255,255,255,.065)) !important;
    border:1px solid rgba(168,231,255,.20) !important;
    align-self:stretch !important;
}

.progress-title{
    font-size:13px !important;
}

.progress-copy b{
    font-size:12px !important;
}

.progress-copy span{
    font-size:11px !important;
}

/* Bracket nicer + half open preview */
.knockout-card{
    border-radius:30px !important;
    padding:0 !important;
    margin:22px 0 !important;
    overflow:hidden !important;
}

.knockout-card .bracket-head{
    padding:22px 28px 16px !important;
    margin:0 !important;
    border-bottom:1px solid rgba(168,231,255,.12) !important;
    background:linear-gradient(180deg,rgba(255,255,255,.075),rgba(255,255,255,.025)) !important;
}

.bracket-title-wrap{
    min-width:0;
}

.bracket-subtitle{
    margin-top:5px;
    color:rgba(255,255,255,.64);
    font-size:12px;
    font-weight:800;
    line-height:1.45;
}

.bracket-head-actions{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:16px;
    flex-wrap:wrap;
}

.bracket-more-btn,
.bracket-show-full{
    border:1px solid rgba(168,231,255,.22);
    color:#fff;
    background:linear-gradient(135deg,rgba(255,255,255,.17),rgba(255,255,255,.07));
    min-height:42px;
    padding:10px 16px;
    border-radius:15px;
    font-size:12px;
    font-weight:900;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:9px;
    transition:.22s ease;
    font-family:inherit;
    box-shadow:0 16px 34px rgba(0,0,0,.18);
}

.bracket-more-btn:hover,
.bracket-show-full:hover{
    transform:translateY(-1px);
    background:linear-gradient(135deg,rgba(255,255,255,.23),rgba(255,255,255,.11));
}

.bracket-shell{
    padding:22px 28px 24px !important;
    position:relative !important;
    background:
        radial-gradient(circle at 58% 48%, rgba(14,99,230,.13), transparent 36%),
        linear-gradient(180deg,rgba(255,255,255,.015),rgba(255,255,255,0)) !important;
}

.bracket-grid{
    min-width:1360px !important;
    grid-template-columns:1.15fr 1.05fr .95fr .95fr .95fr .95fr !important;
    gap:24px !important;
    align-items:start !important;
}

.bracket-stage-title{
    margin-bottom:15px !important;
    color:rgba(255,255,255,.90) !important;
    letter-spacing:.4px !important;
    font-size:12px !important;
}

.bracket-match{
    background:linear-gradient(135deg,rgba(255,255,255,.12),rgba(255,255,255,.06)) !important;
    border:1px solid rgba(168,231,255,.20) !important;
    box-shadow:0 18px 40px rgba(0,0,0,.18), inset 0 1px 0 rgba(255,255,255,.08) !important;
    min-height:104px !important;
    margin-bottom:16px !important;
    border-radius:18px !important;
    transition:.22s ease;
}

.bracket-match:hover{
    transform:translateY(-2px);
    border-color:rgba(168,231,255,.40) !important;
}

.bracket-row{
    font-size:12px !important;
    color:#fff !important;
}

.bracket-status{
    padding-top:8px !important;
    border-top:1px solid rgba(255,255,255,.08) !important;
    font-size:10px !important;
}

.bracket-preview-mode{
    max-height:585px !important;
}

.bracket-preview-mode .bracket-shell{
    max-height:435px !important;
    overflow:hidden !important;
}

.bracket-preview-mode .bracket-shell:after{
    content:"";
    position:absolute;
    left:0;
    right:0;
    bottom:0;
    height:130px;
    pointer-events:none;
    background:linear-gradient(180deg,rgba(6,26,54,0),rgba(6,26,54,.90) 68%,#061A36);
}

.bracket-preview-footer{
    display:flex;
    align-items:center;
    justify-content:center;
    padding:0 28px 26px;
    position:relative;
    z-index:4;
}

.bracket-show-full{
    min-width:230px;
}

.knockout-card.expanded{
    max-height:none !important;
}

.knockout-card.expanded .bracket-shell{
    max-height:none !important;
    overflow-x:auto !important;
    overflow-y:visible !important;
}

.knockout-card.expanded .bracket-shell:after{
    display:none !important;
}

.knockout-card.expanded .bracket-preview-footer{
    display:none !important;
}

.knockout-card.expanded .bracket-more-btn{
    background:linear-gradient(135deg,rgba(17,163,106,.35),rgba(17,163,106,.18)) !important;
}

/* Bottom content wider grid */
.match-dashboard{
    grid-template-columns:minmax(0,1.08fr) minmax(0,.92fr) !important;
    gap:22px !important;
    margin-top:22px !important;
}

.layout{
    grid-template-columns:minmax(0,1.55fr) minmax(360px,.65fr) !important;
    gap:22px !important;
    margin-top:22px !important;
}

.match-card-premium,
.match-list-card{
    min-height:300px !important;
    border-radius:28px !important;
}

.card-title,
.fixture-title,
.leader-name,
.profile-line span:last-child{
    color:#fff !important;
}

.card-title small,
.fixture-sub,
.profile-line span:first-child,
.leader-loc,
.game-tip{
    color:rgba(255,255,255,.66) !important;
}

.fixture-row,
.leader-row,
.profile-line{
    border-bottom:1px solid rgba(168,231,255,.11) !important;
}

.fixture-score{
    background:rgba(255,255,255,.10) !important;
    color:#fff !important;
    border:1px solid rgba(168,231,255,.12);
}

.rank{
    background:rgba(255,255,255,.12) !important;
    color:#fff !important;
}

.points{
    background:rgba(17,163,106,.15) !important;
    color:#7EF4AE !important;
}

.disabled-box{
    background:rgba(245,200,91,.12) !important;
    border-color:rgba(245,200,91,.28) !important;
    color:#FFE19A !important;
}

.game-panel{
    border:1px solid rgba(168,231,255,.18) !important;
    box-shadow:0 28px 70px rgba(0,0,0,.28), inset 0 0 0 1px rgba(255,255,255,.08) !important;
}

/* Large monitors: use empty sides even more */
@media(min-width:1500px){
    :root{
        --wide-max:1720px;
        --wide-pad:56px;
    }

    .map-stage{
        grid-template-columns:1fr 360px !important;
    }

    .map-pin-card{
        width:250px !important;
    }
}

@media(max-width:1180px){
    .hero-content{
        grid-template-columns:1fr !important;
    }

    .daily-card{
        margin-left:0 !important;
        max-width:100% !important;
    }

    .stats{
        grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    }

    .map-stage,
    .match-dashboard,
    .layout{
        grid-template-columns:1fr !important;
    }
}

@media(max-width:768px){
    .nav,
    .hero-content,
    .container,
    .news-ticker{
        width:calc(100vw - 24px) !important;
    }

    .hero{
        padding:18px 12px 92px !important;
    }

    .hero h1{
        font-size:35px !important;
        letter-spacing:-1.4px !important;
    }

    .hero p{
        font-size:13px !important;
    }

    .container{
        margin-top:-46px !important;
    }

    .stats{
        grid-template-columns:1fr !important;
        gap:12px !important;
    }

    .stat{
        min-height:104px !important;
        border-radius:20px !important;
    }

    .news-ticker-inner{
        min-height:46px !important;
    }

    .news-label{
        min-width:auto !important;
        padding:0 12px !important;
        font-size:10px !important;
    }

    .live-map-card,
    .knockout-card,
    .match-card-premium,
    .match-list-card,
    .layout .card{
        border-radius:22px !important;
    }

    .map-head,
    .bracket-head{
        flex-direction:column !important;
        align-items:flex-start !important;
    }

    .map-stage{
        min-height:auto !important;
    }

    .map-pins{
        min-height:auto !important;
        display:grid !important;
        gap:10px !important;
    }

    .map-pin-card{
        position:relative !important;
        left:auto !important;
        right:auto !important;
        top:auto !important;
        bottom:auto !important;
        width:100% !important;
    }

    .tournament-progress{
        padding:16px !important;
    }

    .bracket-head-actions{
        width:100%;
        justify-content:space-between;
    }

    .bracket-grid{
        min-width:980px !important;
        gap:14px !important;
    }

    .bracket-preview-mode{
        max-height:560px !important;
    }

    .bracket-preview-mode .bracket-shell{
        max-height:410px !important;
    }

    .bracket-shell{
        padding:16px !important;
    }

    .bracket-show-full{
        width:100%;
    }
}
/* ================= END WIDE PROFESSIONAL DASHBOARD LAYOUT ================= */

</style>
</head>

<body>

<header class="hero">
    <div class="nav">
        <div class="brand">
            <div class="logo-card">
                <img src="<?= htmlspecialchars($logoPath, ENT_QUOTES, 'UTF-8') ?>" alt="CATRION">
            </div>
            <div>
                <div class="brand-title">CATRION FIFA World Cup 2026 Challenge</div>
            </div>
        </div>

        <button type="button" class="nav-burger" id="navBurger" aria-label="Menu" aria-expanded="false">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
        </button>

        <div class="top-actions" id="topActions">
            <div class="theme-switch" role="group" aria-label="Theme">
                <button type="button" class="theme-btn" data-theme-set="catrion" data-i18n="themeCatrion">CATRION</button>
                <button type="button" class="theme-btn" data-theme-set="saudi" data-i18n="themeSaudi">Saudi</button>
            </div>
            <div class="lang-switch" role="group" aria-label="Language">
                <button type="button" class="lang-btn" data-lang-set="en">EN</button>
                <button type="button" class="lang-btn" data-lang-set="ar">عربي</button>
            </div>
            <a href="/WC2026/" class="top-link active" data-i18n="navHome">Home</a>
            <a href="/WC2026/matches" class="top-link" data-i18n="navMatches">Matches</a>
            <button type="button" class="top-link icon-link" id="openFanFilterBtn" title="Fan Filter Studio">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z"/><path d="M3 8a2 2 0 0 1 2-2h2l1.5-2h7L19 6h0a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
                <span data-i18n="navFanFilter">Fan Filter</span>
            </button>
            <button type="button" class="top-link" id="howToBtn" data-i18n="navHowTo">How to Use</button>
            <button type="button" class="top-link" id="pointsBtn" data-i18n="navPoints">Points</button>
            <button type="button" class="top-link" id="openProfileBtn" data-i18n="navProfile">My Profile</button>
            <form method="POST" action="/WC2026/" style="margin:0;">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="logout" data-i18n="navLogout">Logout</button>
            </form>
        </div>
    </div>

    <div class="hero-content hero-single">
        <div>
            <h1 data-i18n-html="heroTitle">Cheer with <span>CATRION</span></h1>
        </div>

        <!-- (2) Challenge Hub card temporarily hidden -->
        <div class="daily-card" hidden aria-hidden="true">
            <div class="daily-title" data-i18n="dailyTitle">Challenge Hub</div>
            <div class="daily-prize">SAR 20,000</div>
            <div class="daily-sub" data-i18n="dailySub">
                Daily Goal Rush, real match predictions, live fixtures, bonus questions, and leaderboard points.
            </div>
            <div class="hero-mini-grid">
                <div><b>30s</b><span data-i18n="miniDailyGame">Daily Game</span></div>
                <div><b><?= (int)$matchesCount ?></b><span data-i18n="miniMatches">Matches</span></div>
                <div><b>20K</b><span data-i18n="miniPrize">Prize Pool</span></div>
            </div>
        </div>
    </div>
</header>

<main class="container">

<!-- Welcome line (moved out of the banner, above Next Matches) -->
<section class="welcome-bar">
    <p>
        <span data-i18n="heroWelcome">Welcome,</span> <b><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></b>.
        <span data-i18n="heroIntro">Play the daily 30-second challenge, predict real World Cup matches, collect points, and climb the CATRION leaderboard.</span>
    </p>
</section>

<!-- (3) Next World Cup matches within 24 hours — inside main so the container offset can't overlap it -->
<?php $n24 = !empty($next24Matches) ? $next24Matches : $next24Fallback; ?>
<section class="next24-wrap">
    <div class="next24-head">
        <h2 class="next24-title"><span class="next24-dot"></span> <span data-i18n="next24Title">Next Matches · within 24 hours</span></h2>
        <a href="/WC2026/matches" class="match-link soft" data-i18n="viewFullMatches">View Full Matches</a>
    </div>
    <?php if (!empty($n24)): ?>
        <div class="next24-grid">
            <?php foreach ($n24 as $nx): ?>
                <?php
                    $nxLive = (int)($nx['is_live'] ?? 0) === 1;
                    $nxWhen = date('D, d M • h:i A', strtotime((string)$nx['match_datetime']));
                    $nxVenue = trim(($nx['stadium'] ?? '') . (!empty($nx['city']) ? ' • ' . $nx['city'] : ''));
                    $kickoffOpen = wc_prediction_open((string)$nx['match_datetime']);
                ?>
                <article class="next24-card">
                    <div class="next24-meta">
                        <?php if ($nxLive): ?><span class="live-badge" data-i18n="liveNow">Live</span><?php else: ?><span class="status-badge"><?= htmlspecialchars($nxWhen, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        <?php if (!$kickoffOpen && !$nxLive): ?><span class="lock-badge" title="Predictions closed">🔒 <span data-i18n="closed">Closed</span></span><?php endif; ?>
                    </div>
                    <div class="next24-teams">
                        <div class="n24-team">
                            <div class="team-logo">
                                <?php if (!empty($nx['home_logo'])): ?><img src="<?= htmlspecialchars($nx['home_logo'], ENT_QUOTES, 'UTF-8') ?>" alt=""><?php else: ?><span><?= htmlspecialchars(mb_substr(wc_safe_team($nx['home_team']), 0, 2), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                            </div>
                            <strong><?= htmlspecialchars(wc_safe_team($nx['home_team']), ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                        <div class="n24-vs">VS</div>
                        <div class="n24-team">
                            <div class="team-logo">
                                <?php if (!empty($nx['away_logo'])): ?><img src="<?= htmlspecialchars($nx['away_logo'], ENT_QUOTES, 'UTF-8') ?>" alt=""><?php else: ?><span><?= htmlspecialchars(mb_substr(wc_safe_team($nx['away_team']), 0, 2), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                            </div>
                            <strong><?= htmlspecialchars(wc_safe_team($nx['away_team']), ENT_QUOTES, 'UTF-8') ?></strong>
                        </div>
                    </div>
                    <?php if ($nxVenue !== ''): ?><div class="next24-venue"><?= htmlspecialchars($nxVenue, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                    <div class="next24-actions">
                        <?php if ($kickoffOpen): ?>
                            <button type="button" class="match-link primary" data-predict-url="/WC2026/predict?match=<?= (int)$nx['id'] ?>" data-i18n="submitPrediction">Submit Prediction</button>
                        <?php else: ?>
                            <span class="match-link soft" style="opacity:.6;cursor:not-allowed" data-i18n="predictionsClosed">Predictions Closed</span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="next24-empty" data-i18n="no24">No matches kicking off within the next 24 hours. Check the full schedule.</div>
    <?php endif; ?>
</section>

    <section class="stats">
        <div class="stat">
            <div class="stat-label" data-i18n="statOverall">Overall Score</div>
            <div class="stat-value"><?= (int)$overallScore ?></div>
            <div class="stat-note" data-i18n="statOverallNote">All-time points</div>
        </div>

        <div class="stat">
            <div class="stat-label" data-i18n="statWeekly">Weekly Score</div>
            <div class="stat-value"><?= (int)$weeklyScore ?></div>
            <div class="stat-note"><span data-i18n="statWeeklyNote">This week</span> • <?= htmlspecialchars($weeklyRank, ENT_QUOTES, 'UTF-8') ?></div>
        </div>

        <div class="stat">
            <div class="stat-label" data-i18n="statRank">My Rank</div>
            <div class="stat-value"><?= htmlspecialchars($myRank, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="stat-note" data-i18n="statRankNote">Overall leaderboard</div>
        </div>

        <div class="stat">
            <div class="stat-label" data-i18n="statMatches">World Cup Matches</div>
            <div class="stat-value"><?= (int)$matchesCount ?></div>
            <div class="stat-note"><?= (int)$liveMatchesCount ?> <span data-i18n="live">live</span> • <?= (int)$finishedMatchesCount ?> <span data-i18n="finished">finished</span></div>
        </div>

        <!-- (6) Prize Pool replaced with Participants -->
        <div class="stat">
            <div class="stat-label" data-i18n="statParticipants">Participants</div>
            <div class="stat-value"><?= (int)$participantsCount ?></div>
            <div class="stat-note"><?= (int)$onlineCount ?> <span data-i18n="onlineNow">online now</span></div>
        </div>
    </section>

    <section class="news-ticker" aria-label="World Cup news">
        <div class="news-ticker-inner">
            <div class="news-label">● <span data-i18n="matchNews">Match News</span></div>
            <div class="news-track">
                <?php if (!empty($newsMatches)): ?>
                    <span>
                        <?php foreach ($newsMatches as $nm): ?>
                            <?php
                                $nmStatus = wc_match_status_label($nm);
                                $nmScore = ((int)($nm['is_live'] ?? 0) === 1 || (int)($nm['is_finished'] ?? 0) === 1)
                                    ? ((is_null($nm['home_score']) ? '-' : (int)$nm['home_score']) . ' - ' . (is_null($nm['away_score']) ? '-' : (int)$nm['away_score']))
                                    : date('d M - h:i A', strtotime((string)$nm['match_datetime']));
                            ?>
                            <?= htmlspecialchars(wc_safe_team($nm['home_team']), ENT_QUOTES, 'UTF-8') ?>
                            vs
                            <?= htmlspecialchars(wc_safe_team($nm['away_team']), ENT_QUOTES, 'UTF-8') ?>
                            • <?= htmlspecialchars($nmStatus, ENT_QUOTES, 'UTF-8') ?>
                            • <?= htmlspecialchars($nmScore, ENT_QUOTES, 'UTF-8') ?>
                            &nbsp;&nbsp; • &nbsp;&nbsp;
                        <?php endforeach; ?>
                    </span>
                    <span>
                        <?php foreach ($newsMatches as $nm): ?>
                            <?php
                                $nmStatus = wc_match_status_label($nm);
                                $nmScore = ((int)($nm['is_live'] ?? 0) === 1 || (int)($nm['is_finished'] ?? 0) === 1)
                                    ? ((is_null($nm['home_score']) ? '-' : (int)$nm['home_score']) . ' - ' . (is_null($nm['away_score']) ? '-' : (int)$nm['away_score']))
                                    : date('d M - h:i A', strtotime((string)$nm['match_datetime']));
                            ?>
                            <?= htmlspecialchars(wc_safe_team($nm['home_team']), ENT_QUOTES, 'UTF-8') ?>
                            vs
                            <?= htmlspecialchars(wc_safe_team($nm['away_team']), ENT_QUOTES, 'UTF-8') ?>
                            • <?= htmlspecialchars($nmStatus, ENT_QUOTES, 'UTF-8') ?>
                            • <?= htmlspecialchars($nmScore, ENT_QUOTES, 'UTF-8') ?>
                            &nbsp;&nbsp; • &nbsp;&nbsp;
                        <?php endforeach; ?>
                    </span>
                <?php else: ?>
                    <span>World Cup updates will appear after fixtures are synced.</span>
                <?php endif; ?>
            </div>
            <a class="news-view" href="/WC2026/matches">View all →</a>
        </div>
    </section>

    <!-- (7) Fan Wall notification bar — surfaces new posts even while the wall is minimized -->
    <div class="fan-notify" id="fanNotifyBar" hidden>
        <span class="fan-notify-bell">🔔</span>
        <div class="fan-notify-track" id="fanNotifyTrack"></div>
        <button type="button" class="fan-notify-open" id="fanNotifyOpen" data-i18n="openWall">Open Fan Wall</button>
        <button type="button" class="fan-notify-x" id="fanNotifyClose" aria-label="Dismiss">✕</button>
    </div>

    <!-- (2) Fan Filter Studio as a card (also available from the nav icon) -->
    <section class="card fan-filter-card" id="fanFilterCard">
        <div class="ff-card-inner">
            <div class="ff-card-icon">📸</div>
            <div class="ff-card-copy">
                <h2 class="card-title" style="margin:0 0 4px"><span data-i18n="fanFilterTitle">Fan Filter Studio</span></h2>
                <p class="ff-card-sub" data-i18n="ffCardSub">Create your World Cup fan photo — pick your country, choose a frame, snap a selfie and download.</p>
            </div>
            <button type="button" class="match-link primary" id="openFanFilterCard" data-i18n="ffOpenStudio">Open Studio</button>
        </div>
    </section>

    <!-- (2)+(8)+(10) Fan Wall — minimized by default; new posts surface in the notification bar -->
    <section class="card social-card collapsed" id="socialWall">
        <h2 class="card-title social-head">
            <span class="fanwall-head">
                <span class="fanwall-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>
                </span>
                <span><span data-i18n="socialTitle">Fan Wall</span> <small data-i18n="socialSub">Share your moment • comment • like</small></span>
            </span>
            <button type="button" class="social-toggle" id="fanWallToggle">
                <span class="social-toggle-label" data-i18n="openWall">Open Fan Wall</span>
                <span class="social-count" id="fanWallCount"></span>
            </button>
        </h2>
        <div class="social-body" id="socialBody">
            <form class="social-composer" id="socialComposer">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <div class="social-input-row">
                    <span class="social-ava"><?= htmlspecialchars(mb_substr((string)$name, 0, 1), ENT_QUOTES, 'UTF-8') ?></span>
                    <textarea id="socialText" name="body" rows="2" data-i18n-ph="socialPlaceholder" placeholder="Say something about the World Cup…"></textarea>
                </div>
                <div class="social-actions">
                    <label class="social-attach" for="socialPhoto">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                        <span data-i18n="attachPhoto">Add photo</span>
                    </label>
                    <input type="file" id="socialPhoto" name="photo" accept="image/*" hidden>
                    <span class="social-attach-name" id="socialPhotoName"></span>
                    <button type="submit" class="match-link primary" data-i18n="post">Post</button>
                </div>
            </form>
            <div class="social-error" id="socialError"></div>
            <div class="social-feed" id="socialFeed" data-endpoint="/WC2026/api/social_feed.php">
                <div class="social-loading" data-i18n="socialLoading">Loading the fan wall…</div>
            </div>
        </div>
    </section>

    <?php
    // 2026 host cities (USA / Canada / Mexico) plotted on a stylized North-America board
    $wcHostCities = [
        ['Seattle',108,180,'usa'],['San Francisco',96,255,'usa'],['Los Angeles',124,312,'usa'],
        ['Kansas City',300,300,'usa'],['Dallas',285,360,'usa'],['Houston',305,392,'usa'],
        ['Atlanta',400,392,'usa'],['Miami',448,452,'usa'],['Philadelphia',462,252,'usa'],
        ['New York',478,232,'usa'],['Boston',495,210,'usa'],
        ['Vancouver',98,150,'can'],['Toronto',440,200,'can'],
        ['Monterrey',270,420,'mex'],['Guadalajara',245,455,'mex'],['Mexico City',295,478,'mex'],
    ];
    ?>
    <section class="card live-map-card hostmap-card">
        <div class="map-head">
            <div class="map-title"><span class="map-dot"></span> <span data-i18n="liveMapTitle">Live World Cup Map</span></div>
            <div class="map-legend">
                <span class="legend-item"><i class="legend-bullet usa"></i> <span data-i18n="hostUSA">USA</span></span>
                <span class="legend-item"><i class="legend-bullet can"></i> <span data-i18n="hostCAN">Canada</span></span>
                <span class="legend-item"><i class="legend-bullet mex"></i> <span data-i18n="hostMEX">Mexico</span></span>
            </div>
        </div>

        <div class="hostmap-stage">
            <div class="hostmap">
                <svg viewBox="0 0 640 520" class="hostmap-svg" preserveAspectRatio="xMidYMid meet" aria-label="2026 host cities">
                    <defs>
                        <linearGradient id="naFill" x1="0" y1="0" x2="1" y2="1">
                            <stop offset="0" stop-color="rgba(85,183,255,.20)"/>
                            <stop offset="1" stop-color="rgba(14,99,230,.12)"/>
                        </linearGradient>
                    </defs>
                    <!-- Detailed North-America silhouette (Canada • USA • Mexico) -->
                    <path class="hostmap-land" d="M74,148
                        C70,168 70,196 78,224
                        C70,236 70,254 82,262
                        C86,284 98,300 112,310
                        C122,318 134,322 150,328
                        C188,342 214,346 236,350
                        C234,372 226,392 224,410
                        C222,432 230,446 240,458
                        C250,478 270,492 286,492
                        C300,492 306,476 302,460
                        C314,452 322,438 320,420
                        C318,406 322,398 332,398
                        C356,402 384,400 408,400
                        C420,402 432,406 440,416
                        C446,426 446,442 442,456
                        C440,466 448,470 454,462
                        C462,448 464,430 462,412
                        C476,404 488,388 494,366
                        C504,330 508,288 502,252
                        C500,228 494,210 482,200
                        C470,192 452,190 440,196
                        C420,184 396,178 372,176
                        C336,172 300,172 268,174
                        C232,176 196,170 164,168
                        C140,162 112,154 92,150
                        C86,148 80,148 74,148 Z"/>
                    <!-- Baja California peninsula -->
                    <path class="hostmap-land hostmap-land2" d="M108,312 C104,338 112,372 126,398 C132,410 124,416 116,404 C100,376 96,338 108,312 Z"/>
                    <!-- Great Lakes (water cut-outs) -->
                    <ellipse class="hostmap-lake" cx="404" cy="206" rx="20" ry="9"></ellipse>
                    <ellipse class="hostmap-lake" cx="430" cy="196" rx="12" ry="7"></ellipse>
                    <ellipse class="hostmap-lake" cx="384" cy="220" rx="13" ry="6"></ellipse>
                    <g class="hostmap-grid">
                        <line x1="0" y1="173" x2="640" y2="173"/><line x1="0" y1="346" x2="640" y2="346"/>
                        <line x1="213" y1="0" x2="213" y2="520"/><line x1="426" y1="0" x2="426" y2="520"/>
                    </g>
                    <g class="hostmap-cities">
                        <?php foreach ($wcHostCities as $i => $hc): ?>
                            <?php [$cName, $cx, $cy, $cClass] = $hc; $anchor = $cx > 430 ? 'end' : 'start'; $tx = $cx > 430 ? $cx - 12 : $cx + 12; ?>
                            <g class="hc <?= $cClass ?>" data-city="<?= htmlspecialchars($cName, ENT_QUOTES, 'UTF-8') ?>">
                                <title><?= htmlspecialchars($cName, ENT_QUOTES, 'UTF-8') ?></title>
                                <circle class="hc-ring" cx="<?= $cx ?>" cy="<?= $cy ?>" r="9"></circle>
                                <circle class="hc-dot" cx="<?= $cx ?>" cy="<?= $cy ?>" r="4.2"></circle>
                                <text class="hc-label" x="<?= $tx ?>" y="<?= $cy + 3 ?>" text-anchor="<?= $anchor ?>"><?= htmlspecialchars($cName, ENT_QUOTES, 'UTF-8') ?></text>
                            </g>
                        <?php endforeach; ?>
                    </g>
                </svg>
                <div class="hostmap-cap">📍 <span data-i18n="hostCitiesCap">16 Host Cities · United States · Canada · Mexico</span></div>
            </div>

            <aside class="hostmap-side">
                <div class="hostmap-side-head">
                    <span data-i18n="matchesByLoc">Matches &amp; Locations</span>
                    <a href="/WC2026/matches" class="match-link soft" data-i18n="viewFullMatches">View Full Matches</a>
                </div>
                <div class="map-pins hostmap-list">
                    <?php if (!empty($mapMatches)): ?>
                        <?php foreach ($mapMatches as $mp): ?>
                            <?php
                                $mpStatus = wc_match_status_label($mp);
                                $mpScore = ($mpStatus === 'Live' || $mpStatus === 'Finished')
                                    ? ((is_null($mp['home_score']) ? '-' : (int)$mp['home_score']) . ' - ' . (is_null($mp['away_score']) ? '-' : (int)$mp['away_score']))
                                    : 'VS';
                                $mpLoc = trim((string)($mp['city'] ?? '') . (!empty($mp['stadium']) ? ' • ' . $mp['stadium'] : ''));
                            ?>
                            <div class="map-pin-card" data-city="<?= htmlspecialchars((string)($mp['city'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <div class="map-pin-status <?= htmlspecialchars($mpStatus, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($mpStatus, ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="map-pin-teams">
                                    <div class="map-team-mini">
                                        <?php if (!empty($mp['home_logo'])): ?><img src="<?= htmlspecialchars($mp['home_logo'], ENT_QUOTES, 'UTF-8') ?>" alt=""><?php else: ?><span class="map-team-fallback"><?= htmlspecialchars(mb_substr(wc_safe_team($mp['home_team']), 0, 1), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                        <span><?= htmlspecialchars(mb_substr(wc_safe_team($mp['home_team']), 0, 3), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <div class="map-score-mini"><?= htmlspecialchars($mpScore, ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="map-team-mini">
                                        <?php if (!empty($mp['away_logo'])): ?><img src="<?= htmlspecialchars($mp['away_logo'], ENT_QUOTES, 'UTF-8') ?>" alt=""><?php else: ?><span class="map-team-fallback"><?= htmlspecialchars(mb_substr(wc_safe_team($mp['away_team']), 0, 1), ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                        <span><?= htmlspecialchars(mb_substr(wc_safe_team($mp['away_team']), 0, 3), ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                </div>
                                <?php if ($mpLoc !== ''): ?><div class="map-pin-city">📍 <?= htmlspecialchars($mpLoc, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                                <div class="map-pin-time"><?= htmlspecialchars(date('d M Y - h:i A', strtotime((string)$mp['match_datetime'])), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="bracket-empty" data-i18n="noMapMatches">No synced matches available yet.</div>
                    <?php endif; ?>
                </div>
            </aside>
        </div>
    </section>

    <?php /* legacy world-map markup removed in favour of the host-cities board */ if (false): ?>
    <section class="card live-map-card">
        <div class="live-map-bg"></div>
        <div class="world-lines"></div>

        <!-- Inline world map (no external services) -->
        <svg class="world-map-svg" viewBox="0 0 1000 480" preserveAspectRatio="xMidYMid slice" aria-hidden="true">
            <g class="wm-grid">
                <line x1="0" y1="120" x2="1000" y2="120"></line>
                <line x1="0" y1="240" x2="1000" y2="240"></line>
                <line x1="0" y1="360" x2="1000" y2="360"></line>
                <line x1="250" y1="0" x2="250" y2="480"></line>
                <line x1="500" y1="0" x2="500" y2="480"></line>
                <line x1="750" y1="0" x2="750" y2="480"></line>
            </g>
            <g class="wm-land">
                <!-- North America -->
                <path d="M180,55 C140,55 110,80 110,110 C80,120 70,150 95,165 C90,200 130,215 150,200 C175,235 220,225 225,190 C255,185 272,150 250,130 C276,110 255,75 220,85 C210,60 198,55 180,55 Z"/>
                <path d="M222,196 C234,220 250,232 256,260 C260,278 248,292 264,300 C272,286 268,262 262,244 C256,222 244,206 234,194 Z"/>
                <!-- South America -->
                <path d="M300,268 C274,280 270,312 286,336 C280,372 302,408 322,432 C338,448 352,430 345,404 C362,374 356,334 340,314 C346,290 326,262 300,268 Z"/>
                <!-- Europe -->
                <path d="M480,90 C463,96 470,122 491,121 C496,142 527,141 531,120 C557,126 556,94 534,90 C519,74 495,78 480,90 Z"/>
                <!-- Africa -->
                <path d="M520,158 C494,170 495,202 516,216 C506,252 526,302 546,342 C562,362 582,346 576,314 C602,280 591,219 565,200 C571,174 545,152 520,158 Z"/>
                <!-- Asia -->
                <path d="M600,80 C570,90 575,122 601,126 C590,162 621,182 651,171 C661,206 722,216 742,185 C802,201 862,170 856,134 C882,109 855,74 815,85 C780,54 700,55 660,80 C640,64 615,70 600,80 Z"/>
                <!-- Oceania -->
                <path d="M800,330 C779,340 785,372 811,376 C826,401 871,401 881,375 C906,365 900,334 874,330 C849,314 820,318 800,330 Z"/>
            </g>

            <!-- (4) animated host-region flight arcs + pulsing host-city pins (2026: USA / Mexico / Canada) -->
            <g class="wm-hosts">
                <path class="wm-arc" d="M150,120 C170,40 230,60 200,150"></path>
                <path class="wm-arc" d="M200,150 C240,210 150,210 160,170"></path>
                <circle class="wm-ping-ring" cx="160" cy="120" r="9"></circle>
                <circle class="wm-ping" cx="160" cy="120" r="4.5"></circle>
                <text class="wm-host-tip" x="172" y="116">Canada</text>
                <circle class="wm-ping-ring live" cx="185" cy="150" r="9"></circle>
                <circle class="wm-ping live" cx="185" cy="150" r="4.5"></circle>
                <text class="wm-host-tip" x="197" y="154">USA</text>
                <circle class="wm-ping-ring" cx="160" cy="185" r="9"></circle>
                <circle class="wm-ping" cx="160" cy="185" r="4.5"></circle>
                <text class="wm-host-tip" x="118" y="205">Mexico</text>
            </g>
        </svg>

        <div class="map-head">
            <div class="map-title"><span class="map-dot"></span> <span data-i18n="liveMapTitle">Live World Cup Map</span></div>
            <div class="map-legend">
                <span class="legend-item"><i class="legend-bullet live"></i> <span data-i18n="legendLive">Live Now</span></span>
                <span class="legend-item"><i class="legend-bullet upcoming"></i> <span data-i18n="legendUpcoming">Upcoming</span></span>
                <span class="legend-item"><i class="legend-bullet finished"></i> <span data-i18n="legendFinished">Finished</span></span>
            </div>
        </div>

        <div class="map-stage">
            <div class="map-pins">
                <?php if (!empty($mapMatches)): ?>
                    <?php foreach ($mapMatches as $mp): ?>
                        <?php
                            $mpStatus = wc_match_status_label($mp);
                            $mpScore = ($mpStatus === 'Live' || $mpStatus === 'Finished')
                                ? ((is_null($mp['home_score']) ? '-' : (int)$mp['home_score']) . ' - ' . (is_null($mp['away_score']) ? '-' : (int)$mp['away_score']))
                                : 'VS';
                        ?>
                        <div class="map-pin-card">
                            <div class="map-pin-status <?= htmlspecialchars($mpStatus, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($mpStatus, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <div class="map-pin-teams">
                                <div class="map-team-mini">
                                    <?php if (!empty($mp['home_logo'])): ?>
                                        <img src="<?= htmlspecialchars($mp['home_logo'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                                    <?php else: ?>
                                        <span class="map-team-fallback"><?= htmlspecialchars(mb_substr(wc_safe_team($mp['home_team']), 0, 1), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                    <span><?= htmlspecialchars(mb_substr(wc_safe_team($mp['home_team']), 0, 3), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <div class="map-score-mini"><?= htmlspecialchars($mpScore, ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="map-team-mini">
                                    <?php if (!empty($mp['away_logo'])): ?>
                                        <img src="<?= htmlspecialchars($mp['away_logo'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                                    <?php else: ?>
                                        <span class="map-team-fallback"><?= htmlspecialchars(mb_substr(wc_safe_team($mp['away_team']), 0, 1), ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                    <span><?= htmlspecialchars(mb_substr(wc_safe_team($mp['away_team']), 0, 3), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                            </div>
                            <?php $mpLoc = trim((string)($mp['city'] ?? '') . (!empty($mp['stadium']) ? ' • ' . $mp['stadium'] : '')); ?>
                            <?php if ($mpLoc !== ''): ?><div class="map-pin-city">📍 <?= htmlspecialchars($mpLoc, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                            <div class="map-pin-time">
                                <?= htmlspecialchars(date('d M Y - h:i A', strtotime((string)$mp['match_datetime'])), ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="bracket-empty">No synced matches available for the live map yet.</div>
                <?php endif; ?>
            </div>

            <aside class="tournament-progress">
                <div class="progress-title" data-i18n="progressTitle">Tournament Progress</div>
                <div class="progress-line <?= $knockoutFinished > 0 ? '' : 'pending' ?>">
                    <span class="progress-node"></span>
                    <div class="progress-copy">
                        <b data-i18n="stageKnockout">Knockout Stage</b>
                        <span><?= (int)$knockoutFinished ?> / <?= (int)$knockoutTotal ?> <span data-i18n="completed">completed</span></span>
                    </div>
                </div>
                <div class="progress-line <?= count($knockoutStages['Quarter Finals'] ?? []) > 0 ? '' : 'pending' ?>">
                    <span class="progress-node"></span>
                    <div class="progress-copy">
                        <b data-i18n="stageQF">Quarter Finals</b>
                        <span><?= (int)count($knockoutStages['Quarter Finals'] ?? []) ?> <span data-i18n="matchesFromApi">matches</span></span>
                    </div>
                </div>
                <div class="progress-line <?= count($knockoutStages['Semi Finals'] ?? []) > 0 ? '' : 'pending' ?>">
                    <span class="progress-node"></span>
                    <div class="progress-copy">
                        <b data-i18n="stageSF">Semi Finals</b>
                        <span><?= (int)count($knockoutStages['Semi Finals'] ?? []) ?> <span data-i18n="matchesFromApi">matches</span></span>
                    </div>
                </div>
                <div class="progress-line <?= count($knockoutStages['Final'] ?? []) > 0 ? '' : 'pending' ?>">
                    <span class="progress-node"></span>
                    <div class="progress-copy">
                        <b data-i18n="stageFinal">Final</b>
                        <span><?= (int)count($knockoutStages['Final'] ?? []) ?> <span data-i18n="matchFromApi">match</span></span>
                    </div>
                </div>
                <div class="match-links">
                    <a href="/WC2026/matches" class="match-link soft" data-i18n="viewFullMatches">View Full Matches</a>
                </div>
            </aside>
        </div>
    </section>
    <?php endif; /* legacy world-map */ ?>

    <section class="card knockout-card bracket-preview-mode" id="knockoutBracketCard">
        <div class="bracket-head">
            <div class="bracket-title-wrap">
                <div class="bracket-title">🏆 <span data-i18n="bracketTitle">World Cup Knockout Bracket</span></div>
                <div class="bracket-subtitle" data-i18n="bracketSub">
                    Projected tournament path (R32 → R16 → QF → SF → Final). Preview is collapsed for a cleaner home page.
                </div>
            </div>

            <div class="bracket-head-actions">
                <div class="map-legend">
                    <span class="legend-item"><i class="legend-bullet finished"></i> <span data-i18n="legendFinished">Finished</span></span>
                    <span class="legend-item"><i class="legend-bullet upcoming"></i> <span data-i18n="legendUpcoming">Upcoming</span></span>
                    <span class="legend-item"><i class="legend-bullet live"></i> <span data-i18n="legendLive2">Live</span></span>
                </div>

                <button type="button" class="bracket-more-btn" id="bracketMoreBtn">
                    <span data-i18n="more">MORE</span> <span>→</span>
                </button>
            </div>
        </div>

        <div class="bracket-shell" id="bracketShell">
            <?php if ($knockoutTotal > 0): ?>
                <div class="bracket-grid">
                    <?php foreach ($knockoutStages as $stageName => $stageMatches): ?>
                        <?php if ($stageName === 'Knockout' && empty($stageMatches)) continue; ?>
                        <div class="bracket-col">
                            <div class="bracket-stage-title"><?= htmlspecialchars($stageName, ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="bracket-col-body">
                            <?php if (!empty($stageMatches)): ?>
                                <?php foreach ($stageMatches as $bm): ?>
                                    <?php
                                        $bmStatus = wc_match_status_label($bm);
                                        $bmUpcomingClass = $bmStatus === 'Upcoming' ? 'upcoming' : '';
                                        $bmStadiumRaw = (string)($bm['stadium'] ?? '');
                                        // Projected fixtures use a placeholder venue; only show a real DB venue.
                                        $bmVenue = (stripos($bmStadiumRaw, 'Projected') !== false)
                                            ? ''
                                            : trim($bmStadiumRaw . (!empty($bm['city']) ? ' • ' . $bm['city'] : ''));
                                        $bmWhen  = date('D, d M Y • h:i A', strtotime((string)$bm['match_datetime']));
                                    ?>
                                    <div class="bracket-match">
                                        <button type="button" class="bracket-info" aria-label="Match details"
                                            data-round="<?= htmlspecialchars($stageName, ENT_QUOTES, 'UTF-8') ?>"
                                            data-home="<?= htmlspecialchars(wc_safe_team($bm['home_team']), ENT_QUOTES, 'UTF-8') ?>"
                                            data-away="<?= htmlspecialchars(wc_safe_team($bm['away_team']), ENT_QUOTES, 'UTF-8') ?>"
                                            data-hlogo="<?= htmlspecialchars((string)($bm['home_logo'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            data-alogo="<?= htmlspecialchars((string)($bm['away_logo'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            data-hs="<?= is_null($bm['home_score']) ? '-' : (int)$bm['home_score'] ?>"
                                            data-as="<?= is_null($bm['away_score']) ? '-' : (int)$bm['away_score'] ?>"
                                            data-status="<?= htmlspecialchars($bmStatus, ENT_QUOTES, 'UTF-8') ?>"
                                            data-when="<?= htmlspecialchars($bmWhen, ENT_QUOTES, 'UTF-8') ?>"
                                            data-venue="<?= htmlspecialchars($bmVenue, ENT_QUOTES, 'UTF-8') ?>">i</button>
                                        <div class="bracket-row">
                                            <div class="bracket-team">
                                                <?php if (!empty($bm['home_logo'])): ?>
                                                    <img src="<?= htmlspecialchars($bm['home_logo'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                                                <?php else: ?>
                                                    <span class="bracket-team-fallback"><?= htmlspecialchars(mb_substr(wc_safe_team($bm['home_team']), 0, 1), ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php endif; ?>
                                                <span><?= htmlspecialchars(wc_safe_team($bm['home_team']), ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <div class="bracket-score"><?= is_null($bm['home_score']) ? '-' : (int)$bm['home_score'] ?></div>
                                        </div>
                                        <div class="bracket-row">
                                            <div class="bracket-team">
                                                <?php if (!empty($bm['away_logo'])): ?>
                                                    <img src="<?= htmlspecialchars($bm['away_logo'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                                                <?php else: ?>
                                                    <span class="bracket-team-fallback"><?= htmlspecialchars(mb_substr(wc_safe_team($bm['away_team']), 0, 1), ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php endif; ?>
                                                <span><?= htmlspecialchars(wc_safe_team($bm['away_team']), ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <div class="bracket-score"><?= is_null($bm['away_score']) ? '-' : (int)$bm['away_score'] ?></div>
                                        </div>
                                        <div class="bracket-status <?= htmlspecialchars($bmUpcomingClass, ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars($bmStatus, ENT_QUOTES, 'UTF-8') ?> •
                                            <?= htmlspecialchars(date('d M - h:i A', strtotime((string)$bm['match_datetime'])), ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="bracket-match">
                                    <div class="bracket-row">
                                        <div class="bracket-team"><span class="bracket-team-fallback">?</span><span>Waiting API</span></div>
                                        <div class="bracket-score">-</div>
                                    </div>
                                    <div class="bracket-row">
                                        <div class="bracket-team"><span class="bracket-team-fallback">?</span><span>Waiting API</span></div>
                                        <div class="bracket-score">-</div>
                                    </div>
                                    <div class="bracket-status upcoming">Upcoming</div>
                                </div>
                            <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="bracket-empty">
                    Knockout bracket will appear automatically once knockout fixtures are available from the API.
                    The section is already connected to <strong>WC2026_Matches</strong> and will update after sync.
                </div>
            <?php endif; ?>
        </div>

        <div class="bracket-preview-footer" id="bracketPreviewFooter">
            <button type="button" class="bracket-show-full" id="bracketShowFull">
                <span data-i18n="showFullBracket">Show Full Bracket</span> <span>↗</span>
            </button>
        </div>
    </section>

    <!-- (1) Mystery / coming-soon teaser — to be opened later -->
    <section class="card soon-card" id="mysteryBoxCard">
        <div class="soon-shimmer"></div>
        <div class="soon-badge">🔒 <span data-i18n="soonBadge">Coming Soon</span></div>
        <div class="soon-lock">🎁</div>
        <div class="soon-title" data-i18n="soonTitle">Mystery Box</div>
        <div class="soon-sub" data-i18n="soonSub">A surprise World Cup reward drop is on its way. Keep playing and predicting — this box unlocks later in the tournament.</div>
        <button type="button" class="soon-cta" disabled><span data-i18n="soonCta">Unlocks Soon</span> →</button>
    </section>

    <section class="layout">
        <div>
            <div class="card">
                <h2 class="card-title">
                    <span data-i18n="dailyGoalRush">Daily Goal Rush</span>
                    <small data-i18n="onePlayPerDay">One play per day</small>
                </h2>

                <?php if ($todayPlayed > 0): ?>
                    <?php $tg = $todayGame[0] ?? []; ?>
                    <div class="disabled-box">
                        You already used today’s play.<br><br>
                        <?php if (($tg['status'] ?? '') === 'Started'): ?>
                            Your game was started today, so it is locked until tomorrow even if the browser was refreshed or closed.<br><br>
                            <strong>Status:</strong> Started<br>
                            <strong>Total Points:</strong> 0<br><br>
                        <?php else: ?>
                            <strong>Goals:</strong> <?= (int)($tg['goals'] ?? 0) ?><br>
                            <strong>Goal Points:</strong> <?= (int)($tg['goal_points'] ?? 0) ?><br>
                            <strong>Bonus:</strong> <?= !empty($tg['bonus_correct']) ? 'Correct +' . (int)$tg['bonus_points'] : 'Not earned' ?><br>
                            <strong>Total Points:</strong> <?= (int)($tg['total_points'] ?? 0) ?><br><br>
                        <?php endif; ?>
                        Come back tomorrow for a new chance.
                    </div>
                <?php else: ?>
                    <div class="game-panel" id="gamePanel">
                        <div class="game-top">
                            <div class="game-pill"><span data-i18n="gameTime">Time</span><b id="timeLeft">30</b></div>
                            <div class="game-pill"><span data-i18n="gameGoals">Goals</span><b id="goals">0</b></div>
                            <div class="game-pill"><span data-i18n="gamePoints">Points</span><b id="points">0</b></div>
                        </div>

                        <div class="field">
                            <div class="stadium"></div>
                            <div class="light-beam left"></div>
                            <div class="light-beam right"></div>
                            <div class="pitch-lines"></div>
                            <div class="goal"></div>
                            <div class="target-zone high left">30</div>
                            <div class="target-zone high right">30</div>
                            <div class="target-zone mid left">20</div>
                            <div class="target-zone mid right">20</div>
                            <div class="target-zone center">10</div>
                            <div class="keeper" id="keeper"></div>
                            <button class="ball disabled" id="ball" type="button" aria-label="Shoot the ball"></button>
                            <div class="aim"><div class="aim-dot" id="aimDot"></div></div>
                            <div class="golden-banner" id="goldenBanner">GOLDEN BALL +50</div>
                            <div class="combo-banner" id="comboBanner">COMBO</div>
                            <div class="tap-hint">
                                <div>
                                    🎯 <span data-i18n="tapToShoot">Tap the ball to shoot!</span>
                                    <small data-i18n="tapHintSub">Use the moving target line and avoid the goalkeeper</small>
                                </div>
                            </div>
                            <div class="goal-flash" id="goalFlash">GOAL!</div>
                            <div class="miss-flash" id="missFlash">SAVED!</div>
                            <div class="countdown-overlay" id="countdownOverlay">3</div>
                        </div>
                    </div>

                    <div class="game-actions">
                        <button class="primary-btn" id="startBtn" type="button" data-i18n="startGame">Start Daily Game</button>
                        <button class="secondary-btn hidden-shoot" id="shootBtn" type="button" disabled data-i18n="shoot">Shoot</button>
                        <span class="game-tip" data-i18n="gameTip">After start, the ball becomes your shoot button.</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div>
            <div class="card">
                <h2 class="card-title" data-i18n="topLeaderboard">Top Leaderboard</h2>

                <?php if (!empty($leaderboard)): ?>
                    <?php foreach ($leaderboard as $i => $row): ?>
                        <div class="leader-row">
                            <div class="rank"><?= $i + 1 ?></div>
                            <div>
                                <div class="leader-name"><?= htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="leader-loc"><?= htmlspecialchars($row['location'] ?: 'CATRION', ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <div class="points"><?= (int)$row['total_points'] ?> pts</div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="disabled-box">
                        Leaderboard will appear after participants play the daily game.
                    </div>
                <?php endif; ?>
            </div>

            <!-- (11) Current users — recently online -->
            <div class="card">
                <h2 class="card-title">
                    <span data-i18n="onlineNowTitle">Online Now</span>
                    <small><?= (int)$onlineCount ?> <span data-i18n="online">online</span></small>
                </h2>
                <?php if (!empty($onlineUsers)): ?>
                    <div class="online-list">
                        <?php foreach ($onlineUsers as $ou): ?>
                            <div class="online-row">
                                <span class="online-ava"><?= htmlspecialchars(mb_substr((string)$ou['full_name'], 0, 1), ENT_QUOTES, 'UTF-8') ?><span class="online-dot"></span></span>
                                <div class="online-meta">
                                    <div class="online-name"><?= htmlspecialchars((string)$ou['full_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="online-loc"><?= htmlspecialchars(($ou['location'] ?: 'CATRION'), ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="disabled-box" data-i18n="noOnline">No participants are active right now. Be the first to play!</div>
                <?php endif; ?>
            </div>
        </div>
    </section>


</main>

<div class="modal" id="quizModal">
    <div class="modal-card">
        <div class="modal-kicker">Bonus Question</div>
        <h3 id="questionText">Question</h3>
        <div class="answers" id="answersBox"></div>
        <div class="result-box" id="quizResult"></div>
    </div>
</div>

<div class="modal" id="mysteryModal">
    <div class="modal-card">
        <div class="modal-kicker">CATRION Food Bonus Roll</div>
        <h3>Roll the daily food bonus</h3>
        <div class="mystery-note">Get three matching food icons for the highest bonus. Your bonus will be saved with today’s game result.</div>
        <div class="food-roll-machine">
            <div class="mystery-grid">
                <div class="food-slot" id="foodSlot1">🍔</div>
                <div class="food-slot" id="foodSlot2">🍕</div>
                <div class="food-slot" id="foodSlot3">🍟</div>
            </div>
            <button class="food-roll-btn" id="foodRollBtn" type="button">Roll Bonus</button>
            <div class="food-roll-result" id="mysteryResult"></div>
        </div>
    </div>
</div>

<div class="modal" id="finalModal">
    <div class="modal-card">
        <div class="modal-kicker">Game Completed</div>
        <h3 id="finalTitle">Your score is saved</h3>
        <div class="disabled-box" id="finalDetails"></div>
        <br>
        <button class="primary-btn" type="button" onclick="location.reload()">Done</button>
    </div>
</div>

<!-- (5) My Profile pop-up (mobile number hidden, Participants removed) -->
<div class="modal" id="profileModal">
    <div class="modal-card">
        <div class="modal-kicker" data-i18n="myProfile">My Profile</div>
        <h3><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></h3>
        <div class="profile-line">
            <span data-i18n="pfType">User Type</span>
            <span><?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="profile-line">
            <span data-i18n="statOverall">Overall Score</span>
            <span><?= (int)$overallScore ?></span>
        </div>
        <div class="profile-line">
            <span data-i18n="statWeekly">Weekly Score</span>
            <span><?= (int)$weeklyScore ?></span>
        </div>
        <div class="profile-line">
            <span data-i18n="statRank">My Rank</span>
            <span><?= htmlspecialchars($myRank, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="profile-line">
            <span data-i18n="pfDays">Days Played</span>
            <span><?= (int)$playedDays ?></span>
        </div>
        <br>
        <button class="primary-btn" type="button" id="closeProfileBtn" data-i18n="close">Close</button>
    </div>
</div>

<!-- (8) Fan Filter Studio pop-up — NATIVE studio (camera + platform/country/frame), saves to /WC/api/save_filter_photo.php -->
<div class="modal" id="fanFilterModal">
    <div class="modal-card fanfilter-modal-card ff-native">
        <div class="fanfilter-modal-head">
            <div class="modal-kicker" data-i18n="fanFilterTitle">Fan Filter Studio</div>
            <div class="fanfilter-modal-tools">
                <a href="/WC/fan_filter.php" target="_blank" rel="noopener" class="match-link soft" data-i18n="openFull">Open full page</a>
                <button type="button" class="ff-close" id="closeFanFilterBtn" aria-label="Close">✕</button>
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
                                    data-platform-id="<?= (int)$platform['id'] ?>" data-platform-name="<?= htmlspecialchars($platform['platform_name'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-platform-code="<?= htmlspecialchars($platform['platform_code'], ENT_QUOTES, 'UTF-8') ?>" data-width="<?= (int)$platform['width'] ?>" data-height="<?= (int)$platform['height'] ?>">
                                    <?php if ($plogo !== ''): ?><img class="platform-logo" src="<?= htmlspecialchars($plogo, ENT_QUOTES, 'UTF-8') ?>" alt="" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"><?php endif; ?>
                                    <span class="platform-logo-fallback" <?= $plogo === '' ? 'style="display:flex;"' : '' ?>><?= htmlspecialchars($pfb, ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="platform-text"><strong><?= htmlspecialchars($platform['platform_name'], ENT_QUOTES, 'UTF-8') ?></strong><span><?= (int)$platform['width'] ?> × <?= (int)$platform['height'] ?></span></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="option-block">
                        <div class="section-label">Choose Country</div>
                        <div class="country-picker" id="countryPicker">
                            <button type="button" class="country-trigger active" id="countryTrigger">
                                <span class="country-selected">
                                    <img id="selectedCountryFlag" src="<?= htmlspecialchars(wc_asset_path($ffSelectedCountry['flag_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" alt="">
                                    <span style="min-width:0;">
                                        <span class="country-name" id="selectedCountryName"><?= htmlspecialchars($ffSelectedCountry['country_name'] ?? 'Choose Country', ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="country-code" id="selectedCountryCode"><?= htmlspecialchars($ffSelectedCountry['country_code'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                                    </span>
                                </span>
                                <span class="country-arrow">⌄</span>
                            </button>
                            <div class="country-menu" id="countryMenu">
                                <div class="country-search-wrap"><input type="text" class="country-search" id="countrySearch" placeholder="Search country..."></div>
                                <div class="country-options" id="countryOptions">
                                    <?php foreach ($ffCountries as $index => $country): ?>
                                        <button type="button" class="country-option country-choice <?= $index === 0 ? 'active' : '' ?>"
                                            data-country-id="<?= (int)$country['id'] ?>" data-country-name="<?= htmlspecialchars($country['country_name'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-country-code="<?= htmlspecialchars($country['country_code'], ENT_QUOTES, 'UTF-8') ?>" data-flag="<?= htmlspecialchars(wc_asset_path($country['flag_path']), ENT_QUOTES, 'UTF-8') ?>"
                                            data-search="<?= htmlspecialchars(strtolower($country['country_name'] . ' ' . $country['country_code']), ENT_QUOTES, 'UTF-8') ?>">
                                            <img src="<?= htmlspecialchars(wc_asset_path($country['flag_path']), ENT_QUOTES, 'UTF-8') ?>" alt="">
                                            <span><span class="country-name"><?= htmlspecialchars($country['country_name'], ENT_QUOTES, 'UTF-8') ?></span><span class="country-code"><?= htmlspecialchars($country['country_code'], ENT_QUOTES, 'UTF-8') ?></span></span>
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
                                    data-frame-id="<?= (int)$frame['id'] ?>" data-platform-id="<?= (int)$frame['platform_id'] ?>" data-frame-name="<?= htmlspecialchars($frame['frame_name'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-frame="<?= htmlspecialchars(wc_asset_path($frame['frame_path']), ENT_QUOTES, 'UTF-8') ?>" style="<?= $isSel ? '' : 'display:none;' ?>">
                                    <img src="<?= htmlspecialchars(wc_asset_path($preview), ENT_QUOTES, 'UTF-8') ?>" alt=""><span><?= htmlspecialchars($frame['frame_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="selection-summary" id="selectionSummary">
                        Selected platform: <strong><?= htmlspecialchars($ffSelectedPlatform['platform_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></strong><br>
                        Selected country: <strong><?= htmlspecialchars($ffSelectedCountry['country_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></strong><br>
                        Selected frame: <strong>-</strong>
                    </div>
                </aside>
            </div>
        <?php endif; ?>
        </div>
    </div>
</div>

<!-- (3) How to Use pop-up -->
<div class="modal" id="howToModal">
    <div class="modal-card">
        <div class="modal-kicker" data-i18n="navHowTo">How to Use</div>
        <h3 data-i18n="howToTitle">Get started in 5 steps</h3>
        <ul class="help-list">
            <li class="help-item"><span class="help-num">1</span><div><b data-i18n="howStep1t">Play the Daily Goal Rush</b><span data-i18n="howStep1d">Tap the ball (or press Space) to shoot. Aim with the moving target line, beat the goalkeeper, and score as many goals as you can in 30 seconds — once per day.</span></div></li>
            <li class="help-item"><span class="help-num">2</span><div><b data-i18n="howStep2t">Predict real matches</b><span data-i18n="howStep2d">Open Matches or the “Next Matches · 24h” cards, enter your score prediction, and submit before kickoff. Predictions lock the moment the match starts.</span></div></li>
            <li class="help-item"><span class="help-num">3</span><div><b data-i18n="howStep3t">Create a Fan Filter photo</b><span data-i18n="howStep3d">Open the Fan Filter Studio, pick your country and a frame, snap a selfie or upload a photo, then save & download it.</span></div></li>
            <li class="help-item"><span class="help-num">4</span><div><b data-i18n="howStep4t">Join the Fan Wall</b><span data-i18n="howStep4d">Post your moment, like and comment on others. New posts appear in the notification bar at the top.</span></div></li>
            <li class="help-item"><span class="help-num">5</span><div><b data-i18n="howStep5t">Climb the leaderboard</b><span data-i18n="howStep5d">Collect points from games, predictions and your daily photo to rise up the weekly and overall rankings.</span></div></li>
        </ul>
        <button class="primary-btn" type="button" id="howToClose" data-i18n="close">Close</button>
    </div>
</div>

<!-- (4) How to collect points pop-up -->
<div class="modal" id="pointsModal">
    <div class="modal-card">
        <div class="modal-kicker" data-i18n="navPoints">Points</div>
        <h3 data-i18n="pointsTitle">How to collect points</h3>
        <table class="pts-table">
            <thead><tr><th data-i18n="ptsAction">Action</th><th data-i18n="ptsReward">Reward</th></tr></thead>
            <tbody>
                <tr><td data-i18n="ptsGoal">Daily game — score a goal (by zone)</td><td><b>+10 / +20 / +30</b></td></tr>
                <tr><td data-i18n="ptsGolden">Golden ball goal (bonus)</td><td><b>+50</b></td></tr>
                <tr><td data-i18n="ptsCombo">Combo streak (every 3 / 5 goals)</td><td><b>+20 / +50</b></td></tr>
                <tr><td data-i18n="ptsMystery">Daily food bonus roll</td><td><b>+10 → +100</b></td></tr>
                <tr><td data-i18n="ptsPredWin">Predict the match winner</td><td><b>+<?= WC_PTS_PREDICT_WINNER ?></b></td></tr>
                <tr><td data-i18n="ptsPredScore">Predict the correct score</td><td><b>+<?= WC_PTS_PREDICT_SCORE ?></b></td></tr>
                <tr><td data-i18n="ptsChampion">Predict the champion (Final only)</td><td><b>+<?= WC_PTS_PREDICT_CHAMPION ?></b></td></tr>
                <tr><td data-i18n="ptsPhoto">Fan Filter photo (once per day)</td><td><b>+<?= WC_PTS_PHOTO ?></b></td></tr>
            </tbody>
        </table>
        <p class="pts-note" data-i18n="ptsNote">Procedure: 1) Play the daily game and bank your goal + bonus points. 2) Submit predictions before kickoff — points are awarded automatically once the official result is synced. 3) Save your daily Fan Filter photo. Weekly score resets every week; overall score is cumulative across the tournament.</p>
        <button class="primary-btn" type="button" id="pointsClose" data-i18n="close">Close</button>
    </div>
</div>

<!-- Knockout bracket — per-game details pop-up -->
<div class="modal" id="bracketInfoModal">
    <div class="modal-card bracket-info-card">
        <div class="modal-kicker" id="biRound">Match</div>
        <div class="bi-teams">
            <div class="bi-team">
                <div class="bi-logo" id="biHomeLogo"></div>
                <div class="bi-name" id="biHome">—</div>
            </div>
            <div class="bi-score"><span id="biHomeScore">-</span><i>:</i><span id="biAwayScore">-</span></div>
            <div class="bi-team">
                <div class="bi-logo" id="biAwayLogo"></div>
                <div class="bi-name" id="biAway">—</div>
            </div>
        </div>
        <div class="bi-meta">
            <div class="bi-row"><span>Status</span><b id="biStatus">—</b></div>
            <div class="bi-row"><span>Kickoff</span><b id="biWhen">—</b></div>
            <div class="bi-row" id="biVenueRow"><span>Venue</span><b id="biVenue">—</b></div>
        </div>
        <button class="primary-btn" type="button" id="biClose" data-i18n="close">Close</button>
    </div>
</div>

<!-- (B3) Submit-prediction pop-up (opened from Next Matches cards) -->
<div class="predict-popup" id="homePredictPopup" aria-hidden="true">
    <div class="predict-popup-card">
        <div class="predict-popup-top">
            <div class="predict-popup-title" data-i18n="submitPrediction">Submit Prediction</div>
            <button type="button" class="predict-popup-close" id="homePredictClose" aria-label="Close">×</button>
        </div>
        <iframe id="homePredictFrame" src="about:blank" title="Match Prediction"></iframe>
    </div>
</div>

<!-- ===================== SITE FOOTER ===================== -->
<footer class="wc-footer">
    <div class="wc-footer-inner">
        <div class="wc-foot-brand">
            <div class="wc-foot-by">
                <b>Developed by CATRION &copy; IT Digital &amp; Transformation</b>
            </div>
        </div>
        <div class="wc-foot-note" data-i18n="footerNote">
            CATRION FIFA World Cup 2026 Challenge
        </div>
    </div>
</footer>

<!-- ===================== AI FAN AGENT (floating widget) ===================== -->
<button class="wc-agent-fab" id="wcAgentFab" type="button" aria-label="Open AI Fan Agent" aria-controls="wcAgentPanel" aria-expanded="false">
    <span class="wc-agent-online" aria-hidden="true"></span>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <rect x="4" y="7" width="16" height="12" rx="4"/><path d="M12 7V4M9 13h.01M15 13h.01M9 16h6M2 11v3M22 11v3"/>
    </svg>
</button>

<section class="wc-agent-panel" id="wcAgentPanel" aria-label="AI Fan Agent" aria-hidden="true">
    <div class="wc-agent-head">
        <span class="wc-agent-ava" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="7" width="16" height="12" rx="4"/><path d="M12 7V4M9 13h.01M15 13h.01M9 16h6M2 11v3M22 11v3"/></svg>
        </span>
        <div class="wc-agent-meta">
            <b data-i18n="agentName">WC2026 Fan Agent</b>
            <span data-i18n="agentStatus">Online • AI-powered</span>
        </div>
        <button class="wc-agent-x" id="wcAgentClose" type="button" aria-label="Close">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
    </div>
    <div class="wc-agent-tools">
        <select id="wcAgentMode" class="wc-agent-select" aria-label="AI function">
            <option value="fan" data-i18n="agentFan">Fan Assistant</option>
            <option value="predict" data-i18n="agentPredict">Match Predictor</option>
            <option value="tactical" data-i18n="agentTactical">Tactical Analyst</option>
            <option value="summary" data-i18n="agentSummary">Match Summary</option>
            <option value="command" data-i18n="agentCommand">Command Center</option>
        </select>
    </div>
    <div class="wc-agent-body" id="wcAgentBody" aria-live="polite"></div>
    <div class="wc-agent-chips" id="wcAgentChips"></div>
    <form class="wc-agent-input" id="wcAgentForm">
        <input id="wcAgentText" autocomplete="off" data-i18n-ph="agentPlaceholder" placeholder="Ask about today’s matches…">
        <button type="submit" aria-label="Send">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
        </button>
    </form>
</section>

<script>
const csrf = <?= json_encode($csrf) ?>;
const bonusQuestion = <?= $bonusQuestionJson ?>;

const GAME_DURATION = 30;
const NORMAL_BALL_POINTS = 10;
const GOLDEN_BONUS_POINTS = 50;

let gameStarted = false;
let gameFinished = false;
let timeLeft = GAME_DURATION;
let goals = 0;
let targetPoints = 0;
let goldenGoals = 0;
let goldenPoints = 0;
let comboStreak = 0;
let comboBonus = 0;
let mysteryBonus = 0;
let bonusAnswer = '';
let bonusQuestionId = bonusQuestion ? parseInt(bonusQuestion.id, 10) : 0;
let bonusShown = false;
let timer = null;
let goldenTimer = null;
let quizTriggerTimeLeft = Math.floor(Math.random() * 13) + 15; // random during middle of 45s game // random between 20 and 40 seconds left
let aimX = 50;
let aimDirection = 1;
let keeperX = 40;
let keeperDirection = 1;
let isGoldenBall = false;
let savingScore = false;
let mysteryChosen = false;
let gameStartTimestamp = null;

const timeLeftEl = document.getElementById('timeLeft');
const goalsEl = document.getElementById('goals');
const pointsEl = document.getElementById('points');
const aimDot = document.getElementById('aimDot');
const keeper = document.getElementById('keeper');
const ball = document.getElementById('ball');
const startBtn = document.getElementById('startBtn');
const shootBtn = document.getElementById('shootBtn');
const goalFlash = document.getElementById('goalFlash');
const missFlash = document.getElementById('missFlash');
const countdownOverlay = document.getElementById('countdownOverlay');
const goldenBanner = document.getElementById('goldenBanner');
const comboBanner = document.getElementById('comboBanner');
const mysteryModal = document.getElementById('mysteryModal');
const mysteryResult = document.getElementById('mysteryResult');

function currentGamePoints(){
    return targetPoints + goldenPoints + comboBonus + mysteryBonus;
}

function updateScore(){
    goalsEl.textContent = goals;
    pointsEl.textContent = currentGamePoints();
}

function showGoalFlash(text){
    if (!goalFlash) return;
    goalFlash.textContent = text || 'GOAL!';
    goalFlash.classList.remove('active');
    void goalFlash.offsetWidth;
    goalFlash.classList.add('active');
    setTimeout(() => goalFlash.classList.remove('active'), 750);
}

function showMissFlash(){
    if (!missFlash) return;
    missFlash.classList.remove('active');
    void missFlash.offsetWidth;
    missFlash.classList.add('active');
    setTimeout(() => missFlash.classList.remove('active'), 550);
}

function showCombo(text){
    if (!comboBanner) return;
    comboBanner.textContent = text;
    comboBanner.classList.add('active');
    setTimeout(() => comboBanner.classList.remove('active'), 900);
}

function setGoldenBall(active){
    isGoldenBall = active;
    if (!ball || !goldenBanner) return;

    if (active) {
        ball.classList.add('golden');
        goldenBanner.classList.add('active');
    } else {
        ball.classList.remove('golden');
        goldenBanner.classList.remove('active');
    }
}

function scheduleGoldenBall(){
    if (!gameStarted || gameFinished) return;

    const delay = (Math.floor(Math.random() * 8) + 10) * 1000; // 10-17 seconds

    goldenTimer = setTimeout(() => {
        if (!gameStarted || gameFinished) return;

        setGoldenBall(true);

        setTimeout(() => {
            setGoldenBall(false);
            scheduleGoldenBall();
        }, 4200);
    }, delay);
}

function getTargetPoints(x){
    if (x <= 24 || x >= 76) return 30;
    if (x <= 38 || x >= 62) return 20;
    return 10;
}

function animateField(){
    if (!aimDot || !keeper) return;

    const elapsed = GAME_DURATION - timeLeft;
    let aimSpeed = elapsed > 45 ? 2.55 : (elapsed > 30 ? 2.25 : 1.85);
    let keeperSpeed = elapsed > 45 ? 2.25 : (elapsed > 30 ? 1.75 : 1.12);

    aimX += aimDirection * aimSpeed;
    if (aimX >= 96 || aimX <= 4) aimDirection *= -1;
    aimDot.style.left = aimX + '%';

    keeperX += keeperDirection * keeperSpeed;
    if (keeperX >= 70 || keeperX <= 30) keeperDirection *= -1;

    // Smart goalkeeper: occasional quick reaction jump
    if (gameStarted && !gameFinished && Math.random() < 0.012) {
        keeperX += (aimX > keeperX ? 1 : -1) * (Math.random() * 4);
        keeperX = Math.max(30, Math.min(70, keeperX));
    }

    keeper.style.left = keeperX + '%';

    requestAnimationFrame(animateField);
}

function shoot(){
    if (!gameStarted || gameFinished || !ball) return;

    const targetX = aimX;
    const keeperCenter = keeperX;
    const blocked = Math.abs(targetX - keeperCenter) < 9;

    ball.classList.add('shooting');
    ball.style.left = targetX + '%';

    if (blocked) {
        // goalkeeper save: the ball stops in front of the keeper
        ball.style.bottom = '205px';
        ball.style.transform = 'translateX(-50%) scale(.8)';
    } else {
        // GOAL: the ball flies up and settles INSIDE the goal frame / net
        ball.style.bottom = '252px';
        ball.style.transform = 'translateX(-50%) scale(.5)';
    }

    setTimeout(() => {
        ball.classList.remove('shooting');
        ball.style.left = '50%';
        ball.style.bottom = '';   // clear inline so the per-breakpoint CSS idle position (desktop/mobile) applies
        ball.style.transform = '';
    }, 460);

    if (!blocked) {
        const shotPoints = getTargetPoints(targetX);
        goals++;
        targetPoints += shotPoints;
        comboStreak++;

        let flashText = '+' + shotPoints;

        if (isGoldenBall) {
            goldenGoals++;
            goldenPoints += GOLDEN_BONUS_POINTS;
            flashText = 'GOLDEN +' + (shotPoints + GOLDEN_BONUS_POINTS);
            setGoldenBall(false);
        }

        if (comboStreak > 0 && comboStreak % 5 === 0) {
            comboBonus += 50;
            showCombo('🔥 COMBO x' + comboStreak + ' +50');
        } else if (comboStreak > 0 && comboStreak % 3 === 0) {
            comboBonus += 20;
            showCombo('🔥 COMBO x' + comboStreak + ' +20');
        }

        updateScore();
        showGoalFlash(flashText);
    } else {
        comboStreak = 0;
        showMissFlash();
    }
}

function openQuiz(){
    if (!bonusQuestion || bonusShown) return;
    bonusShown = true;

    const modal = document.getElementById('quizModal');
    const questionText = document.getElementById('questionText');
    const answersBox = document.getElementById('answersBox');
    const quizResult = document.getElementById('quizResult');

    questionText.textContent = bonusQuestion.question_text;
    answersBox.innerHTML = '';
    quizResult.className = 'result-box';
    quizResult.textContent = '';

    const answers = [
        ['A', bonusQuestion.option_a],
        ['B', bonusQuestion.option_b],
        ['C', bonusQuestion.option_c],
        ['D', bonusQuestion.option_d]
    ];

    answers.forEach(([key, value]) => {
        const btn = document.createElement('button');
        btn.className = 'answer-btn';
        btn.type = 'button';
        btn.textContent = key + '. ' + value;
        btn.onclick = () => {
            bonusAnswer = key;
            quizResult.className = 'result-box ok';
            quizResult.textContent = 'Answer submitted! Continue playing.';
            setTimeout(() => modal.classList.remove('active'), 750);
        };
        answersBox.appendChild(btn);
    });

    modal.classList.add('active');
}

function runStartCountdown(callback){
    if (!countdownOverlay) {
        callback();
        return;
    }

    let count = 3;
    countdownOverlay.textContent = count;
    countdownOverlay.classList.add('active');

    const countdownTimer = setInterval(() => {
        count--;

        if (count > 0) {
            countdownOverlay.textContent = count;
        } else {
            countdownOverlay.textContent = 'GO!';
        }

        if (count < 0) {
            clearInterval(countdownTimer);
            countdownOverlay.classList.remove('active');
            callback();
        }
    }, 700);
}

function startGame(){
    if (gameStarted) return;

    startBtn.disabled = true;

    const formData = new FormData();
    formData.append('csrf', csrf);
    formData.append('action', 'start_daily_game');

    fetch('/WC2026/', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) {
            alert(data.message || 'You already played today.');
            location.reload();
            return;
        }

        runStartCountdown(() => {
            gameStarted = true;
            gameFinished = false;
            gameStartTimestamp = Date.now();
            timeLeft = GAME_DURATION;
            goals = 0;
            targetPoints = 0;
            goldenGoals = 0;
            goldenPoints = 0;
            comboStreak = 0;
            comboBonus = 0;
            mysteryBonus = 0;
            mysteryChosen = false;
            bonusAnswer = '';
            bonusShown = false;
            quizTriggerTimeLeft = Math.floor(Math.random() * 13) + 15; // random during middle of 45s game

            updateScore();
            timeLeftEl.textContent = timeLeft;
            shootBtn.disabled = false;
            if (ball) ball.classList.remove('disabled');

            scheduleGoldenBall();

            timer = setInterval(() => {
                timeLeft--;
                timeLeftEl.textContent = timeLeft;

                if (timeLeft === quizTriggerTimeLeft) {
                    openQuiz();
                }

                if (timeLeft <= 0) {
                    finishGame();
                }
            }, 1000);
        });
    })
    .catch(() => {
        startBtn.disabled = false;
        alert('Unable to start the game. Please try again.');
    });
}

function finishGame(){
    if (gameFinished) return;

    gameFinished = true;
    gameStarted = false;
    shootBtn.disabled = true;
    if (ball) ball.classList.add('disabled');

    if (timer) clearInterval(timer);
    if (goldenTimer) clearTimeout(goldenTimer);
    setGoldenBall(false);

    openMysteryBox();
}

function openMysteryBox(){
    if (!mysteryModal) {
        saveScore();
        return;
    }

    mysteryChosen = false;
    mysteryModal.classList.add('active');

    const foodItems = ['🍔', '🍕', '🍟', '🌭', '🥤', '🍗'];
    const slots = [
        document.getElementById('foodSlot1'),
        document.getElementById('foodSlot2'),
        document.getElementById('foodSlot3')
    ];
    const rollBtn = document.getElementById('foodRollBtn');

    if (mysteryResult) {
        mysteryResult.className = 'food-roll-result';
        mysteryResult.innerHTML = '';
    }

    if (!rollBtn || slots.some(slot => !slot)) {
        saveScore();
        return;
    }

    rollBtn.disabled = false;
    rollBtn.textContent = 'Roll Bonus';

    slots.forEach((slot, index) => {
        slot.textContent = foodItems[index] || '🍔';
        slot.classList.remove('rolling');
    });

    rollBtn.onclick = () => {
        if (mysteryChosen) return;
        mysteryChosen = true;
        rollBtn.disabled = true;
        rollBtn.textContent = 'Rolling...';

        let ticks = 0;
        const maxTicks = 30;
        const finalItems = [
            foodItems[Math.floor(Math.random() * foodItems.length)],
            foodItems[Math.floor(Math.random() * foodItems.length)],
            foodItems[Math.floor(Math.random() * foodItems.length)]
        ];

        slots.forEach(slot => slot.classList.add('rolling'));

        const rollTimer = setInterval(() => {
            ticks++;

            slots.forEach((slot) => {
                slot.textContent = foodItems[Math.floor(Math.random() * foodItems.length)];
            });

            if (ticks >= maxTicks) {
                clearInterval(rollTimer);

                slots.forEach((slot, index) => {
                    slot.classList.remove('rolling');
                    slot.textContent = finalItems[index];
                });

                const counts = {};
                finalItems.forEach(item => {
                    counts[item] = (counts[item] || 0) + 1;
                });

                const highestMatch = Math.max(...Object.values(counts));

                if (highestMatch === 3) {
                    mysteryBonus = 100;
                    mysteryResult.innerHTML = '🎉 JACKPOT! Three matching meals <strong>+100 Points</strong>';
                } else if (highestMatch === 2) {
                    mysteryBonus = 50;
                    mysteryResult.innerHTML = '✨ Nice Roll! Two matching meals <strong>+50 Points</strong>';
                } else {
                    mysteryBonus = 10;
                    mysteryResult.innerHTML = '🍽️ Daily Roll Bonus <strong>+10 Points</strong>';
                }

                mysteryResult.classList.add('active');
                updateScore();

                setTimeout(() => {
                    mysteryModal.classList.remove('active');
                    saveScore();
                }, 2200);
            }
        }, 85);
    };
}

function saveScore(){
    if (savingScore) return;
    savingScore = true;
    if (startBtn) startBtn.disabled = true;

    const formData = new FormData();
    formData.append('csrf', csrf);
    formData.append('action', 'finish_daily_game');
    formData.append('goals', goals);
    formData.append('target_points', targetPoints);
    formData.append('golden_goals', goldenGoals);
    formData.append('golden_points', goldenPoints);
    formData.append('combo_bonus', comboBonus);
    formData.append('mystery_bonus', mysteryBonus);
    formData.append('goal_points', currentGamePoints());
    formData.append('duration_seconds', gameStartTimestamp ? Math.max(1, Math.min(GAME_DURATION, Math.round((Date.now() - gameStartTimestamp) / 1000))) : GAME_DURATION);
    formData.append('bonus_question_id', bonusQuestionId);
    formData.append('bonus_answer', bonusAnswer);

    fetch('/WC2026/', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    })
    .then(async (r) => {
        const text = await r.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error(text || 'Invalid server response');
        }
    })
    .then(data => {
        if (data.ok) {
            setTimeout(() => {
                location.reload();
            }, 600);
        } else {
            alert(data.message || 'Unable to save your score.');
            location.reload();
        }
    })
    .catch((err) => {
        console.error(err);
        alert('Unable to save your score. ' + (err && err.message ? err.message : 'Please try again.'));
        savingScore = false;
    });
}

if (startBtn) startBtn.addEventListener('click', startGame);
if (shootBtn) shootBtn.addEventListener('click', shoot);
if (ball) ball.addEventListener('click', shoot);
document.addEventListener('keydown', e => {
    // Don't hijack Space while the user is typing in a field (chatbot, fan wall, comments…)
    const t = e.target;
    const typing = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable);
    if (typing) return;
    if (e.code === 'Space') {
        e.preventDefault();
        shoot();
    }
});

animateField();
</script>
<script>
/* WC2026 game duration hard sync */
document.addEventListener('DOMContentLoaded', function(){
    const timeLeftElHard = document.getElementById('timeLeft');
    if (timeLeftElHard && timeLeftElHard.textContent.trim() === '45') {
        timeLeftElHard.textContent = '30';
    }
});
</script>


<script>
(function(){
    const card = document.getElementById('knockoutBracketCard');
    const moreBtn = document.getElementById('bracketMoreBtn');
    const showFullBtn = document.getElementById('bracketShowFull');

    const footer = document.getElementById('bracketPreviewFooter');

    function expandBracket(){
        if (!card) return;
        card.classList.add('expanded');
        card.classList.remove('bracket-preview-mode');
        if (moreBtn) moreBtn.innerHTML = 'MINIMIZE <span>✕</span>';
        if (footer) {
            footer.style.display = 'flex';
            if (showFullBtn) showFullBtn.innerHTML = '<span>Minimize Bracket</span> <span>↙</span>';
        }
        setTimeout(() => card.scrollIntoView({ behavior: 'smooth', block: 'start' }), 80);
    }

    function collapseBracket(){
        if (!card) return;
        card.classList.remove('expanded');
        card.classList.add('bracket-preview-mode');
        if (moreBtn) moreBtn.innerHTML = 'MORE <span>→</span>';
        if (showFullBtn) showFullBtn.innerHTML = '<span>Show Full Bracket</span> <span>↗</span>';
        setTimeout(() => card.scrollIntoView({ behavior: 'smooth', block: 'start' }), 80);
    }

    function toggleBracket(){
        if (card && card.classList.contains('expanded')) collapseBracket();
        else expandBracket();
    }

    if (moreBtn) moreBtn.addEventListener('click', toggleBracket);
    if (showFullBtn) showFullBtn.addEventListener('click', toggleBracket);

    /* per-game details pop-up */
    var biModal = document.getElementById('bracketInfoModal');
    function setLogo(el, src, name){
        if(!el) return;
        el.innerHTML = src ? '<img src="'+src+'" alt="">' : (name||'?').trim().charAt(0);
    }
    function openInfo(btn){
        if(!biModal) return;
        var ds = btn.dataset;
        document.getElementById('biRound').textContent = ds.round || 'Match';
        document.getElementById('biHome').textContent = ds.home || '—';
        document.getElementById('biAway').textContent = ds.away || '—';
        document.getElementById('biHomeScore').textContent = ds.hs || '-';
        document.getElementById('biAwayScore').textContent = ds.as || '-';
        document.getElementById('biStatus').textContent = ds.status || '—';
        document.getElementById('biWhen').textContent = ds.when || '—';
        var venueRow = document.getElementById('biVenueRow');
        if(ds.venue){ document.getElementById('biVenue').textContent = ds.venue; venueRow.style.display=''; }
        else { venueRow.style.display='none'; }
        setLogo(document.getElementById('biHomeLogo'), ds.hlogo, ds.home);
        setLogo(document.getElementById('biAwayLogo'), ds.alogo, ds.away);
        biModal.classList.add('active');
    }
    function closeInfo(){ if(biModal) biModal.classList.remove('active'); }
    document.querySelectorAll('.bracket-info').forEach(function(btn){
        btn.addEventListener('click', function(e){ e.stopPropagation(); openInfo(btn); });
    });
    var biClose = document.getElementById('biClose');
    if(biClose) biClose.addEventListener('click', closeInfo);
    if(biModal) biModal.addEventListener('click', function(e){ if(e.target===biModal) closeInfo(); });
    document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeInfo(); });
})();
</script>


<!-- ===================== WOW / ANIMATED UI LAYER ===================== -->
<style>
@media (prefers-reduced-motion:no-preference){

  /* ---------- keyframes ---------- */
  @keyframes wcUp{from{opacity:0;transform:translateY(28px)}to{opacity:1;transform:none}}
  @keyframes wcAurora{0%{transform:translate(-5%,-3%) rotate(0deg)}50%{transform:translate(5%,3%) rotate(10deg)}100%{transform:translate(-5%,-3%) rotate(0deg)}}
  @keyframes wcFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-10px)}}
  @keyframes wcGrad{0%{background-position:0% 50%}100%{background-position:200% 50%}}
  @keyframes wcPulse{0%{box-shadow:0 0 0 0 rgba(17,163,106,.6)}100%{box-shadow:0 0 0 12px rgba(17,163,106,0)}}
  @keyframes wcShine{0%{transform:translateX(-160%) skewX(-18deg)}55%,100%{transform:translateX(280%) skewX(-18deg)}}
  @keyframes wcConfFall{to{transform:translateY(380px) rotate(560deg);opacity:0}}
  @keyframes wcGlowPulse{0%,100%{opacity:.5}50%{opacity:1}}

  /* ---------- animated background layers (injected by JS) ---------- */
  .wc-aurora{
    position:fixed;inset:-25%;z-index:-1;pointer-events:none;opacity:.7;
    background:
      radial-gradient(closest-side at 22% 26%, rgba(85,183,255,.22), transparent 70%),
      radial-gradient(closest-side at 80% 16%, rgba(14,99,230,.24), transparent 70%),
      radial-gradient(closest-side at 62% 82%, rgba(245,200,91,.12), transparent 70%);
    animation:wcAurora 24s ease-in-out infinite;
  }
  .wc-spark{position:fixed;inset:0;z-index:-1;pointer-events:none}

  /* ---------- hero entrance + animated headline ---------- */
  .hero .badge{animation:wcUp .8s cubic-bezier(.16,1,.3,1) both}
  .hero h1{animation:wcUp .9s cubic-bezier(.16,1,.3,1) .12s both}
  .hero p{animation:wcUp .9s cubic-bezier(.16,1,.3,1) .24s both}
  .daily-card{animation:wcUp 1s cubic-bezier(.16,1,.3,1) .34s both}

  .hero h1 span{
    background:linear-gradient(90deg,#A8E7FF,#FFE19A,#55B7FF,#A8E7FF);
    background-size:200% auto;
    -webkit-background-clip:text;background-clip:text;
    -webkit-text-fill-color:transparent;color:transparent;
    animation:wcGrad 6s linear infinite;
  }

  .badge i{animation:wcGlowPulse 1.6s ease-in-out infinite}
  .daily-card:before{animation:wcFloat 4s ease-in-out infinite}

  /* sheen sweep across the white logo card */
  .logo-card{position:relative;overflow:hidden}
  .logo-card:after{
    content:"";position:absolute;top:0;left:0;width:42%;height:100%;pointer-events:none;
    background:linear-gradient(120deg,transparent,rgba(255,255,255,.7),transparent);
    transform:translateX(-160%) skewX(-18deg);
    animation:wcShine 6s ease-in-out 1.2s infinite;
  }

  /* ---------- scroll reveal (added via JS only when supported) ---------- */
  .wc-reveal{opacity:0;transform:translateY(30px);
    transition:opacity .7s cubic-bezier(.16,1,.3,1),transform .7s cubic-bezier(.16,1,.3,1)}
  .wc-reveal.wc-in{opacity:1;transform:none}

  /* ---------- cards: lift + glow + cursor spotlight ---------- */
  .wc-spot{position:relative}
  .wc-spot>*{position:relative;z-index:1}
  .wc-glow{
    position:absolute;inset:0;border-radius:inherit;pointer-events:none;opacity:0;z-index:0;
    transition:opacity .35s ease;
    background:radial-gradient(440px circle at var(--mx,50%) var(--my,50%), rgba(85,183,255,.16), transparent 45%);
  }
  .wc-spot:hover .wc-glow{opacity:1}

  .stat{position:relative;overflow:hidden;transition:transform .25s ease, box-shadow .3s ease}
  .stat:hover{transform:translateY(-5px); box-shadow:0 26px 60px rgba(7,42,85,.30)}
  .stat .wc-bar{position:absolute;left:0;right:0;top:0;height:3px;z-index:2;transform:scaleX(0);transform-origin:left;
    background:linear-gradient(90deg,#0E63E6,#55B7FF,#F5C85B);transition:transform .45s ease}
  .stat:hover .wc-bar{transform:scaleX(1)}

  .live-map-card:hover,.knockout-card:hover,
  .match-card-premium:hover,.match-list-card:hover,
  .layout .card:hover{
    box-shadow:0 30px 72px rgba(0,0,0,.30), 0 0 0 1px rgba(168,231,255,.30), 0 0 42px rgba(85,183,255,.12) !important;
  }

  /* ---------- live map pins: radar ping ---------- */
  .map-pin-status.Live{position:relative}
  .map-pin-status.Live:after{
    content:"";position:absolute;right:-5px;top:50%;width:9px;height:9px;border-radius:50%;
    transform:translateY(-50%);background:#11A36A;animation:wcPulse 1.5s ease-out infinite;
  }

  /* ---------- primary buttons: hover shine ---------- */
  .match-link.primary,.primary-btn,.food-roll-btn,.bracket-show-full,.bracket-more-btn{position:relative;overflow:hidden}
  .match-link.primary:after,.primary-btn:after,.food-roll-btn:after,
  .bracket-show-full:after,.bracket-more-btn:after{
    content:"";position:absolute;top:0;left:0;width:38%;height:100%;pointer-events:none;
    background:linear-gradient(120deg,transparent,rgba(255,255,255,.5),transparent);
    transform:translateX(-160%) skewX(-18deg);transition:transform .6s ease;
  }
  .match-link.primary:hover:after,.primary-btn:hover:after,.food-roll-btn:hover:after,
  .bracket-show-full:hover:after,.bracket-more-btn:hover:after{transform:translateX(320%) skewX(-18deg)}

  /* ---------- confetti (spawned on GOAL) ---------- */
  .wc-conf{position:absolute;top:28%;width:9px;height:15px;border-radius:2px;z-index:30;pointer-events:none;
    animation:wcConfFall 1.15s ease-in forwards}

  /* gentle float for the daily-game ball is already in the base CSS */
}
</style>

<script>
(function(){
  "use strict";
  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  var d = document, body = d.body;

  /* ---------- aurora ---------- */
  var aurora = d.createElement('div'); aurora.className = 'wc-aurora'; body.appendChild(aurora);

  /* ---------- drifting spark particles ---------- */
  var canvas = d.createElement('canvas'); canvas.className = 'wc-spark'; body.appendChild(canvas);
  var ctx = canvas.getContext('2d');
  var DPR = Math.min(window.devicePixelRatio || 1, 1.5), W = 0, H = 0, parts = [], raf = null;
  function size(){ W = innerWidth; H = innerHeight; canvas.width = W*DPR; canvas.height = H*DPR;
    canvas.style.width = W+'px'; canvas.style.height = H+'px'; ctx.setTransform(DPR,0,0,DPR,0,0); }
  function seed(){ parts = []; var n = Math.min(64, Math.round(W*H/30000));
    for (var i=0;i<n;i++) parts.push({x:Math.random()*W, y:Math.random()*H, r:Math.random()*2+.5,
      s:Math.random()*.45+.12, a:Math.random()*.45+.12}); }
  function loop(){ ctx.clearRect(0,0,W,H);
    for (var i=0;i<parts.length;i++){ var p=parts[i]; p.y-=p.s; if(p.y<-6){ p.y=H+6; p.x=Math.random()*W; }
      ctx.fillStyle='rgba(168,231,255,'+p.a+')'; ctx.beginPath(); ctx.arc(p.x,p.y,p.r,0,6.28); ctx.fill(); }
    raf = requestAnimationFrame(loop); }
  size(); seed(); loop();
  addEventListener('resize', function(){ size(); seed(); });
  d.addEventListener('visibilitychange', function(){ if(d.hidden){ cancelAnimationFrame(raf); raf=null; } else if(!raf){ loop(); } });

  /* ---------- cursor spotlight on cards ---------- */
  d.querySelectorAll('.card,.stat,.live-map-card,.knockout-card').forEach(function(el){
    el.classList.add('wc-spot');
    var g = d.createElement('span'); g.className = 'wc-glow'; el.insertBefore(g, el.firstChild);
    el.addEventListener('pointermove', function(e){
      var r = el.getBoundingClientRect();
      el.style.setProperty('--mx', (e.clientX-r.left)+'px');
      el.style.setProperty('--my', (e.clientY-r.top)+'px');
    });
  });

  /* ---------- stat accent bar ---------- */
  d.querySelectorAll('.stat').forEach(function(el){ var b=d.createElement('span'); b.className='wc-bar'; el.insertBefore(b, el.firstChild); });

  /* ---------- scroll reveal ---------- */
  if ('IntersectionObserver' in window){
    var io = new IntersectionObserver(function(es){ es.forEach(function(en){
      if (en.isIntersecting){ en.target.classList.add('wc-in'); io.unobserve(en.target); } });
    }, {threshold:.12, rootMargin:'0px 0px -6% 0px'});
    d.querySelectorAll('.stat, .news-ticker, .live-map-card, .knockout-card, .match-dashboard .card, .layout .card')
      .forEach(function(el){ el.classList.add('wc-reveal'); io.observe(el); });
  }

  /* ---------- count-up for integer stat values ---------- */
  if ('IntersectionObserver' in window){
    var cio = new IntersectionObserver(function(es){ es.forEach(function(en){
      if (!en.isIntersecting) return;
      var el = en.target, raw = el.textContent.trim();
      cio.unobserve(el);
      if (!/^\d+$/.test(raw)) return;
      var target = parseInt(raw,10), start = performance.now(), dur = 1200;
      (function tick(now){ var p = Math.min(1,(now-start)/dur), e = 1-Math.pow(1-p,3);
        el.textContent = Math.round(target*e); if(p<1) requestAnimationFrame(tick); })(start);
    }); }, {threshold:.6});
    d.querySelectorAll('.stat-value').forEach(function(el){ cio.observe(el); });
  }

  /* ---------- confetti burst whenever a GOAL flashes ---------- */
  var gf = d.getElementById('goalFlash');
  if (gf && 'MutationObserver' in window){
    var cols = ['#F5C85B','#55B7FF','#11A36A','#FFFFFF','#0E63E6','#FFE19A'];
    new MutationObserver(function(muts){
      for (var i=0;i<muts.length;i++){
        if (muts[i].attributeName === 'class' && gf.classList.contains('active')){
          var host = gf.parentElement || body, hw = host.clientWidth || 320;
          for (var k=0;k<28;k++){
            var s = d.createElement('span'); s.className = 'wc-conf';
            s.style.left = (16 + Math.random()*(hw-32)) + 'px';
            s.style.background = cols[k % cols.length];
            s.style.animationDelay = (Math.random()*.12) + 's';
            s.style.transform = 'translateY(0) rotate(' + (Math.random()*360) + 'deg)';
            host.appendChild(s);
            (function(node){ setTimeout(function(){ node.remove(); }, 1400); })(s);
          }
          break;
        }
      }
    }).observe(gf, {attributes:true, attributeFilter:['class']});
  }
})();
</script>

<!-- ===================== THEMES · WORLD MAP · FOOTER · AGENT · BILINGUAL ===================== -->
<style>
/* ---------- top toggles (theme + language) ---------- */
.theme-switch,.lang-switch{display:inline-flex;gap:4px;padding:4px;border-radius:999px;
    background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22)}
.theme-btn,.lang-btn{border:0;border-radius:999px;padding:8px 12px;background:transparent;color:#fff;
    font-weight:900;font-size:12px;cursor:pointer;font-family:inherit;line-height:1;transition:.2s}
.theme-btn.active,.lang-btn.active{background:#fff;color:var(--deep,#0B2C55)}
html[data-theme="saudi"] .theme-btn.active,html[data-theme="saudi"] .lang-btn.active{color:#06371f}

/* ---------- inline world map ---------- */
.world-map-svg{position:absolute;left:0;right:0;top:48px;height:calc(100% - 66px);width:100%;
    z-index:1;opacity:.6;pointer-events:none}
.world-map-svg .wm-land path{fill:rgba(85,183,255,.16);stroke:rgba(168,231,255,.5);stroke-width:1.4;
    filter:drop-shadow(0 0 7px rgba(85,183,255,.30))}
.world-map-svg .wm-grid line{stroke:rgba(168,231,255,.10);stroke-width:1}

/* ---------- footer ---------- */
.wc-footer{position:relative;z-index:2;margin-top:26px;padding:26px clamp(18px,3.2vw,48px);
    border-top:1px solid rgba(168,231,255,.14);background:rgba(4,18,40,.55)}
.wc-footer-inner{max-width:1680px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:18px;flex-wrap:wrap}
.wc-foot-brand{display:flex;align-items:center;gap:14px}
.wc-footer .logo-card{width:120px;height:46px;box-shadow:0 10px 24px rgba(0,0,0,.3)}
.wc-footer .logo-card img{max-width:96px;max-height:30px}
.wc-foot-by b{display:block;color:#fff;font-size:14px;font-weight:900;letter-spacing:-.2px}
.wc-odd{display:inline-block;margin-top:5px;font-size:11px;font-weight:900;color:#071A35;letter-spacing:.3px;
    background:linear-gradient(135deg,#F5C85B,#FFE19A);padding:3px 11px;border-radius:999px;text-transform:uppercase}
.wc-foot-note{color:rgba(255,255,255,.62);font-size:12px;font-weight:700;max-width:420px;text-align:end}
@media(max-width:768px){.wc-footer-inner{flex-direction:column;text-align:center}.wc-foot-note{text-align:center}}

/* ---------- AI agent floating widget ---------- */
.wc-agent-fab{position:fixed;bottom:24px;inset-inline-end:24px;z-index:1400;width:62px;height:62px;border-radius:50%;
    border:0;cursor:pointer;color:#06202e;display:flex;align-items:center;justify-content:center;
    background:linear-gradient(135deg,#F5C85B,#FFE19A);box-shadow:0 14px 34px rgba(245,200,91,.45);
    animation:wcAgentFloat 4s ease-in-out infinite}
.wc-agent-fab svg{width:30px;height:30px}
.wc-agent-fab:before{content:"";position:absolute;inset:0;border-radius:50%;border:2px solid #F5C85B;animation:wcAgentRing 2.4s ease-out infinite}
.wc-agent-online{position:absolute;top:5px;inset-inline-end:5px;width:13px;height:13px;border-radius:50%;background:#22C55E;border:2px solid #06202e;box-shadow:0 0 8px #22C55E}
@keyframes wcAgentFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}
@keyframes wcAgentRing{0%{transform:scale(1);opacity:.6}80%,100%{transform:scale(1.8);opacity:0}}
.wc-agent-panel{position:fixed;bottom:98px;inset-inline-end:24px;z-index:1400;width:min(94vw,380px);
    background:linear-gradient(180deg,#071F42,#05162F);border:1px solid rgba(168,231,255,.25);border-radius:22px;overflow:hidden;
    box-shadow:0 34px 80px rgba(0,0,0,.6);transform:translateY(18px) scale(.97);opacity:0;visibility:hidden;
    transform-origin:bottom right;transition:transform .3s cubic-bezier(.16,1,.3,1),opacity .3s,visibility .3s}
html[dir="rtl"] .wc-agent-panel{transform-origin:bottom left}
.wc-agent-panel.open{transform:none;opacity:1;visibility:visible}
.wc-agent-head{display:flex;align-items:center;gap:11px;padding:15px 16px;background:linear-gradient(135deg,rgba(245,200,91,.16),rgba(85,183,255,.12));border-bottom:1px solid rgba(168,231,255,.16)}
.wc-agent-ava{width:40px;height:40px;border-radius:50%;flex:none;display:flex;align-items:center;justify-content:center;color:#06202e;background:linear-gradient(135deg,#F5C85B,#FFE19A)}
.wc-agent-ava svg{width:23px;height:23px}
.wc-agent-meta{flex:1;min-width:0}
.wc-agent-meta b{display:block;color:#fff;font-size:14px;font-weight:900}
.wc-agent-meta span{color:rgba(255,255,255,.7);font-size:11px;font-weight:700}
.wc-agent-x{width:32px;height:32px;border-radius:10px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.06);color:#fff;cursor:pointer;flex:none;display:flex;align-items:center;justify-content:center}
.wc-agent-x svg{width:18px;height:18px}
.wc-agent-tools{padding:12px 16px 0}
.wc-agent-select{width:100%;min-height:40px;border-radius:12px;border:1px solid rgba(168,231,255,.22);background:rgba(255,255,255,.06);color:#fff;font:inherit;font-weight:800;font-size:13px;padding:0 12px}
.wc-agent-select option{color:#06202e}
.wc-agent-body{padding:14px 16px;height:280px;overflow-y:auto;display:flex;flex-direction:column;gap:10px}
.wc-msg{max-width:86%;padding:10px 13px;border-radius:15px;font-size:13px;line-height:1.6;white-space:pre-wrap;word-wrap:break-word}
.wc-msg.bot{align-self:flex-start;background:rgba(255,255,255,.07);border:1px solid rgba(168,231,255,.16);color:#eaf3ff;border-bottom-left-radius:5px}
.wc-msg.user{align-self:flex-end;background:linear-gradient(135deg,#F5C85B,#FFE19A);color:#06202e;font-weight:700;border-bottom-right-radius:5px}
html[dir="rtl"] .wc-msg.bot{border-bottom-left-radius:15px;border-bottom-right-radius:5px}
html[dir="rtl"] .wc-msg.user{border-bottom-right-radius:15px;border-bottom-left-radius:5px}
.wc-typing{align-self:flex-start;display:flex;gap:4px;padding:11px 13px;background:rgba(255,255,255,.07);border:1px solid rgba(168,231,255,.16);border-radius:15px}
.wc-typing i{width:7px;height:7px;border-radius:50%;background:#F5C85B;animation:wcType 1.2s infinite}
.wc-typing i:nth-child(2){animation-delay:.2s}.wc-typing i:nth-child(3){animation-delay:.4s}
@keyframes wcType{0%{opacity:.25}20%{opacity:1}100%{opacity:.25}}
.wc-agent-chips{display:flex;flex-wrap:wrap;gap:7px;padding:0 16px 10px}
.wc-chip{font-size:12px;color:#FFE19A;padding:7px 11px;border-radius:20px;background:rgba(245,200,91,.10);border:1px solid rgba(245,200,91,.3);cursor:pointer;font-family:inherit;font-weight:700}
.wc-chip:hover{background:rgba(245,200,91,.22);color:#fff}
.wc-agent-input{display:flex;gap:8px;padding:11px 16px 15px;border-top:1px solid rgba(168,231,255,.14)}
.wc-agent-input input{flex:1;min-height:42px;border-radius:12px;border:1px solid rgba(168,231,255,.22);background:rgba(255,255,255,.06);color:#fff;padding:0 13px;font:inherit;font-size:13px}
.wc-agent-input input::placeholder{color:rgba(255,255,255,.5)}
.wc-agent-input button{width:42px;height:42px;flex:none;border:0;border-radius:12px;cursor:pointer;color:#06202e;background:linear-gradient(135deg,#F5C85B,#FFE19A);display:flex;align-items:center;justify-content:center}
.wc-agent-input button svg{width:20px;height:20px}
html[dir="rtl"] .wc-agent-input button svg{transform:scaleX(-1)}
@media(max-width:600px){.wc-agent-panel{inset-inline-end:12px;inset-inline-start:12px;width:auto}}

/* ---------- SAUDI THEME (toggle) ---------- */
html[data-theme="saudi"]{background:#03190f !important}
html[data-theme="saudi"] body{
    background:
        radial-gradient(circle at 8% 8%, rgba(34,197,94,.18), transparent 30%),
        radial-gradient(circle at 92% 8%, rgba(17,163,106,.30), transparent 34%),
        radial-gradient(circle at 50% 76%, rgba(245,200,91,.08), transparent 30%),
        linear-gradient(180deg,#03190f 0%,#06371f 45%,#03190f 100%) !important}
html[data-theme="saudi"] .hero{
    background:
        radial-gradient(circle at 72% 18%, rgba(17,163,106,.40), transparent 32%),
        radial-gradient(circle at 15% 28%, rgba(34,197,94,.18), transparent 30%),
        linear-gradient(135deg,#03190f 0%,#0a5a32 48%,#0e7c43 100%) !important}
html[data-theme="saudi"] .hero h1 span{color:#FFE19A}
html[data-theme="saudi"] .live-map-card,
html[data-theme="saudi"] .knockout-card,
html[data-theme="saudi"] .match-card-premium,
html[data-theme="saudi"] .match-list-card,
html[data-theme="saudi"] .layout .card{
    background:
        radial-gradient(circle at 100% 0%, rgba(17,163,106,.26), transparent 34%),
        linear-gradient(135deg,#06291a 0%,#0a3f27 58%,#0e6a3e 100%) !important}
html[data-theme="saudi"] .game-panel{background:#06291a !important}
html[data-theme="saudi"] .world-map-svg .wm-land path{fill:rgba(34,197,94,.18);stroke:rgba(126,244,174,.5)}
html[data-theme="saudi"] .wc-aurora{background:
    radial-gradient(closest-side at 22% 26%, rgba(34,197,94,.22), transparent 70%),
    radial-gradient(closest-side at 80% 16%, rgba(17,163,106,.24), transparent 70%),
    radial-gradient(closest-side at 62% 82%, rgba(245,200,91,.12), transparent 70%) !important}
html[data-theme="saudi"] .map-dot{background:#FFE19A;box-shadow:0 0 18px rgba(255,225,154,.9)}

/* RTL niceties */
html[dir="rtl"] .news-track span{animation-direction:reverse}
html[dir="rtl"] .bracket-match:after{right:auto;left:-16px}
</style>

<script>
(function(){
  "use strict";
  var d=document, root=document.documentElement;

  /* ===== THEME ===== */
  var theme = (function(){ try{ return localStorage.getItem('wc_theme')||'catrion'; }catch(e){ return 'catrion'; } })();
  function applyTheme(t){
    theme = (t==='saudi')?'saudi':'catrion';
    root.setAttribute('data-theme', theme);
    d.querySelectorAll('.theme-btn').forEach(function(b){ b.classList.toggle('active', b.getAttribute('data-theme-set')===theme); });
    try{ localStorage.setItem('wc_theme', theme); }catch(e){}
  }
  d.querySelectorAll('.theme-btn').forEach(function(b){ b.addEventListener('click', function(){ applyTheme(b.getAttribute('data-theme-set')); }); });

  /* ===== BILINGUAL ===== */
  var T = {
    en:{
      brandSub:'Prediction League • Daily Goal Rush',themeCatrion:'CATRION',themeSaudi:'Saudi',
      navHome:'Home',navMatches:'Matches',navLogout:'Logout',
      heroBadge:'CATRION FIFA WORLD CUP 2026',
      heroTitle:'Cheer with <span>CATRION</span>',
      heroWelcome:'Welcome,',
      heroIntro:'Play the daily 30-second challenge, predict real World Cup matches, collect points, and climb the CATRION leaderboard.',
      dailyTitle:'Challenge Hub',
      dailySub:'Daily Goal Rush, real match predictions, live fixtures, bonus questions, and leaderboard points.',
      miniDailyGame:'Daily Game',miniMatches:'Matches',miniPrize:'Prize Pool',
      statMyPoints:'My Points',statMyPointsNote:'All game points',statRank:'My Rank',statRankNote:'Daily game leaderboard',
      statBest:'Best Score',statBestNote:'Highest daily score',statMatches:'World Cup Matches',live:'live',finished:'finished',
      statPrize:'Prize Pool',matchNews:'Match News',liveMapTitle:'Live World Cup Map',
      legendLive:'Live Now',legendUpcoming:'Upcoming',legendFinished:'Finished',legendLive2:'Live',
      progressTitle:'Tournament Progress',stageKnockout:'Knockout Stage',completed:'completed',
      stageQF:'Quarter Finals',matchesFromApi:'matches',stageSF:'Semi Finals',stageFinal:'Final',matchFromApi:'match',
      viewFullMatches:'View Full Matches',bracketTitle:'World Cup Knockout Bracket',
      bracketSub:'Projected tournament path (R32 → R16 → QF → SF → Final). Preview is collapsed for a cleaner home page.',
      more:'MORE',showFullBracket:'Show Full Bracket',
      kickerLive:'Live Now',kickerNext:'Next World Cup Match',kickerMatches:'World Cup Matches',
      viewMatches:'View Matches',submitPrediction:'Submit Prediction',worldCupFeed:'World Cup Feed',matchesSynced:'matches synced',
      dailyGoalRush:'Daily Goal Rush',onePlayPerDay:'One play per day',
      gameTime:'Time',gameGoals:'Goals',gamePoints:'Points',
      tapToShoot:'Tap the ball to shoot!',tapHintSub:'Use the moving target line and avoid the goalkeeper',
      startGame:'Start Daily Game',shoot:'Shoot',gameTip:'After start, the ball becomes your shoot button.',
      topLeaderboard:'Top Leaderboard',myProfile:'My Profile',pfName:'Name',pfMobile:'Mobile',pfType:'User Type',pfDays:'Days Played',pfParticipants:'Participants',
      footerNote:'CATRION FIFA World Cup 2026 Challenge',
      agentName:'WC2026 Fan Agent',agentStatus:'Online • AI-powered',
      agentFan:'Fan Assistant',agentPredict:'Match Predictor',agentTactical:'Tactical Analyst',agentSummary:'Match Summary',agentCommand:'Command Center',
      agentPlaceholder:'Ask about today’s matches…',
      agentGreeting:'Hi! I’m your World Cup 2026 Fan Agent. Pick a skill above, then ask me anything about fixtures, predictions, tactics, or today’s action.',
      agentChips:['What should I watch today?','Predict the next match','Give me a tactical view'],
      agentTyping:'Thinking…',agentErr:'Sorry, the agent could not respond. Please try again.',
      agentInstruction:'Respond in clear, fan-friendly English. If live data is missing, say what is missing.',
      navFanFilter:'Fan Filter',navProfile:'My Profile',close:'Close',
      next24Title:'Next Matches · within 24 hours',liveNow:'Live',closed:'Closed',predictionsClosed:'Predictions Closed',
      no24:'No matches kicking off within the next 24 hours. Check the full schedule.',
      statOverall:'Overall Score',statOverallNote:'All-time points',statWeekly:'Weekly Score',statWeeklyNote:'This week',
      statParticipants:'Participants',onlineNow:'online now',onlineNowTitle:'Online Now',online:'online',
      noOnline:'No participants are active right now. Be the first to play!',
      socialTitle:'Fan Wall',socialSub:'Share your moment • comment • like',socialPlaceholder:'Say something about the World Cup…',
      attachPhoto:'Add photo',post:'Post',socialLoading:'Loading the fan wall…',socialEmpty:'Be the first to post on the fan wall!',
      likeWord:'Like',commentWord:'Comment',sendWord:'Send',commentPh:'Write a comment…',justNow:'just now',
      fanFilterTitle:'Fan Filter Studio',openFull:'Open full page',
      soonBadge:'Coming Soon',soonTitle:'Mystery Box',soonSub:'A surprise World Cup reward drop is on its way. Keep playing and predicting — this box unlocks later in the tournament.',soonCta:'Unlocks Soon',
      ffChooseCountry:'Choose Country',ffChooseFrame:'Choose Frame',ffStartCam:'Start Camera',ffUpload:'Upload Photo',ffCapture:'Capture',ffRetake:'Retake',ffSave:'Save & Download',ffReady:'Start the camera or upload a photo.',ffNeedData:'Add active countries and frames to enable the studio.',
      openWall:'Open Fan Wall',closeWall:'Minimize',ffCardSub:'Create your World Cup fan photo — pick your country, choose a frame, snap a selfie and download.',ffOpenStudio:'Open Studio',newPost:'new',
      navHowTo:'How to Use',navPoints:'Points',ffSearchCountry:'Search country…',
      howToTitle:'Get started in 5 steps',
      howStep1t:'Play the Daily Goal Rush',howStep1d:'Tap the ball (or press Space) to shoot. Aim with the moving target line, beat the goalkeeper, and score as many goals as you can in 30 seconds — once per day.',
      howStep2t:'Predict real matches',howStep2d:'Open Matches or the “Next Matches · 24h” cards, enter your score prediction, and submit before kickoff. Predictions lock the moment the match starts.',
      howStep3t:'Create a Fan Filter photo',howStep3d:'Open the Fan Filter Studio, pick your country and a frame, snap a selfie or upload a photo, then save & download it.',
      howStep4t:'Join the Fan Wall',howStep4d:'Post your moment, like and comment on others. New posts appear in the notification bar at the top.',
      howStep5t:'Climb the leaderboard',howStep5d:'Collect points from games, predictions and your daily photo to rise up the weekly and overall rankings.',
      pointsTitle:'How to collect points',ptsAction:'Action',ptsReward:'Reward',
      ptsGoal:'Daily game — score a goal (by zone)',ptsGolden:'Golden ball goal (bonus)',ptsCombo:'Combo streak (every 3 / 5 goals)',ptsMystery:'Daily food bonus roll',
      ptsPredWin:'Predict the match winner',ptsPredScore:'Predict the correct score',ptsChampion:'Predict the champion (Final only)',ptsPhoto:'Fan Filter photo (once per day)',
      ptsNote:'Procedure: 1) Play the daily game and bank your goal + bonus points. 2) Submit predictions before kickoff — points are awarded automatically once the official result is synced. 3) Save your daily Fan Filter photo. Weekly score resets every week; overall score is cumulative across the tournament.',
      hostUSA:'USA',hostCAN:'Canada',hostMEX:'Mexico',hostCitiesCap:'16 Host Cities · United States · Canada · Mexico',matchesByLoc:'Matches & Locations',noMapMatches:'No synced matches available yet.'
    },
    ar:{
      brandSub:'دوري التوقعات • تحدي الأهداف اليومي',themeCatrion:'كاتريون',themeSaudi:'السعودية',
      navHome:'الرئيسية',navMatches:'المباريات',navLogout:'خروج',
      heroBadge:'كاتريون · كأس العالم 2026',
      heroTitle:'شجّع مع <span>كاتريون</span>',
      heroWelcome:'مرحبًا،',
      heroIntro:'العب تحدي الـ30 ثانية اليومي، وتوقّع مباريات كأس العالم الحقيقية، واجمع النقاط، وتصدّر لوحة كاتريون.',
      dailyTitle:'مركز التحدي',
      dailySub:'تحدي الأهداف اليومي، توقعات حقيقية، مباريات مباشرة، أسئلة إضافية، ونقاط لوحة الصدارة.',
      miniDailyGame:'اللعبة اليومية',miniMatches:'مباريات',miniPrize:'إجمالي الجائزة',
      statMyPoints:'نقاطي',statMyPointsNote:'كل نقاط اللعبة',statRank:'ترتيبي',statRankNote:'لوحة اللعبة اليومية',
      statBest:'أفضل نتيجة',statBestNote:'أعلى نتيجة يومية',statMatches:'مباريات كأس العالم',live:'مباشر',finished:'منتهية',
      statPrize:'إجمالي الجائزة',matchNews:'أخبار المباريات',liveMapTitle:'خريطة كأس العالم المباشرة',
      legendLive:'مباشر الآن',legendUpcoming:'قادمة',legendFinished:'منتهية',legendLive2:'مباشر',
      progressTitle:'تقدّم البطولة',stageKnockout:'دور خروج المغلوب',completed:'مكتملة',
      stageQF:'ربع النهائي',matchesFromApi:'مباريات',stageSF:'نصف النهائي',stageFinal:'النهائي',matchFromApi:'مباراة',
      viewFullMatches:'عرض كل المباريات',bracketTitle:'مخطط أدوار خروج المغلوب',
      bracketSub:'المسار المتوقع للبطولة (دور 32 ← دور 16 ← ربع ← نصف ← النهائي). المعاينة مطوية لصفحة أنظف.',
      more:'المزيد',showFullBracket:'عرض المخطط كاملًا',
      kickerLive:'مباشر الآن',kickerNext:'المباراة القادمة',kickerMatches:'مباريات كأس العالم',
      viewMatches:'عرض المباريات',submitPrediction:'أرسل توقعك',worldCupFeed:'تغذية كأس العالم',matchesSynced:'مباراة متزامنة',
      dailyGoalRush:'تحدي الأهداف اليومي',onePlayPerDay:'محاولة واحدة يوميًا',
      gameTime:'الوقت',gameGoals:'أهداف',gamePoints:'نقاط',
      tapToShoot:'انقر الكرة للتسديد!',tapHintSub:'استخدم خط التصويب المتحرك وتفادَ الحارس',
      startGame:'ابدأ اللعبة اليومية',shoot:'سدّد',gameTip:'بعد البدء تتحوّل الكرة إلى زر التسديد.',
      topLeaderboard:'لوحة الصدارة',myProfile:'ملفي',pfName:'الاسم',pfMobile:'الجوال',pfType:'نوع المستخدم',pfDays:'أيام اللعب',pfParticipants:'المشاركون',
      footerNote:'تحدي كاتريون لكأس العالم 2026',
      agentName:'وكيل جماهير 2026',agentStatus:'متصل • مدعوم بالذكاء',
      agentFan:'مساعد الجماهير',agentPredict:'متوقّع المباراة',agentTactical:'محلل تكتيكي',agentSummary:'ملخص المباراة',agentCommand:'مركز القيادة',
      agentPlaceholder:'اسأل عن مباريات اليوم…',
      agentGreeting:'مرحبًا! أنا وكيل جماهير كأس العالم 2026. اختر مهارة من الأعلى ثم اسألني عن المباريات أو التوقعات أو التكتيك أو أحداث اليوم.',
      agentChips:['ماذا أشاهد اليوم؟','توقّع المباراة القادمة','أعطني رؤية تكتيكية'],
      agentTyping:'أفكّر…',agentErr:'عذرًا، تعذّر على الوكيل الرد. حاول مرة أخرى.',
      agentInstruction:'أجب بالعربية بأسلوب واضح ومناسب للجماهير. إذا كانت البيانات الحية غير متوفرة فاذكر ذلك.',
      navFanFilter:'فلتر المشجع',navProfile:'ملفي',close:'إغلاق',
      next24Title:'المباريات القادمة · خلال 24 ساعة',liveNow:'مباشر',closed:'مغلق',predictionsClosed:'التوقعات مغلقة',
      no24:'لا توجد مباريات تنطلق خلال الـ24 ساعة القادمة. اطّلع على الجدول الكامل.',
      statOverall:'النقاط الإجمالية',statOverallNote:'النقاط الكلية',statWeekly:'نقاط الأسبوع',statWeeklyNote:'هذا الأسبوع',
      statParticipants:'المشاركون',onlineNow:'متصل الآن',onlineNowTitle:'المتصلون الآن',online:'متصل',
      noOnline:'لا يوجد مشاركون نشطون حاليًا. كن أول من يلعب!',
      socialTitle:'جدار المشجعين',socialSub:'شارك لحظتك • علّق • أعجبني',socialPlaceholder:'شارك رأيك عن كأس العالم…',
      attachPhoto:'إضافة صورة',post:'نشر',socialLoading:'جارٍ تحميل جدار المشجعين…',socialEmpty:'كن أول من ينشر على جدار المشجعين!',
      likeWord:'إعجاب',commentWord:'تعليق',sendWord:'إرسال',commentPh:'اكتب تعليقًا…',justNow:'الآن',
      fanFilterTitle:'استوديو فلتر المشجع',openFull:'فتح الصفحة كاملة',
      soonBadge:'قريبًا',soonTitle:'الصندوق الغامض',soonSub:'مكافأة مفاجئة من كأس العالم في الطريق. واصل اللعب والتوقع — سيُفتح هذا الصندوق لاحقًا خلال البطولة.',soonCta:'يُفتح قريبًا',
      ffChooseCountry:'اختر الدولة',ffChooseFrame:'اختر الإطار',ffStartCam:'تشغيل الكاميرا',ffUpload:'رفع صورة',ffCapture:'التقاط',ffRetake:'إعادة',ffSave:'حفظ وتنزيل',ffReady:'شغّل الكاميرا أو ارفع صورة.',ffNeedData:'أضف دولًا وإطارات نشطة لتفعيل الاستوديو.',
      openWall:'فتح جدار المشجعين',closeWall:'تصغير',ffCardSub:'أنشئ صورتك كمشجع — اختر دولتك، اختر إطارًا، التقط صورة وحمّلها.',ffOpenStudio:'فتح الاستوديو',newPost:'جديد',
      navHowTo:'طريقة الاستخدام',navPoints:'النقاط',ffSearchCountry:'ابحث عن دولة…',
      howToTitle:'ابدأ في 5 خطوات',
      howStep1t:'العب تحدي الأهداف اليومي',howStep1d:'انقر الكرة (أو اضغط مسافة) للتسديد. صوّب باستخدام خط الهدف المتحرك، تجاوز الحارس، وسجّل أكبر عدد من الأهداف خلال 30 ثانية — مرة واحدة يوميًا.',
      howStep2t:'توقّع المباريات الحقيقية',howStep2d:'افتح المباريات أو بطاقات «المباريات القادمة · 24 ساعة»، أدخل توقع النتيجة، وأرسله قبل انطلاق المباراة. تُقفل التوقعات بمجرد بدء المباراة.',
      howStep3t:'أنشئ صورة فلتر المشجع',howStep3d:'افتح استوديو فلتر المشجع، اختر دولتك وإطارًا، التقط صورة أو ارفع واحدة، ثم احفظها ونزّلها.',
      howStep4t:'انضم إلى جدار المشجعين',howStep4d:'انشر لحظتك، وتفاعل وعلّق على منشورات الآخرين. تظهر المنشورات الجديدة في شريط الإشعارات بالأعلى.',
      howStep5t:'تصدّر لوحة الصدارة',howStep5d:'اجمع النقاط من الألعاب والتوقعات وصورتك اليومية لترتقي في التصنيف الأسبوعي والإجمالي.',
      pointsTitle:'كيف تجمع النقاط',ptsAction:'الإجراء',ptsReward:'المكافأة',
      ptsGoal:'اللعبة اليومية — تسجيل هدف (حسب المنطقة)',ptsGolden:'هدف الكرة الذهبية (مكافأة)',ptsCombo:'سلسلة متتالية (كل 3 / 5 أهداف)',ptsMystery:'لفة المكافأة الغذائية اليومية',
      ptsPredWin:'توقّع الفائز بالمباراة',ptsPredScore:'توقّع النتيجة الصحيحة',ptsChampion:'توقّع البطل (النهائي فقط)',ptsPhoto:'صورة فلتر المشجع (مرة يوميًا)',
      ptsNote:'الطريقة: 1) العب اللعبة اليومية واجمع نقاط الأهداف والمكافآت. 2) أرسل التوقعات قبل انطلاق المباراة — تُمنح النقاط تلقائيًا بعد مزامنة النتيجة الرسمية. 3) احفظ صورة فلتر المشجع اليومية. تُصفّر نقاط الأسبوع أسبوعيًا، أما النقاط الإجمالية فتتراكم طوال البطولة.',
      hostUSA:'أمريكا',hostCAN:'كندا',hostMEX:'المكسيك',hostCitiesCap:'16 مدينة مضيفة · الولايات المتحدة · كندا · المكسيك',matchesByLoc:'المباريات والمواقع',noMapMatches:'لا توجد مباريات متزامنة بعد.'
    }
  };
  var lang = (function(){ try{ return localStorage.getItem('wc_lang')||'en'; }catch(e){ return 'en'; } })();
  function tr(k){ return (T[lang]&&T[lang][k]!=null)?T[lang][k]:(T.en[k]!=null?T.en[k]:k); }
  window.wcTr = tr; /* expose current-language translator for the v4 feature script */

  function applyLang(l){
    lang = (l==='ar')?'ar':'en';
    root.lang = lang; root.dir = (lang==='ar')?'rtl':'ltr';
    var known=function(k){ return T.en[k]!=null; };
    d.querySelectorAll('[data-i18n]').forEach(function(el){ var k=el.getAttribute('data-i18n'); if(known(k)) el.textContent=tr(k); });
    d.querySelectorAll('[data-i18n-html]').forEach(function(el){ var k=el.getAttribute('data-i18n-html'); if(known(k)) el.innerHTML=tr(k); });
    d.querySelectorAll('[data-i18n-ph]').forEach(function(el){ var k=el.getAttribute('data-i18n-ph'); if(known(k)) el.setAttribute('placeholder',tr(k)); });
    d.querySelectorAll('.lang-btn').forEach(function(b){ b.classList.toggle('active', b.getAttribute('data-lang-set')===lang); });
    try{ localStorage.setItem('wc_lang', lang); }catch(e){}
    if (typeof renderAgentGreeting==='function') renderAgentGreeting();
  }
  d.querySelectorAll('.lang-btn').forEach(function(b){ b.addEventListener('click', function(){ applyLang(b.getAttribute('data-lang-set')); }); });

  /* ===== AI FAN AGENT ===== */
  var endpointMap={
    fan:'/AI-Gateway/api/wc_ai_fan_assistant.php',
    predict:'/AI-Gateway/api/wc_ai_predict.php',
    tactical:'/AI-Gateway/api/wc_ai_tactical.php',
    summary:'/AI-Gateway/api/wc_ai_match_summary.php',
    command:'/AI-Gateway/api/wc_ai_command_center.php'
  };
  var fab=d.getElementById('wcAgentFab'), panel=d.getElementById('wcAgentPanel'),
      bodyEl=d.getElementById('wcAgentBody'), chipsEl=d.getElementById('wcAgentChips'),
      form=d.getElementById('wcAgentForm'), input=d.getElementById('wcAgentText'),
      modeSel=d.getElementById('wcAgentMode');

  function addMsg(text,who){ var m=d.createElement('div'); m.className='wc-msg '+who; m.textContent=text; bodyEl.appendChild(m); bodyEl.scrollTop=bodyEl.scrollHeight; return m; }
  window.renderAgentGreeting=function(){
    if(!bodyEl) return;
    bodyEl.innerHTML=''; addMsg(tr('agentGreeting'),'bot');
    chipsEl.innerHTML='';
    (tr('agentChips')||[]).forEach(function(c){
      var b=d.createElement('button'); b.type='button'; b.className='wc-chip'; b.textContent=c;
      b.addEventListener('click', function(){ input.value=c; send(); }); chipsEl.appendChild(b);
    });
  };
  function setAgent(open){
    panel.classList.toggle('open',open);
    panel.setAttribute('aria-hidden',String(!open));
    fab.setAttribute('aria-expanded',String(open));
    if(open && bodyEl.children.length===0) renderAgentGreeting();
    if(open) setTimeout(function(){ input.focus(); },320);
  }
  function send(){
    var q=(input.value||'').trim(); if(!q) return; input.value='';
    addMsg(q,'user');
    var mode=modeSel.value, endpoint=endpointMap[mode]||endpointMap.fan;
    var typing=d.createElement('div'); typing.className='wc-typing'; typing.innerHTML='<i></i><i></i><i></i>';
    bodyEl.appendChild(typing); bodyEl.scrollTop=bodyEl.scrollHeight;
    var today=new Date().toISOString().slice(0,10);
    var payload={ module:'WORLDCUP', lang:lang, date:today,
        text: tr('agentInstruction')+"\n\nUser request:\n"+q };
    fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify(payload)})
      .then(function(r){ return r.text(); })
      .then(function(txt){ typing.remove(); var data; try{ data=JSON.parse(txt); }catch(e){ addMsg(txt||tr('agentErr'),'bot'); return; }
        if(data && data.ok===false){ addMsg(data.error||tr('agentErr'),'bot'); return; }
        addMsg((data && (data.result||data.output))||txt||tr('agentErr'),'bot');
      })
      .catch(function(){ typing.remove(); addMsg(tr('agentErr'),'bot'); });
  }
  if(fab){ fab.addEventListener('click', function(){ setAgent(!panel.classList.contains('open')); }); }
  if(d.getElementById('wcAgentClose')){ d.getElementById('wcAgentClose').addEventListener('click', function(){ setAgent(false); }); }
  if(form){ form.addEventListener('submit', function(e){ e.preventDefault(); send(); }); }
  d.addEventListener('keydown', function(e){ if(e.key==='Escape') setAgent(false); });

  /* ===== init ===== */
  applyTheme(theme);
  applyLang(lang);
})();
</script>

<!-- ===================== v4 FEATURES (banner, next24, online, social, fan-filter, profile, audit) ===================== -->
<style>
/* (1) top background banner layer (Key Visual) — full-bleed, edge to edge */
.hero{overflow:hidden}
.hero-banner-layer{position:absolute;top:0;left:0;right:0;bottom:0;width:100%;z-index:0;
    background:url('<?= htmlspecialchars($bannerPath, ENT_QUOTES, 'UTF-8') ?>') center center/cover no-repeat;opacity:.92;pointer-events:none}
/* light gradient overlay so hero text stays readable over the KV */
.hero-banner-layer::after{content:"";position:absolute;inset:0;background:linear-gradient(135deg,rgba(4,20,43,.62),rgba(8,37,77,.38) 55%,rgba(14,99,230,.30))}
/* (2) hide Challenge Hub + single-column hero */
.daily-card{display:none !important}
.hero-content.hero-single{grid-template-columns:1fr !important;max-width:860px !important}
.top-actions button.top-link{font-family:inherit}

/* (3)+(4) next-24h matches — normal flow, no overlap */
.next24-wrap{width:min(1680px,calc(100vw - 80px));margin:22px auto;position:relative;z-index:1}
.next24-head{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:14px;flex-wrap:wrap}
.next24-title{display:flex;align-items:center;gap:9px;color:#fff;font-size:20px;font-weight:900;margin:0;letter-spacing:-.3px}
.next24-dot{width:9px;height:9px;border-radius:50%;background:#22C55E;box-shadow:0 0 18px rgba(34,197,94,.9)}
.next24-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px}
.next24-card{position:relative;color:#fff;border:1px solid rgba(168,231,255,.2);border-radius:22px;padding:18px;
    background:radial-gradient(circle at 100% 0%,rgba(14,99,230,.24),transparent 40%),linear-gradient(135deg,#061A36,#08254D 60%,#0A3A76);
    box-shadow:0 22px 50px rgba(0,0,0,.26);transition:transform .25s ease,border-color .25s}
.next24-card:hover{transform:translateY(-4px);border-color:rgba(168,231,255,.4)}
.next24-meta{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.lock-badge{display:inline-flex;align-items:center;gap:5px;font-size:10px;font-weight:900;text-transform:uppercase;color:#FFE19A;background:rgba(245,200,91,.14);border:1px solid rgba(245,200,91,.35);padding:6px 9px;border-radius:999px}
.next24-teams{display:grid;grid-template-columns:1fr auto 1fr;gap:10px;align-items:center}
.n24-team{text-align:center}.n24-team .team-logo{width:52px;height:52px;margin:0 auto 8px;border-radius:16px}
.n24-team .team-logo img{width:38px;height:38px}.n24-team strong{font-size:13px;line-height:1.3}
.n24-vs{font-size:13px;font-weight:900;color:rgba(255,255,255,.6)}
.next24-venue{margin-top:12px;color:rgba(255,255,255,.6);font-size:12px;font-weight:700;text-align:center}
.next24-actions{margin-top:14px;display:flex;justify-content:center}
.next24-empty{color:rgba(255,255,255,.66);font-weight:800;font-size:14px;padding:18px;border-radius:18px;background:rgba(255,255,255,.06);border:1px solid rgba(168,231,255,.14);text-align:center}

/* (11) online users list */
.online-list{display:flex;flex-direction:column;gap:4px;max-height:360px;overflow:auto}
.online-row{display:flex;align-items:center;gap:11px;padding:10px 0;border-bottom:1px solid rgba(168,231,255,.1)}
.online-row:last-child{border-bottom:0}
.online-ava{position:relative;width:36px;height:36px;border-radius:50%;flex:none;display:grid;place-items:center;font-weight:900;color:#06202e;background:linear-gradient(135deg,#F5C85B,#FFE19A)}
.online-dot{position:absolute;bottom:-1px;inset-inline-end:-1px;width:11px;height:11px;border-radius:50%;background:#22C55E;border:2px solid #061A36;box-shadow:0 0 8px #22C55E}
.online-name{font-size:13px;font-weight:900;color:#fff}.online-loc{font-size:11px;color:rgba(255,255,255,.6);margin-top:2px}

/* (10) social wall */
.social-composer{background:rgba(255,255,255,.05);border:1px solid rgba(168,231,255,.16);border-radius:18px;padding:14px;margin-bottom:16px}
.social-input-row{display:flex;gap:12px;align-items:flex-start}
.social-ava{width:40px;height:40px;border-radius:50%;flex:none;display:grid;place-items:center;font-weight:900;color:#06202e;background:linear-gradient(135deg,#55B7FF,#A8E7FF)}
.social-composer textarea{flex:1;min-height:48px;resize:vertical;border-radius:12px;border:1px solid rgba(168,231,255,.18);background:rgba(255,255,255,.05);color:#fff;font:inherit;font-size:14px;padding:11px 13px}
.social-actions{display:flex;align-items:center;gap:12px;margin-top:12px;flex-wrap:wrap}
.social-attach{display:inline-flex;align-items:center;gap:7px;cursor:pointer;color:#A8E7FF;font-weight:800;font-size:13px}
.social-attach svg{width:20px;height:20px}
.social-attach-name{color:rgba(255,255,255,.6);font-size:12px;font-weight:700}
.social-actions .match-link.primary{margin-inline-start:auto}
.social-feed{display:flex;flex-direction:column;gap:14px}
.social-loading,.social-empty{color:rgba(255,255,255,.6);font-weight:800;text-align:center;padding:16px}
.s-post{border:1px solid rgba(168,231,255,.14);border-radius:18px;padding:14px;background:rgba(255,255,255,.04)}
.s-post-head{display:flex;align-items:center;gap:10px;margin-bottom:8px}
.s-post-ava{width:34px;height:34px;border-radius:50%;flex:none;display:grid;place-items:center;font-weight:900;color:#06202e;background:linear-gradient(135deg,#F5C85B,#FFE19A)}
.s-post-name{font-size:13px;font-weight:900;color:#fff}.s-post-time{font-size:11px;color:rgba(255,255,255,.5)}
.s-post-body{color:#eaf3ff;font-size:14px;line-height:1.6;white-space:pre-wrap;word-wrap:break-word}
.s-post-img{margin-top:10px;border-radius:14px;max-width:240px;max-height:200px;object-fit:cover;border:1px solid rgba(168,231,255,.18)}
.s-post-actions{display:flex;gap:16px;margin-top:10px;padding-top:10px;border-top:1px solid rgba(168,231,255,.1)}
.s-act{display:inline-flex;align-items:center;gap:6px;background:none;border:0;color:rgba(255,255,255,.7);font:inherit;font-weight:800;font-size:13px;cursor:pointer}
.s-act:hover{color:#FFE19A}.s-act.liked{color:#FFE19A}.s-act svg{width:17px;height:17px}
.s-comments{margin-top:10px;display:flex;flex-direction:column;gap:8px}
.s-comment{display:flex;gap:8px;font-size:13px}.s-comment b{color:#fff}.s-comment span{color:rgba(255,255,255,.78)}
.s-comment-form{display:flex;gap:8px;margin-top:8px}
.s-comment-form input{flex:1;min-height:36px;border-radius:10px;border:1px solid rgba(168,231,255,.18);background:rgba(255,255,255,.05);color:#fff;font:inherit;font-size:13px;padding:0 11px}
.s-comment-form button{border:0;border-radius:10px;padding:0 13px;background:rgba(245,200,91,.9);color:#06202e;font-weight:900;cursor:pointer}

/* (1) fan filter pop-up */
.top-link.icon-link{display:inline-flex;align-items:center;gap:6px}
.top-link.icon-link svg{width:16px;height:16px}
.fanfilter-modal-card{max-width:min(980px,96vw);width:100%;padding:16px}
.fanfilter-modal-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
.fanfilter-modal-tools{display:flex;align-items:center;gap:10px}
.ff-close{width:34px;height:34px;border:0;border-radius:10px;cursor:pointer;background:rgba(255,255,255,.12);color:#fff;font-size:15px;font-weight:900;line-height:1}
.ff-close:hover{background:rgba(255,255,255,.2)}
.fanfilter-frame{border-radius:18px;overflow:hidden;border:1px solid rgba(168,231,255,.18);background:#05162F;height:74vh}
.fanfilter-frame iframe{width:100%;height:100%;border:0;display:block}
@media(max-width:768px){.next24-wrap{margin:16px auto}.fanfilter-frame{height:68vh}}

/* (3) My Profile pop-up — dark card so the white-forced text is readable */
#profileModal .modal-card{background:linear-gradient(150deg,#0B2C55,#071A35) !important;border:1px solid rgba(168,231,255,.2);color:#fff}
#profileModal .modal-card h3{color:#fff !important}
#profileModal .modal-kicker{color:var(--cyan) !important}
#profileModal .profile-line{border-bottom:1px solid rgba(168,231,255,.14) !important}
#profileModal .profile-line span:first-child{color:rgba(255,255,255,.72) !important}
#profileModal .profile-line span:last-child{color:#fff !important}

/* (8) Fan Filter Studio pop-up — base .modal-card is white; force a dark card so studio text is readable */
#fanFilterModal .modal-card{background:linear-gradient(150deg,#0B2C55,#071A35) !important;border:1px solid rgba(168,231,255,.2) !important;color:#fff !important}
#fanFilterModal .modal-card h3,#fanFilterModal .ff-label,#fanFilterModal .ff-frame span,#fanFilterModal .ff-chip{color:#fff !important}
#fanFilterModal .modal-kicker{color:var(--cyan) !important}
#fanFilterModal .ff-camera .ff-empty{color:rgba(255,255,255,.8) !important}

/* (5)+(6) Knockout bracket — true bracket pyramid (deeper rounds centre between feeders) */
.bracket-grid{display:grid !important;grid-auto-flow:column !important;grid-auto-columns:minmax(232px,1fr) !important;
    grid-template-columns:none !important;gap:26px !important;align-items:stretch !important;min-width:max-content !important}
.bracket-col{display:flex !important;flex-direction:column;gap:0 !important;height:100%}
.bracket-stage-title{flex:none;position:sticky;top:0;z-index:2;margin:0 0 10px !important;padding:9px 0 !important;border-radius:11px;
    background:linear-gradient(135deg,rgba(14,99,230,.32),rgba(85,183,255,.16));border:1px solid rgba(168,231,255,.22);text-align:center !important}
/* the body flexes and spaces the matches evenly -> classic bracket shape */
.bracket-col-body{flex:1 1 auto;display:flex;flex-direction:column;justify-content:space-around;gap:14px;min-height:0;padding:4px 0}
.bracket-match{position:relative;margin-bottom:0 !important;min-height:100px !important;display:flex;flex-direction:column;justify-content:center}
/* connector elbows joining each match to the next round */
.bracket-match:after{content:"" !important;position:absolute;right:-26px !important;left:auto !important;width:26px !important;height:2px !important;background:rgba(168,231,255,.30) !important;top:50% !important}
.bracket-col:last-child .bracket-col-body .bracket-match:after{display:none !important}
.bracket-col:not(:first-child) .bracket-match:before{content:"";position:absolute;left:-26px;top:50%;width:26px;height:2px;background:rgba(168,231,255,.30)}
html[dir="rtl"] .bracket-match:after{right:auto !important;left:-26px !important}
html[dir="rtl"] .bracket-col:not(:first-child) .bracket-match:before{left:auto;right:-26px}

/* (6) keep the footer visible when expanded so it can act as the Minimize control */
.knockout-card.expanded .bracket-preview-footer{display:flex !important}

/* (4) Live World Cup Map — animated, more attractive */
.world-map-svg .wm-land path{animation:wmLandGlow 6s ease-in-out infinite}
@keyframes wmLandGlow{0%,100%{fill:rgba(85,183,255,.14)}50%{fill:rgba(85,183,255,.22)}}
.wm-ping{fill:var(--gold,#F5C85B)}
.wm-ping-ring{fill:none;stroke:var(--gold,#F5C85B);stroke-width:2;transform-origin:center;transform-box:fill-box;animation:wmPing 2.4s ease-out infinite}
.wm-ping.live{fill:#22C55E}.wm-ping-ring.live{stroke:#22C55E}
.wm-arc{fill:none;stroke:rgba(168,231,255,.55);stroke-width:2;stroke-dasharray:6 9;stroke-linecap:round;animation:wmDash 1.6s linear infinite}
@keyframes wmPing{0%{transform:scale(.4);opacity:.9}100%{transform:scale(2.6);opacity:0}}
@keyframes wmDash{to{stroke-dashoffset:-30}}
.wm-host-tip{fill:#fff;font:900 13px Inter,sans-serif;paint-order:stroke;stroke:rgba(7,26,53,.65);stroke-width:3}
.map-pin-card{transition:transform .25s ease,box-shadow .25s ease}
.map-pin-card:hover{transform:translateY(-3px)}

/* (1) coming-soon / mystery teaser card */
.soon-card{position:relative;overflow:hidden;text-align:center;
    background:radial-gradient(circle at 80% 0%,rgba(245,200,91,.18),transparent 42%),linear-gradient(135deg,#0B2C55,#071A35) !important;
    border:1px solid rgba(245,200,91,.25) !important}
.soon-card .soon-lock{font-size:42px;filter:drop-shadow(0 8px 18px rgba(0,0,0,.4))}
.soon-badge{display:inline-flex;align-items:center;gap:7px;margin-bottom:12px;padding:7px 13px;border-radius:999px;font-size:11px;font-weight:900;letter-spacing:.6px;text-transform:uppercase;color:#FFE19A;background:rgba(245,200,91,.14);border:1px solid rgba(245,200,91,.35)}
.soon-title{font-size:23px;font-weight:950;color:#fff;margin:10px 0 6px;letter-spacing:-.4px}
.soon-sub{color:rgba(255,255,255,.66);font-weight:700;font-size:14px;max-width:520px;margin:0 auto 14px;line-height:1.6}
.soon-shimmer{position:absolute;inset:0;background:linear-gradient(115deg,transparent 30%,rgba(255,255,255,.08) 50%,transparent 70%);background-size:280% 100%;animation:soonShine 3.2s linear infinite;pointer-events:none}
@keyframes soonShine{to{background-position:-280% 0}}
.soon-cta{display:inline-flex;align-items:center;gap:8px;padding:11px 18px;border-radius:14px;border:1px solid rgba(168,231,255,.25);background:rgba(255,255,255,.06);color:#fff;font-weight:900;font-size:13px;cursor:not-allowed;opacity:.85}

/* (8) native Fan Filter studio inside the pop-up */
.ff-studio{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px}
.ff-pane{border:1px solid rgba(168,231,255,.16);background:rgba(255,255,255,.04);border-radius:18px;padding:14px}
.ff-label{font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.5px;color:rgba(255,255,255,.6);margin:2px 0 10px}
.ff-camera{position:relative;width:100%;aspect-ratio:4/5;border-radius:16px;overflow:hidden;background:#05162F;box-shadow:inset 0 0 0 1px rgba(168,231,255,.16)}
.ff-camera video,.ff-camera canvas,.ff-camera img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;display:none}
.ff-camera .ff-empty{position:absolute;inset:0;display:grid;place-items:center;text-align:center;color:rgba(255,255,255,.75);padding:18px;font-weight:800}
.ff-controls{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:12px}
.ff-btn{border:0;border-radius:12px;min-height:44px;padding:10px 12px;font:inherit;font-weight:900;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px}
.ff-btn[disabled]{opacity:.45;cursor:not-allowed}
.ff-btn.primary{background:linear-gradient(135deg,#0E63E6,#0847B8);color:#fff}
.ff-btn.gold{background:linear-gradient(135deg,#F5C85B,#E7A90C);color:#2A2105}
.ff-btn.soft{background:rgba(255,255,255,.1);color:#fff;border:1px solid rgba(168,231,255,.18)}
.ff-btn.green{background:linear-gradient(135deg,#15B97A,#0B8E5C);color:#fff}
.ff-btn.span2{grid-column:1 / -1}
.ff-msg{display:none;margin-top:10px;border-radius:12px;padding:10px 12px;font-weight:800;font-size:13px}
.ff-msg.ok{display:block;background:rgba(17,163,106,.16);color:#7EF4AE;border:1px solid rgba(17,163,106,.3)}
.ff-msg.err{display:block;background:rgba(233,71,71,.14);color:#FFB4B4;border:1px solid rgba(233,71,71,.3)}
.ff-countries{display:flex;flex-wrap:wrap;gap:8px;max-height:120px;overflow:auto;margin-bottom:6px}
.ff-chip{display:inline-flex;align-items:center;gap:7px;border:1px solid rgba(168,231,255,.18);background:rgba(255,255,255,.05);color:#fff;border-radius:999px;padding:6px 11px;font-weight:800;font-size:12px;cursor:pointer}
.ff-chip img{width:20px;height:20px;border-radius:5px;object-fit:cover}
.ff-chip.active{border-color:#F5C85B;background:rgba(245,200,91,.16)}
.ff-frames{display:grid;grid-template-columns:repeat(3,1fr);gap:9px}
.ff-frame{border:2px solid transparent;background:rgba(255,255,255,.05);border-radius:14px;padding:8px;cursor:pointer}
.ff-frame img{width:100%;height:64px;object-fit:cover;border-radius:10px;background:#0a2347}
.ff-frame span{display:block;margin-top:6px;font-size:11px;font-weight:900;color:#fff;text-align:center;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ff-frame.active{border-color:#0E63E6;background:rgba(14,99,230,.16)}
.ff-empty-note{padding:14px;border-radius:14px;background:rgba(245,200,91,.12);border:1px solid rgba(245,200,91,.3);color:#FFE19A;font-weight:800;font-size:13px;line-height:1.6}
.fanfilter-modal-card.ff-native{max-width:min(960px,96vw)}
@media(max-width:780px){.ff-studio{grid-template-columns:1fr}}

/* ===== v5 fixes ===== */
/* (1) next-24h cards: equal height, actions pinned to bottom; sit inside main cleanly */
.next24-wrap{width:auto !important;margin:0 0 18px !important}
.next24-card{display:flex;flex-direction:column}
.next24-teams{flex:1 0 auto}
.n24-team strong{display:block;min-height:2.2em;display:flex;align-items:center;justify-content:center}
.next24-actions{margin-top:auto;padding-top:12px}

/* (4) Fan Wall + any standalone .card must be dark so white text is readable (no white-on-white) */
.social-card,.fan-filter-card{
    color:#fff !important;
    background:
        radial-gradient(circle at 100% 0%, rgba(14,99,230,.22), transparent 34%),
        linear-gradient(135deg,#061A36 0%,#08254D 58%,#0A3A76 100%) !important;
    border:1px solid rgba(168,231,255,.18) !important;
    box-shadow:0 22px 55px rgba(0,0,0,.22), inset 0 1px 0 rgba(255,255,255,.06) !important;
}
.social-card .card-title,.fan-filter-card .card-title{color:#fff !important}
.social-card .card-title small{color:rgba(255,255,255,.62) !important}

/* (8) Fan Wall minimized by default */
.social-head{display:flex !important;align-items:center;justify-content:space-between;gap:12px}
.social-toggle{display:inline-flex;align-items:center;gap:8px;border:1px solid rgba(168,231,255,.25);background:rgba(255,255,255,.08);color:#fff;font:inherit;font-weight:900;font-size:12px;padding:9px 14px;border-radius:999px;cursor:pointer}
.social-toggle:hover{background:rgba(255,255,255,.16)}
.social-count{min-width:20px;height:20px;padding:0 6px;border-radius:999px;display:none;align-items:center;justify-content:center;background:#E94747;color:#fff;font-size:11px;font-weight:900}
.social-count.show{display:inline-flex}
.social-card.collapsed .social-body{display:none}
.social-card:not(.collapsed) .social-toggle-label:after{content:" ▲"}
.social-card.collapsed .social-toggle-label:after{content:" ▼"}

/* (7) Fan Wall notification bar */
.fan-notify[hidden]{display:none !important}
.fan-notify{display:flex;align-items:center;gap:12px;margin:0 0 18px;padding:12px 16px;border-radius:16px;
    background:linear-gradient(135deg,#0B3D78,#061A36);border:1px solid rgba(245,200,91,.3);
    box-shadow:0 18px 44px rgba(0,0,0,.26)}
.fan-notify-bell{font-size:20px;animation:wmLandGlow 2s ease-in-out infinite;filter:drop-shadow(0 0 8px rgba(245,200,91,.6))}
.fan-notify-track{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px;overflow:hidden}
.fan-notify-msg{color:#fff;font-size:13px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fan-notify-msg b{color:#FFE19A}
.fan-notify-msg small{color:rgba(255,255,255,.6);font-weight:700;margin-inline-start:6px}
.fan-notify-open{border:0;border-radius:12px;padding:9px 14px;background:linear-gradient(135deg,#F5C85B,#FFE19A);color:#06202e;font-weight:900;font-size:12px;cursor:pointer;flex:none}
.fan-notify-x{width:30px;height:30px;flex:none;border:0;border-radius:10px;background:rgba(255,255,255,.12);color:#fff;font-weight:900;cursor:pointer}
.fan-notify-x:hover{background:rgba(255,255,255,.22)}

/* (2) Fan Filter card */
.ff-card-inner{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
.ff-card-icon{width:54px;height:54px;flex:none;display:grid;place-items:center;border-radius:16px;font-size:28px;background:linear-gradient(135deg,#F5C85B,#FFE19A)}
.ff-card-copy{flex:1;min-width:200px}
.ff-card-sub{margin:0;color:rgba(255,255,255,.7);font-size:13px;font-weight:700;line-height:1.6}
.fan-filter-card .match-link.primary{flex:none}

/* (5) Live World Cup Map — richer + mobile-friendly */
.world-map-svg{opacity:.72 !important}
.map-pin-card{background:rgba(6,26,54,.92) !important;backdrop-filter:blur(10px)}
.map-pin-status.Live{animation:wmLandGlow 1.6s ease-in-out infinite}
@media(max-width:768px){
    .world-map-svg{position:relative !important;top:auto !important;height:150px !important;opacity:.9 !important;margin-bottom:12px;border-radius:16px;background:linear-gradient(135deg,#05162F,#0a2f5e)}
    .live-map-bg,.world-lines{display:none !important}
    .map-stage{grid-template-columns:1fr !important;gap:12px !important}
    .map-pins{display:grid !important;grid-template-columns:1fr 1fr !important;gap:10px !important}
    .map-pin-card{width:100% !important;min-height:auto !important}
    .tournament-progress{grid-column:1 / -1}
    .next24-grid{grid-template-columns:1fr 1fr}
    .ff-card-inner{flex-direction:column;text-align:center}
    .fan-notify{flex-wrap:wrap}
    .fan-notify-track{order:3;flex-basis:100%}
}
@media(max-width:480px){.next24-grid{grid-template-columns:1fr}.map-pins{grid-template-columns:1fr !important}}

/* ===== bracket info marks + popup, tighter formatting ===== */
.bracket-match{padding:12px 12px 10px !important}
.bracket-info{position:absolute;top:8px;inset-inline-end:8px;z-index:3;width:20px;height:20px;border-radius:50%;
    border:1px solid rgba(168,231,255,.45);background:rgba(255,255,255,.12);color:#fff;font:900 12px Georgia,serif;font-style:italic;
    line-height:1;cursor:pointer;display:grid;place-items:center;transition:.18s ease}
.bracket-info:hover{background:#F5C85B;color:#06202e;border-color:#F5C85B;transform:scale(1.1)}
.bracket-row{padding-inline-end:6px !important}
.bracket-status{margin-top:8px !important;padding-top:8px !important}

/* bracket details pop-up card (base .modal-card is white -> force dark + readable) */
#bracketInfoModal .modal-card{background:linear-gradient(150deg,#0B2C55,#071A35) !important;border:1px solid rgba(168,231,255,.2) !important;color:#fff;max-width:440px}
#bracketInfoModal .modal-kicker{color:var(--cyan) !important;text-align:center}
.bi-teams{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:12px;margin:6px 0 18px}
.bi-team{text-align:center;min-width:0}
.bi-logo{width:58px;height:58px;margin:0 auto 8px;border-radius:16px;background:rgba(255,255,255,.92);display:grid;place-items:center;overflow:hidden;color:#0B2C55;font-weight:900;font-size:20px}
.bi-logo img{width:42px;height:42px;object-fit:contain}
.bi-name{color:#fff;font-weight:900;font-size:14px;line-height:1.3}
.bi-score{display:flex;align-items:center;gap:6px;font-size:30px;font-weight:900;color:#FFE19A}
.bi-score i{color:rgba(255,255,255,.5);font-style:normal}
.bi-meta{border-top:1px solid rgba(168,231,255,.16);padding-top:6px;margin-bottom:18px}
.bi-row{display:flex;justify-content:space-between;gap:12px;padding:11px 0;border-bottom:1px solid rgba(168,231,255,.12);font-size:14px}
.bi-row:last-child{border-bottom:0}
.bi-row span{color:rgba(255,255,255,.66);font-weight:700}.bi-row b{color:#fff;font-weight:900;text-align:end}
#bracketInfoModal .primary-btn{width:100%}

/* ===== v6 ===== */
/* (1) stop Next Matches from overlapping the banner: drop the hero/container overlap */
.hero{padding-bottom:44px !important}
.container{margin-top:22px !important}
.next24-wrap{margin-top:0 !important}

/* (2) Fan Wall posting error surface */
.social-error{display:none;margin:0 0 14px;border-radius:12px;padding:11px 13px;font-weight:800;font-size:13px;
    background:rgba(233,71,71,.16);color:#FFB4B4;border:1px solid rgba(233,71,71,.35)}
.social-error.show{display:block}

/* (3)+(4) How-to / Points info pop-ups (base .modal-card is white -> force dark) */
#howToModal .modal-card,#pointsModal .modal-card{background:linear-gradient(150deg,#0B2C55,#071A35) !important;border:1px solid rgba(168,231,255,.2) !important;color:#fff;max-width:580px}
#howToModal .modal-kicker,#pointsModal .modal-kicker{color:var(--cyan) !important}
#howToModal h3,#pointsModal h3{color:#fff !important}
.help-list{list-style:none;margin:6px 0 18px;padding:0;display:flex;flex-direction:column;gap:12px;max-height:60vh;overflow:auto}
.help-item{display:flex;gap:12px;align-items:flex-start}
.help-num{flex:none;width:30px;height:30px;border-radius:50%;display:grid;place-items:center;font-weight:900;font-size:13px;color:#06202e;background:linear-gradient(135deg,#F5C85B,#FFE19A)}
.help-item b{display:block;color:#fff;font-size:14px;margin-bottom:2px}
.help-item span{color:rgba(255,255,255,.72);font-size:13px;line-height:1.55}
.pts-table{width:100%;border-collapse:collapse;margin:6px 0 16px}
.pts-table th,.pts-table td{text-align:start;padding:10px 8px;border-bottom:1px solid rgba(168,231,255,.14);font-size:13px}
.pts-table th{color:rgba(255,255,255,.6);font-weight:800;text-transform:uppercase;font-size:11px;letter-spacing:.4px}
.pts-table td{color:#fff;font-weight:700}
.pts-table td b{color:#FFE19A;font-weight:900}
.pts-note{color:rgba(255,255,255,.66);font-size:12px;line-height:1.6;margin:0 0 16px}
#howToModal .primary-btn,#pointsModal .primary-btn{width:100%}

/* (5) map pin city label */
.map-pin-city{margin-top:5px;color:#A8E7FF;font-size:11px;font-weight:900;text-align:center;text-transform:uppercase;letter-spacing:.3px}

/* (2) Fan Filter country search */
.ff-search{width:100%;margin-bottom:10px;min-height:38px;border-radius:10px;border:1px solid rgba(168,231,255,.22);
    background:rgba(255,255,255,.06);color:#fff;font:inherit;font-size:13px;padding:0 12px}
.ff-search::placeholder{color:rgba(255,255,255,.5)}
.ff-countries-empty{padding:8px 2px;color:rgba(255,255,255,.55);font-size:12px;font-weight:700}

/* (B3) home prediction pop-up (dark, matches the theme) */
.predict-popup{position:fixed;inset:0;z-index:300;display:none;align-items:center;justify-content:center;padding:22px;background:rgba(4,18,40,.78);backdrop-filter:blur(10px)}
.predict-popup.active{display:flex}
.predict-popup-card{width:100%;max-width:820px;height:min(86vh,760px);background:#0c1830;border:1px solid rgba(168,231,255,.2);border-radius:24px;overflow:hidden;box-shadow:0 35px 90px rgba(0,0,0,.5);position:relative}
.predict-popup-top{height:60px;display:flex;align-items:center;justify-content:space-between;padding:0 12px 0 22px;border-bottom:1px solid rgba(168,231,255,.14)}
.predict-popup-title{font-size:18px;font-weight:900;color:#fff}
.predict-popup-close{width:38px;height:38px;border:0;border-radius:12px;background:rgba(255,255,255,.12);color:#fff;font-size:22px;font-weight:900;cursor:pointer}
.predict-popup iframe{width:100%;height:calc(100% - 60px);border:0;background:#0c1830}
@media(max-width:640px){.predict-popup{padding:0;align-items:flex-end}.predict-popup-card{height:94vh;border-radius:20px 20px 0 0}}

/* (A1) host-cities live map */
.hostmap-card{padding:22px !important}
.legend-bullet.usa{background:#55B7FF}.legend-bullet.can{background:#E94747}.legend-bullet.mex{background:#22C55E}
.hostmap-stage{display:grid;grid-template-columns:1.55fr .85fr;gap:20px;align-items:stretch}
.hostmap{position:relative;border-radius:20px;overflow:hidden;display:flex;flex-direction:column;min-height:380px;
    background:radial-gradient(circle at 50% 26%,rgba(14,99,230,.20),transparent 60%),linear-gradient(160deg,#061A36,#08254D);
    border:1px solid rgba(168,231,255,.16)}
.hostmap-svg{width:100%;flex:1;display:block}
.hostmap-land{fill:url(#naFill);stroke:rgba(168,231,255,.5);stroke-width:1.4;filter:drop-shadow(0 0 14px rgba(85,183,255,.25))}
.hostmap-land2{fill:rgba(85,183,255,.16);stroke:rgba(168,231,255,.4);stroke-width:1.2}
.hostmap-lake{fill:#06203f;stroke:rgba(168,231,255,.22);stroke-width:.8}
.hostmap-grid line{stroke:rgba(168,231,255,.08);stroke-width:1}
.hc-label{fill:rgba(255,255,255,.85);font:800 11px Inter,sans-serif;paint-order:stroke;stroke:rgba(6,26,54,.72);stroke-width:2.6}
.hc-dot{fill:#fff}
.hc-ring{fill:none;stroke-width:2;transform-origin:center;transform-box:fill-box;animation:wmPing 2.6s ease-out infinite}
.hc.usa .hc-dot{fill:#55B7FF}.hc.usa .hc-ring{stroke:#55B7FF}
.hc.can .hc-dot{fill:#E94747}.hc.can .hc-ring{stroke:#E94747}
.hc.mex .hc-dot{fill:#22C55E}.hc.mex .hc-ring{stroke:#22C55E}
.hc{opacity:.95;transition:opacity .25s ease}
.hc.dim{opacity:.3}
.hc.active .hc-dot{fill:#FFE19A}.hc.active .hc-ring{stroke:#FFE19A;animation-duration:1.3s}.hc.active .hc-label{fill:#FFE19A}
.hostmap-cap{padding:10px 14px;color:rgba(255,255,255,.7);font-size:12px;font-weight:800;border-top:1px solid rgba(168,231,255,.12);text-align:center}
/* (4) Matches & Locations list fills the same height as the map card */
.hostmap-side{display:flex;flex-direction:column;min-width:0;height:100%;min-height:0}
.hostmap-side-head{flex:none;display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px;color:#fff;font-weight:900;font-size:14px;flex-wrap:wrap}
.hostmap-list{position:static !important;display:flex !important;flex-direction:column;gap:10px;flex:1 1 auto;max-height:none;overflow:auto;min-height:0}
.hostmap-list .map-pin-card{position:static !important;inset:auto !important;left:auto !important;right:auto !important;top:auto !important;bottom:auto !important;width:auto !important;cursor:pointer}
.hostmap-list .map-pin-card.hl{border-color:#FFE19A !important;box-shadow:0 0 0 1px #FFE19A,0 18px 40px rgba(0,0,0,.32) !important}
@media(max-width:900px){.hostmap-stage{grid-template-columns:1fr}.hostmap{min-height:300px}.hostmap-side{height:auto}.hostmap-list{flex:none}}

/* welcome line (moved below the banner) */
.welcome-bar{margin:0 0 18px}
.welcome-bar p{margin:0;color:rgba(255,255,255,.82);font-size:15px;font-weight:700;line-height:1.7}
.welcome-bar p b{color:#fff;font-weight:900}

/* (5) Knockout bracket match cards — cleaner, consistent cards */
.bracket-match{padding:12px 12px 11px !important;min-height:108px !important;display:flex;flex-direction:column;justify-content:center;gap:2px}
.bracket-match .bracket-row{padding:5px 0 !important}
.bracket-match .bracket-status{margin-top:8px !important;align-self:flex-start;padding:4px 9px !important;border-radius:999px !important;background:rgba(255,255,255,.07) !important;border-top:0 !important;font-size:10px !important}
.bracket-match .bracket-status.upcoming{color:#A8E7FF !important}
</style>

<script>
(function(){
  "use strict";
  var d=document, csrf=<?= json_encode($csrf) ?>;
  var tr = window.wcTr || function(k){ return k; };
  var arNow = function(){ return d.documentElement.lang==='ar'; };

  /* (1) inject banner layer behind the hero */
  var hero=d.querySelector('.hero');
  if(hero){ var bl=d.createElement('div'); bl.className='hero-banner-layer'; hero.insertBefore(bl, hero.firstChild); }

  /* (12) lightweight audit hook (UI scaffold -> POST /WC2026/api/audit_log.php) */
  function wcAudit(action, detail){
    try{
      var body=JSON.stringify({csrf:csrf, action:action, detail:detail||'', page:'home', ts:Date.now()});
      if(navigator.sendBeacon){ navigator.sendBeacon('/WC2026/api/audit_log.php', new Blob([body],{type:'application/json'})); }
      else { fetch('/WC2026/api/audit_log.php',{method:'POST',headers:{'Content-Type':'application/json'},body:body,keepalive:true}).catch(function(){}); }
    }catch(e){}
  }
  window.wcAudit = wcAudit;
  wcAudit('view_home');

  /* (5) profile pop-up */
  var pModal=d.getElementById('profileModal');
  function openProfile(){ if(pModal){ pModal.classList.add('active'); wcAudit('open_profile'); } }
  function closeProfile(){ if(pModal) pModal.classList.remove('active'); }
  var ob=d.getElementById('openProfileBtn'); if(ob) ob.addEventListener('click', openProfile);
  var cb=d.getElementById('closeProfileBtn'); if(cb) cb.addEventListener('click', closeProfile);
  if(pModal) pModal.addEventListener('click', function(e){ if(e.target===pModal) closeProfile(); });
  d.addEventListener('keydown', function(e){ if(e.key==='Escape') closeProfile(); });

  /* (3)+(4) How-to / Points pop-ups */
  function bindModal(openId, modalId, closeId){
    var m=d.getElementById(modalId), o=d.getElementById(openId), c=d.getElementById(closeId);
    function open(){ if(m){ m.classList.add('active'); wcAudit('open_'+modalId); } }
    function close(){ if(m) m.classList.remove('active'); }
    if(o) o.addEventListener('click', open);
    if(c) c.addEventListener('click', close);
    if(m) m.addEventListener('click', function(e){ if(e.target===m) close(); });
    d.addEventListener('keydown', function(e){ if(e.key==='Escape') close(); });
  }
  bindModal('howToBtn','howToModal','howToClose');
  bindModal('pointsBtn','pointsModal','pointsClose');

  /* (3) audit prediction clicks */
  d.querySelectorAll('.next24-actions a.primary').forEach(function(a){ a.addEventListener('click', function(){ wcAudit('open_prediction', a.getAttribute('href')||''); }); });

  /* (8) fan filter studio pop-up — NATIVE functional studio (no iframe) */
  var ffModal=d.getElementById('fanFilterModal');
  function closeFanFilter(){ if(ffModal){ ffModal.classList.remove('active'); if(window.wcFFStop) window.wcFFStop(); } }
  function openFanFilter(){ if(ffModal){ ffModal.classList.add('active'); wcAudit('open_fan_filter'); } }
  var offb=d.getElementById('openFanFilterBtn'); if(offb) offb.addEventListener('click', openFanFilter);
  var offc=d.getElementById('openFanFilterCard'); if(offc) offc.addEventListener('click', openFanFilter);
  var cffb=d.getElementById('closeFanFilterBtn'); if(cffb) cffb.addEventListener('click', closeFanFilter);
  if(ffModal) ffModal.addEventListener('click', function(e){ if(e.target===ffModal) closeFanFilter(); });
  d.addEventListener('keydown', function(e){ if(e.key==='Escape') closeFanFilter(); });

  /* native studio engine moved to a dedicated script below */

  /* (10) social wall — UI scaffold posting to /WC2026/api endpoints, degrades gracefully */
  var feed=d.getElementById('socialFeed'), composer=d.getElementById('socialComposer'),
      photoInput=d.getElementById('socialPhoto'), photoName=d.getElementById('socialPhotoName'),
      textArea=d.getElementById('socialText');

  /* (8) Fan Wall minimize toggle */
  var wall=d.getElementById('socialWall'), wallToggle=d.getElementById('fanWallToggle'),
      wallCount=d.getElementById('fanWallCount'), wallLabel=wallToggle?wallToggle.querySelector('.social-toggle-label'):null;
  function setWall(open){ if(!wall) return; wall.classList.toggle('collapsed', !open);
    if(wallLabel) wallLabel.textContent = open ? tr('closeWall') : tr('openWall');
    if(open && wallCount){ wallCount.classList.remove('show'); wallCount.textContent=''; } }
  if(wallToggle) wallToggle.addEventListener('click', function(){ setWall(wall.classList.contains('collapsed')); });

  /* (7) Fan Wall notification bar */
  var notifyBar=d.getElementById('fanNotifyBar'), notifyTrack=d.getElementById('fanNotifyTrack'),
      notifyOpen=d.getElementById('fanNotifyOpen'), notifyClose=d.getElementById('fanNotifyClose');
  var unseen=0;
  function notifyShow(){ if(notifyBar) notifyBar.hidden=false; }
  function pushNotify(name, body){
    if(!notifyTrack) return;
    var row=d.createElement('div'); row.className='fan-notify-msg';
    row.innerHTML='<b>'+esc(name)+'</b> '+esc((body||'').slice(0,90))+'<small>'+esc(tr('justNow'))+'</small>';
    notifyTrack.insertBefore(row, notifyTrack.firstChild);
    while(notifyTrack.children.length>2) notifyTrack.removeChild(notifyTrack.lastChild);
    notifyShow();
    if(wall && wall.classList.contains('collapsed') && wallCount){ unseen++; wallCount.textContent=unseen; wallCount.classList.add('show'); }
  }
  function seedNotify(posts){
    if(!notifyTrack || !posts || !posts.length) return;
    notifyTrack.innerHTML='';
    posts.slice(0,2).forEach(function(p){
      var row=d.createElement('div'); row.className='fan-notify-msg';
      row.innerHTML='<b>'+esc(p.name)+'</b> '+esc((p.body||'').slice(0,90))+'<small>'+esc(p.created_at||tr('justNow'))+'</small>';
      notifyTrack.appendChild(row);
    });
    notifyShow();
    if(wall && wall.classList.contains('collapsed') && wallCount){ wallCount.textContent=posts.length; wallCount.classList.add('show'); }
  }
  if(notifyOpen) notifyOpen.addEventListener('click', function(){ setWall(true); wall.scrollIntoView({behavior:'smooth',block:'start'}); });
  if(notifyClose) notifyClose.addEventListener('click', function(){ if(notifyBar) notifyBar.hidden=true; });
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[c];}); }
  function renderPosts(posts){
    if(!posts || !posts.length){ feed.innerHTML='<div class="social-empty">'+esc(tr('socialEmpty'))+'</div>'; return; }
    feed.innerHTML='';
    posts.forEach(function(p){ feed.appendChild(renderPost(p)); });
  }
  function renderPost(p){
    var el=d.createElement('div'); el.className='s-post'; el.dataset.id=p.id;
    var initial=(p.name||'?').trim().charAt(0)||'?';
    var img=p.photo?('<img class="s-post-img" src="'+esc(p.photo)+'" alt="">'):'';
    var comments=(p.comments||[]).map(function(c){ return '<div class="s-comment"><b>'+esc(c.name)+'</b><span>'+esc(c.body)+'</span></div>'; }).join('');
    el.innerHTML=
      '<div class="s-post-head"><span class="s-post-ava">'+esc(initial)+'</span><div><div class="s-post-name">'+esc(p.name)+'</div><div class="s-post-time">'+esc(p.created_at||tr('justNow'))+'</div></div></div>'+
      '<div class="s-post-body">'+esc(p.body)+'</div>'+img+
      '<div class="s-post-actions">'+
        '<button class="s-act like'+(p.liked?' liked':'')+'" type="button"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 9V5a3 3 0 0 0-6 0v4H5l-1 11h16l-1-11z"/></svg><span class="lk">'+(p.likes||0)+'</span> '+esc(tr('likeWord'))+'</button>'+
        '<button class="s-act cmt" type="button"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>'+esc(tr('commentWord'))+'</button>'+
      '</div>'+
      '<div class="s-comments">'+comments+'</div>'+
      '<form class="s-comment-form"><input type="text" placeholder="'+esc(tr('commentPh'))+'"><button type="submit">'+esc(tr('sendWord'))+'</button></form>';
    // like
    el.querySelector('.like').addEventListener('click', function(){
      var btn=this, span=btn.querySelector('.lk'); var liked=btn.classList.toggle('liked');
      span.textContent=(parseInt(span.textContent,10)||0)+(liked?1:-1);
      wcAudit('social_like', p.id);
      fetch('/WC2026/api/social_like.php',{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({csrf:csrf,post_id:p.id,liked:liked})}).catch(function(){});
    });
    // comment
    var cf=el.querySelector('.s-comment-form');
    cf.addEventListener('submit', function(e){ e.preventDefault(); var inp=cf.querySelector('input'); var v=inp.value.trim(); if(!v) return; inp.value='';
      var box=el.querySelector('.s-comments'); var c=d.createElement('div'); c.className='s-comment'; c.innerHTML='<b>'+esc('<?= htmlspecialchars($name, ENT_QUOTES, "UTF-8") ?>')+'</b><span>'+esc(v)+'</span>'; box.appendChild(c);
      wcAudit('social_comment', p.id);
      var fd=new FormData(); fd.append('csrf',csrf); fd.append('post_id',p.id); fd.append('body',v);
      fetch('/WC2026/api/social_comment.php',{method:'POST',body:fd,credentials:'same-origin'}).catch(function(){});
    });
    return el;
  }
  function loadFeed(){
    if(!feed) return;
    fetch(feed.dataset.endpoint,{credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(data){ var posts=(data && data.posts)||[]; renderPosts(posts); seedNotify((data && data.recent) || posts); })
      .catch(function(){ feed.innerHTML='<div class="social-empty">'+esc(tr('socialEmpty'))+'</div>'; });
  }
  if(photoInput){ photoInput.addEventListener('change', function(){ photoName.textContent=(photoInput.files&&photoInput.files[0])?photoInput.files[0].name:''; }); }
  var myName='<?= htmlspecialchars($name, ENT_QUOTES, "UTF-8") ?>';
  var socialErr=d.getElementById('socialError');
  function showSocialErr(msg){ if(socialErr){ socialErr.textContent=msg; socialErr.classList.add('show'); } }
  function clearSocialErr(){ if(socialErr){ socialErr.textContent=''; socialErr.classList.remove('show'); } }
  function afterPost(post){
    if(feed.querySelector('.social-empty')||feed.querySelector('.social-loading')) feed.innerHTML='';
    feed.insertBefore(renderPost(post), feed.firstChild);
    pushNotify(post.name, post.body);   // (7) surface in the notification bar
    setWall(true);                       // (8) reveal the wall so the user sees their post
    textArea.value=''; if(photoInput){ photoInput.value=''; photoName.textContent=''; }
  }
  if(composer){
    composer.addEventListener('submit', function(e){ e.preventDefault(); clearSocialErr();
      var body=(textArea.value||'').trim(); var hasPhoto=photoInput && photoInput.files && photoInput.files[0];
      if(!body && !hasPhoto) return;
      var fd=new FormData(composer);
      wcAudit('social_post', body.slice(0,60));
      fetch('/WC2026/api/social_post.php',{method:'POST',body:fd,credentials:'same-origin'})
        .then(function(r){ return r.text(); })
        .then(function(txt){
          var data; try{ data=JSON.parse(txt); }catch(e){ showSocialErr('Server did not return JSON (endpoint missing or error). Check /WC2026/api/social_post.php and run sql/wc2026_fan_wall.sql.'); return; }
          if(data && data.ok && data.post){ afterPost(data.post); }
          else { showSocialErr((data && data.message) ? ('Could not post: '+data.message) : 'Could not save your post. Make sure the Fan Wall tables exist (sql/wc2026_fan_wall.sql).'); }
        })
        .catch(function(){ showSocialErr('Could not reach the server. Your post was not saved.'); });
    });
  }
  loadFeed();
  /* (7) keep the notification bar fresh (15-min window) while the wall is minimized */
  setInterval(function(){ if(wall && wall.classList.contains('collapsed')) loadFeed(); }, 60000);
})();
</script>

<script>
/* (B3) Submit Prediction -> open predict.php in a popup (no full-page navigation) */
(function(){
  var pop=document.getElementById('homePredictPopup'), fr=document.getElementById('homePredictFrame'),
      x=document.getElementById('homePredictClose');
  if(!pop || !fr) return;
  function openPop(url){ fr.src = url + (url.indexOf('?')>=0?'&':'?') + 'popup=1'; pop.classList.add('active'); pop.setAttribute('aria-hidden','false'); document.body.style.overflow='hidden'; }
  function closePop(reload){ pop.classList.remove('active'); pop.setAttribute('aria-hidden','true'); document.body.style.overflow=''; fr.src='about:blank'; if(reload) setTimeout(function(){ location.reload(); }, 200); }
  document.querySelectorAll('[data-predict-url]').forEach(function(b){ b.addEventListener('click', function(){ openPop(b.dataset.predictUrl); if(window.wcAudit) wcAudit('open_prediction', b.dataset.predictUrl); }); });
  if(x) x.addEventListener('click', function(){ closePop(true); });
  pop.addEventListener('click', function(e){ if(e.target===pop) closePop(true); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape' && pop.classList.contains('active')) closePop(true); });
  window.addEventListener('message', function(ev){ if(ev && ev.data && ev.data.type==='WC2026_PREDICTION_SAVED') closePop(true); });
})();
</script>

<script>
/* (A1) host-cities map: link match cards to host-city dots */
(function(){
  var hcs=[].slice.call(document.querySelectorAll('.hostmap .hc'));
  var cards=[].slice.call(document.querySelectorAll('.hostmap-list .map-pin-card'));
  if(!hcs.length) return;
  function norm(s){ return (s||'').toLowerCase().trim(); }
  function match(host, city){ host=norm(host); city=norm(city); return city!=='' && (host.indexOf(city)>=0 || city.indexOf(host)>=0); }
  var matchCities=cards.map(function(c){ return norm(c.dataset.city); }).filter(Boolean);
  var any=matchCities.length>0;
  hcs.forEach(function(g){
    var host=g.dataset.city, on=matchCities.some(function(c){ return match(host,c); });
    if(any){ g.classList.toggle('active', on); g.classList.toggle('dim', !on); }
  });
  cards.forEach(function(c){
    c.addEventListener('click', function(){
      cards.forEach(function(x){ x.classList.remove('hl'); }); c.classList.add('hl');
      var city=c.dataset.city;
      hcs.forEach(function(g){ var on=match(g.dataset.city, city); g.classList.toggle('active', on); g.classList.toggle('dim', !on); });
    });
  });
})();
</script>

<style>
/* Fan Wall heading icon */
.fanwall-head{display:inline-flex;align-items:center;gap:10px}
.fanwall-icon{width:34px;height:34px;flex:none;display:grid;place-items:center;border-radius:10px;color:#06202e;background:linear-gradient(135deg,#F5C85B,#FFE19A)}
.fanwall-icon svg{width:19px;height:19px}

/* Mobile: header buttons slide in as a scrollable side drawer */
.nav-burger{display:none;width:44px;height:44px;flex:none;border:1px solid rgba(255,255,255,.22);background:rgba(255,255,255,.12);color:#fff;border-radius:12px;cursor:pointer;align-items:center;justify-content:center}
.nav-burger svg{width:22px;height:22px}
@media(max-width:768px){
  .nav{flex-wrap:wrap}
  .nav-burger{display:inline-flex;position:relative;z-index:70}
  .top-actions{position:fixed;top:0;inset-inline-end:0;height:100vh;height:100dvh;width:min(82vw,300px);z-index:60;
    display:flex !important;flex-direction:column;align-items:stretch;gap:10px;overflow-y:auto;-webkit-overflow-scrolling:touch;
    background:#0c1830;border-inline-start:1px solid rgba(168,231,255,.2);border-radius:0;padding:70px 14px 28px;
    box-shadow:-24px 0 60px rgba(0,0,0,.55);transform:translateX(105%);transition:transform .28s ease}
  .top-actions.open{transform:none}
  html[dir="rtl"] .top-actions{inset-inline-end:auto;inset-inline-start:0;border-inline-start:0;border-inline-end:1px solid rgba(168,231,255,.2);box-shadow:24px 0 60px rgba(0,0,0,.55);transform:translateX(-105%)}
  html[dir="rtl"] .top-actions.open{transform:none}
  .top-actions .theme-switch,.top-actions .lang-switch{justify-content:center}
  .top-actions .top-link,.top-actions .logout,.top-actions form{width:100%}
  .top-actions .top-link,.top-actions .logout{text-align:center;justify-content:center}
  .top-actions form button{width:100%}
  .nav-backdrop{position:fixed;inset:0;z-index:55;background:rgba(4,12,28,.55);opacity:0;visibility:hidden;transition:.25s}
  .nav-backdrop.show{opacity:1;visibility:visible}
}

/* Arabic / RTL spacing fixes */
html[dir="rtl"] .stat:before{right:auto !important;left:16px !important}
html[dir="rtl"] .news-label{border-radius:0 18px 18px 0 !important}
html[dir="rtl"] .hero:after{right:auto;left:70px}
html[dir="rtl"] .match-card-premium:after{right:auto;left:22px}
html[dir="rtl"] .social-attach svg,html[dir="rtl"] .fanwall-icon svg{transform:scaleX(-1)}
html[dir="rtl"] .next24-title,html[dir="rtl"] .map-title,html[dir="rtl"] .bracket-title{flex-direction:row-reverse}
html[dir="rtl"] .help-item{flex-direction:row-reverse;text-align:right}
html[dir="rtl"] .pts-table th,html[dir="rtl"] .pts-table td{text-align:right}
html[dir="rtl"] .wc-agent-fab{inset-inline-end:auto;inset-inline-start:24px}
html[dir="rtl"] .wc-agent-panel{inset-inline-end:auto;inset-inline-start:24px}
</style>
<script>
(function(){
  var b=document.getElementById('navBurger'), a=document.getElementById('topActions');
  if(!b||!a) return;
  var bd=document.createElement('div'); bd.className='nav-backdrop'; document.body.appendChild(bd);
  function setOpen(open){ a.classList.toggle('open', open); bd.classList.toggle('show', open); b.setAttribute('aria-expanded', open?'true':'false'); }
  b.addEventListener('click', function(e){ e.stopPropagation(); setOpen(!a.classList.contains('open')); });
  bd.addEventListener('click', function(){ setOpen(false); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') setOpen(false); });
  a.querySelectorAll('a, button').forEach(function(el){
    if(el.classList.contains('theme-btn')||el.classList.contains('lang-btn')) return;
    el.addEventListener('click', function(){ if(window.matchMedia('(max-width:768px)').matches) setOpen(false); });
  });
})();
</script>

<style>
/* ===== Native Fan Filter studio (scoped to #fanFilterModal) ===== */
#fanFilterModal .modal-card.ff-native{max-width:min(1080px,96vw) !important;max-height:92vh;overflow:auto;background:#fff !important;color:#102033 !important}
#fanFilterModal .ff-native .modal-kicker{color:#0E63E6 !important}
#fanFilterModal .ffstudio{margin-top:6px}
#fanFilterModal .empty{padding:20px;border-radius:18px;background:#FFF8E7;border:1px solid #F6DEA5;color:#6C520B;font-weight:800;line-height:1.6}
#fanFilterModal .studio-layout{display:grid;grid-template-columns:minmax(300px,440px) 1fr;gap:18px;align-items:start}
#fanFilterModal .preview-card,#fanFilterModal .options-card{min-width:0;border:1px solid #E1ECF8;background:#FBFDFF;border-radius:24px;padding:16px}
#fanFilterModal .section-label{font-size:12px;color:#71839A;font-weight:900;text-transform:uppercase;letter-spacing:.6px;margin:2px 0 10px}
#fanFilterModal .camera-wrap{width:100%;max-width:430px;margin:0 auto;border-radius:24px;background:#071A35;overflow:hidden;position:relative;box-shadow:0 18px 50px rgba(7,26,53,.25);aspect-ratio:4/5}
#fanFilterModal #video,#fanFilterModal #canvas,#fanFilterModal #previewImage,#fanFilterModal #liveFrameOverlay{width:100%;height:100%;object-fit:cover;display:none}
#fanFilterModal #video,#fanFilterModal #canvas,#fanFilterModal #previewImage{position:relative;z-index:1}
#fanFilterModal #previewImage{z-index:3}
#fanFilterModal #liveFrameOverlay{position:absolute;inset:0;z-index:4;pointer-events:none;object-fit:fill}
#fanFilterModal #liveFlagOverlay{position:absolute;z-index:6;pointer-events:none;display:none;flex-direction:column;align-items:center;justify-content:flex-start}
#fanFilterModal #liveFlagBox{width:100%;background:#fff;box-shadow:0 8px 24px rgba(0,0,0,.25);display:flex;align-items:center;justify-content:center;overflow:hidden}
#fanFilterModal #liveFlagImage{width:100%;height:100%;object-fit:cover;display:block}
#fanFilterModal #liveFlagCode{display:block;color:#fff;font-weight:900;line-height:1.05;text-align:center;text-shadow:0 3px 8px rgba(0,0,0,.45);white-space:nowrap}
#fanFilterModal .camera-empty{position:absolute;inset:0;z-index:5;display:grid;place-items:center;color:rgba(255,255,255,.78);text-align:center;padding:22px}
#fanFilterModal .camera-empty b{display:block;color:#fff;font-size:22px;margin-bottom:8px}
#fanFilterModal .controls{margin:16px auto 0;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
#fanFilterModal .btn{border:0;border-radius:999px;padding:12px 16px;font-family:inherit;font-weight:900;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:46px;text-align:center}
#fanFilterModal .btn-primary{background:linear-gradient(135deg,#0E63E6,#0847B8);color:#fff}
#fanFilterModal .btn-gold{background:linear-gradient(135deg,#F5C85B,#E7A90C);color:#2A2105}
#fanFilterModal .btn-soft{background:#EEF5FC;color:#0B2C55}
#fanFilterModal .btn-green{background:linear-gradient(135deg,#15B97A,#0B8E5C);color:#fff}
#fanFilterModal .btn:disabled{opacity:.45;cursor:not-allowed}
#fanFilterModal .upload-input{display:none}
#fanFilterModal .flip-camera-btn{display:none}
#fanFilterModal .flip-camera-btn.show{display:inline-flex}
#fanFilterModal .option-block{background:#fff;border:1px solid #E8F0FA;border-radius:20px;padding:14px;box-shadow:0 10px 24px rgba(7,26,53,.05)}
#fanFilterModal .option-block + .option-block{margin-top:14px}
#fanFilterModal .platform-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
#fanFilterModal .platform-choice{border:2px solid transparent;background:#fff;border-radius:18px;padding:12px 8px;cursor:pointer;min-height:100px;box-shadow:0 10px 24px rgba(7,26,53,.06);text-align:center;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:7px}
#fanFilterModal .platform-choice.active{border-color:#0E63E6;background:linear-gradient(180deg,#fff,#F7FBFF)}
#fanFilterModal .platform-logo{width:40px;height:40px;border-radius:12px;object-fit:contain;background:#fff;padding:5px;box-shadow:0 10px 22px rgba(7,26,53,.12)}
#fanFilterModal .platform-logo-fallback{width:40px;height:40px;border-radius:12px;display:none;align-items:center;justify-content:center;color:#fff;font-size:17px;font-weight:900;background:linear-gradient(135deg,#0E63E6,#0B2C55)}
#fanFilterModal .platform-text strong{display:block;color:#0B2C55;font-size:12px;font-weight:900}
#fanFilterModal .platform-text span{display:block;color:#71839A;font-size:11px;font-weight:800;margin-top:4px}
#fanFilterModal .country-picker{position:relative}
#fanFilterModal .country-trigger{width:100%;border:2px solid #E1ECF8;background:#fff;border-radius:18px;padding:11px;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:12px}
#fanFilterModal .country-trigger.active{border-color:#0E63E6}
#fanFilterModal .country-selected{display:flex;align-items:center;gap:12px;min-width:0}
#fanFilterModal .country-selected img{width:50px;height:50px;object-fit:cover;border-radius:14px;background:#EEF5FC;flex:0 0 auto}
#fanFilterModal .country-name{display:block;color:#0B2C55;font-weight:900;line-height:1.15;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#fanFilterModal .country-code{display:block;color:#71839A;font-size:12px;font-weight:800;margin-top:4px}
#fanFilterModal .country-arrow{color:#0B2C55;font-weight:900;flex:0 0 auto}
#fanFilterModal .country-menu{display:none;position:absolute;z-index:30;top:calc(100% + 8px);left:0;right:0;background:#fff;border:1px solid #DDE9F6;border-radius:20px;box-shadow:0 24px 60px rgba(7,26,53,.18);overflow:hidden}
#fanFilterModal .country-menu.show{display:block}
#fanFilterModal .country-search-wrap{padding:10px;border-bottom:1px solid #EDF3FA}
#fanFilterModal .country-search{width:100%;border:1px solid #DDE9F6;background:#F7FBFF;border-radius:14px;padding:11px 13px;font-family:inherit;font-weight:800;color:#0B2C55;outline:none;font-size:15px}
#fanFilterModal .country-options{max-height:280px;overflow:auto;padding:7px;-webkit-overflow-scrolling:touch}
#fanFilterModal .country-option{width:100%;border:0;background:#fff;border-radius:14px;padding:9px;cursor:pointer;display:flex;align-items:center;gap:12px;text-align:left}
#fanFilterModal .country-option:hover,#fanFilterModal .country-option.active{background:#EEF5FC}
#fanFilterModal .country-option img{width:40px;height:40px;object-fit:cover;border-radius:12px;background:#EEF5FC;flex:0 0 auto}
#fanFilterModal .no-results{display:none;padding:16px;color:#71839A;font-weight:800;text-align:center}
#fanFilterModal .frame-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
#fanFilterModal .frame-choice{border:2px solid transparent;background:#fff;border-radius:18px;padding:9px;cursor:pointer;min-height:118px;box-shadow:0 10px 24px rgba(7,26,53,.06)}
#fanFilterModal .frame-choice.active{border-color:#0E63E6}
#fanFilterModal .frame-choice img{width:100%;height:76px;object-fit:cover;border-radius:13px;background:#EEF5FC}
#fanFilterModal .frame-choice span{display:block;margin-top:7px;font-size:12px;font-weight:900;color:#0B2C55;text-align:center}
#fanFilterModal .selection-summary{margin-top:14px;border-radius:18px;padding:13px;background:linear-gradient(135deg,#F7FBFF,#EEF5FC);border:1px solid #DDE9F6;color:#0B2C55;font-size:13px;font-weight:800;line-height:1.6}
#fanFilterModal .message{display:none;margin:14px auto 0;border-radius:16px;padding:12px 14px;font-weight:800;font-size:14px}
#fanFilterModal .message.ok{display:block;background:#E9FFF5;color:#08764B;border:1px solid #BDF1DA}
#fanFilterModal .message.err{display:block;background:#FFF1F1;color:#B42318;border:1px solid #FFD0D0}
@media(max-width:820px){#fanFilterModal .studio-layout{grid-template-columns:1fr}#fanFilterModal .platform-row,#fanFilterModal .frame-row{display:flex;overflow-x:auto;gap:10px;padding-bottom:6px}#fanFilterModal .platform-choice{min-width:140px}#fanFilterModal .frame-choice{min-width:140px}}
</style>

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
    const cameraWrap = document.querySelector('#fanFilterModal .camera-wrap');
    let stream=null, finalImageData='', finalImageBlob=null, currentFacingMode='user', hasCameraStarted=false;
    const isMobileDevice = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent||'');
    window.wcFFStop = function(){ if(stream){ stream.getTracks().forEach(t=>t.stop()); stream=null; hasCameraStarted=false; } };
    function showMessage(t,x){messageBox.className='message '+(t==='ok'?'ok':'err');messageBox.textContent=x;}
    function clearMessage(){messageBox.className='message';messageBox.textContent='';}
    function escapeHtml(s){return String(s).replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;').replaceAll("'",'&#039;');}
    function updateFlip(){ if(!flipCameraBtn) return; if(isMobileDevice&&navigator.mediaDevices&&navigator.mediaDevices.getUserMedia){flipCameraBtn.classList.add('show');flipCameraBtn.disabled=!hasCameraStarted;flipCameraBtn.textContent=currentFacingMode==='user'?'🔄 Back Camera':'🔄 Front Camera';}else{flipCameraBtn.classList.remove('show');flipCameraBtn.disabled=true;} }
    function selPlatform(){const el=document.querySelector('#fanFilterModal .platform-choice.active');if(!el)return null;return{id:el.dataset.platformId,name:el.dataset.platformName,code:el.dataset.platformCode,width:parseInt(el.dataset.width||'720',10),height:parseInt(el.dataset.height||'900',10)};}
    function selCountry(){const el=document.querySelector('#fanFilterModal .country-choice.active');if(!el)return null;return{id:el.dataset.countryId,name:el.dataset.countryName,code:el.dataset.countryCode,flag:el.dataset.flag};}
    function selFrame(){const el=document.querySelector('#fanFilterModal .frame-choice.active');if(!el)return null;return{id:el.dataset.frameId,name:el.dataset.frameName,frame:el.dataset.frame};}
    function updFrameOverlay(){const f=selFrame();if(!liveFrameOverlay)return;if(!f||!f.frame){liveFrameOverlay.style.display='none';liveFrameOverlay.src='';return;}liveFrameOverlay.src=f.frame;liveFrameOverlay.style.display='block';}
    function updFlagOverlay(){const c=selCountry();if(!liveFlagOverlay||!liveFlagBox||!liveFlagImage||!liveFlagCode||!cameraWrap||!c)return;const r=cameraWrap.getBoundingClientRect();const W=r.width||0,H=r.height||0;if(!W||!H){liveFlagOverlay.style.display='none';return;}const flagW=Math.round(W*0.245),flagH=Math.round(flagW*0.70),mr=Math.round(W*0.070),bm=Math.round(H*0.110),tg=Math.round(H*0.028),pad=Math.max(4,Math.round(W*0.0046));const fx=W-flagW-mr,fy=H-flagH-bm-tg;liveFlagOverlay.style.left=Math.round(fx-pad)+'px';liveFlagOverlay.style.top=Math.round(fy-pad)+'px';liveFlagOverlay.style.width=Math.round(flagW+pad*2)+'px';liveFlagOverlay.style.display='flex';liveFlagBox.style.height=Math.round(flagH+pad*2)+'px';liveFlagBox.style.padding=pad+'px';liveFlagBox.style.borderRadius=Math.round(W*0.030)+'px';liveFlagImage.style.borderRadius=Math.round(W*0.022)+'px';liveFlagImage.src=c.flag;liveFlagImage.alt=c.name||'';liveFlagCode.textContent=c.code||'';liveFlagCode.style.fontSize=Math.max(12,Math.round(W*0.040))+'px';liveFlagCode.style.marginTop=Math.max(2,tg-pad)+'px';}
    function updOverlays(){updFrameOverlay();updFlagOverlay();}
    function updSummary(){const p=selPlatform(),c=selCountry(),f=selFrame();if(selectionSummary)selectionSummary.innerHTML='Selected platform: <strong>'+escapeHtml(p?p.name:'-')+'</strong><br>Selected country: <strong>'+escapeHtml(c?c.name:'-')+'</strong><br>Selected frame: <strong>'+escapeHtml(f?f.name:'-')+'</strong>';}
    function updAspect(){const p=selPlatform();if(!p||!cameraWrap)return;cameraWrap.style.aspectRatio=p.width+' / '+p.height;}
    function filterFrames(){const p=selPlatform();if(!p)return;let first=null;document.querySelectorAll('#fanFilterModal .frame-choice').forEach(b=>{const m=String(b.dataset.platformId||'')===String(p.id);b.style.display=m?'':'none';b.classList.remove('active');if(m&&!first)first=b;});if(first)first.classList.add('active');updAspect();updSummary();finalImageData='';finalImageBlob=null;previewImage.src='';previewImage.style.display='none';saveDownloadBtn.disabled=true;retakeBtn.disabled=true;if(stream){video.style.display='block';cameraEmpty.style.display='none';captureBtn.disabled=false;}else{video.style.display='none';cameraEmpty.style.display='grid';captureBtn.disabled=true;}updOverlays();clearMessage();}
    function loadImage(src){return new Promise((res,rej)=>{const i=new Image();i.crossOrigin='anonymous';i.onload=()=>res(i);i.onerror=()=>rej(new Error('load '+src));i.src=src;});}
    function drawCover(s,x,y,w,h){const sw=s.videoWidth||s.naturalWidth||s.width,sh=s.videoHeight||s.naturalHeight||s.height;if(!sw||!sh)throw new Error('not ready');const r=Math.max(w/sw,h/sh),nw=sw*r,nh=sh*r;ctx.drawImage(s,x+(w-nw)/2,y+(h-nh)/2,nw,nh);}
    function roundRect(c,x,y,w,h,r){c.beginPath();c.moveTo(x+r,y);c.arcTo(x+w,y,x+w,y+h,r);c.arcTo(x+w,y+h,x,y+h,r);c.arcTo(x,y+h,x,y,r);c.arcTo(x,y,x+w,y,r);c.closePath();}
    async function compose(source){clearMessage();const p=selPlatform(),c=selCountry(),f=selFrame();if(!p||!c||!f){showMessage('err','Please choose a platform, country and frame first.');return;}canvas.width=p.width;canvas.height=p.height;ctx.clearRect(0,0,canvas.width,canvas.height);try{drawCover(source,0,0,canvas.width,canvas.height);}catch(e){showMessage('err','Camera image is not ready. Please try again.');return;}try{const fr=await loadImage(f.frame);ctx.drawImage(fr,0,0,canvas.width,canvas.height);}catch(e){showMessage('err','Frame image could not be loaded.');return;}try{const fl=await loadImage(c.flag);const flagW=Math.round(canvas.width*0.245),flagH=Math.round(flagW*0.70),mr=Math.round(canvas.width*0.070),bm=Math.round(canvas.height*0.110),tg=Math.round(canvas.height*0.028);const fx=canvas.width-flagW-mr,fy=canvas.height-flagH-bm-tg;ctx.save();ctx.shadowColor='rgba(0,0,0,.25)';ctx.shadowBlur=18;ctx.fillStyle='#fff';roundRect(ctx,fx-10,fy-10,flagW+20,flagH+20,Math.round(canvas.width*0.030));ctx.fill();ctx.restore();ctx.save();roundRect(ctx,fx,fy,flagW,flagH,Math.round(canvas.width*0.022));ctx.clip();ctx.drawImage(fl,fx,fy,flagW,flagH);ctx.restore();ctx.font='900 '+Math.round(canvas.width*0.040)+'px Inter, Arial';ctx.fillStyle='#fff';ctx.textAlign='center';ctx.shadowColor='rgba(0,0,0,.45)';ctx.shadowBlur=8;ctx.fillText(c.code,fx+flagW/2,fy+flagH+tg);ctx.shadowBlur=0;}catch(e){showMessage('err','Flag image could not be loaded.');return;}finalImageData=canvas.toDataURL('image/jpeg',0.88);finalImageBlob=await new Promise(r=>canvas.toBlob(r,'image/jpeg',0.88));if(!finalImageBlob){showMessage('err','Unable to prepare image.');return;}previewImage.src=finalImageData;previewImage.style.display='block';if(liveFrameOverlay)liveFrameOverlay.style.display='none';if(liveFlagOverlay)liveFlagOverlay.style.display='none';canvas.style.display='none';video.style.display='none';cameraEmpty.style.display='none';saveDownloadBtn.disabled=false;retakeBtn.disabled=false;showMessage('ok','Photo created successfully.');}
    async function startCamera(fm=currentFacingMode){clearMessage();if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia){showMessage('err','Camera not supported. Please upload a photo.');return;}try{if(stream)stream.getTracks().forEach(t=>t.stop());currentFacingMode=fm;stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:currentFacingMode},width:{ideal:1080},height:{ideal:1350}},audio:false});video.srcObject=stream;video.style.display='block';updOverlays();previewImage.style.display='none';cameraEmpty.style.display='none';await video.play();captureBtn.disabled=false;retakeBtn.disabled=true;saveDownloadBtn.disabled=true;finalImageData='';finalImageBlob=null;hasCameraStarted=true;updateFlip();}catch(e){hasCameraStarted=!!stream;updateFlip();showMessage('err','Unable to open camera. Allow access or upload a photo.');}}
    if(countryTrigger&&countryMenu){countryTrigger.addEventListener('click',e=>{e.stopPropagation();countryMenu.classList.toggle('show');if(countryMenu.classList.contains('show')&&countrySearch)setTimeout(()=>countrySearch.focus(),60);});document.addEventListener('click',e=>{const pk=document.getElementById('countryPicker');if(pk&&!pk.contains(e.target))countryMenu.classList.remove('show');});}
    document.querySelectorAll('#fanFilterModal .platform-choice').forEach(b=>b.addEventListener('click',()=>{document.querySelectorAll('#fanFilterModal .platform-choice').forEach(x=>x.classList.remove('active'));b.classList.add('active');filterFrames();}));
    if(countrySearch)countrySearch.addEventListener('input',()=>{const q=countrySearch.value.trim().toLowerCase();let v=0;document.querySelectorAll('#fanFilterModal .country-choice').forEach(b=>{const ok=(b.dataset.search||'').includes(q);b.style.display=ok?'flex':'none';if(ok)v++;});if(noCountryResults)noCountryResults.style.display=v?'none':'block';});
    document.querySelectorAll('#fanFilterModal .country-choice').forEach(b=>b.addEventListener('click',()=>{document.querySelectorAll('#fanFilterModal .country-choice').forEach(x=>x.classList.remove('active'));b.classList.add('active');selectedCountryFlag.src=b.dataset.flag;selectedCountryName.textContent=b.dataset.countryName;selectedCountryCode.textContent=b.dataset.countryCode;if(countryMenu)countryMenu.classList.remove('show');if(countrySearch){countrySearch.value='';document.querySelectorAll('#fanFilterModal .country-choice').forEach(x=>x.style.display='flex');if(noCountryResults)noCountryResults.style.display='none';}updSummary();updFlagOverlay();clearMessage();}));
    document.querySelectorAll('#fanFilterModal .frame-choice').forEach(b=>b.addEventListener('click',()=>{if(b.style.display==='none')return;document.querySelectorAll('#fanFilterModal .frame-choice').forEach(x=>x.classList.remove('active'));b.classList.add('active');updSummary();updOverlays();clearMessage();}));
    startCameraBtn.addEventListener('click',()=>startCamera(currentFacingMode));
    if(flipCameraBtn)flipCameraBtn.addEventListener('click',async()=>{if(!isMobileDevice)return;const nf=currentFacingMode==='user'?'environment':'user';flipCameraBtn.disabled=true;try{await startCamera(nf);}finally{updateFlip();}});
    captureBtn.addEventListener('click',()=>{if(!video.srcObject||!video.videoWidth){showMessage('err','Camera is not ready yet.');return;}compose(video);});
    uploadPhoto.addEventListener('change',()=>{const f=uploadPhoto.files&&uploadPhoto.files[0];if(!f)return;if(!f.type.startsWith('image/')){showMessage('err','Please upload an image file.');return;}const rd=new FileReader();rd.onload=()=>{const im=new Image();im.onload=()=>{if(stream){stream.getTracks().forEach(t=>t.stop());stream=null;hasCameraStarted=false;updateFlip();}compose(im);};im.onerror=()=>showMessage('err','Unable to read photo.');im.src=rd.result;};rd.readAsDataURL(f);});
    retakeBtn.addEventListener('click',()=>{finalImageData='';finalImageBlob=null;previewImage.src='';previewImage.style.display='none';saveDownloadBtn.disabled=true;retakeBtn.disabled=true;if(stream){video.style.display='block';updOverlays();cameraEmpty.style.display='none';captureBtn.disabled=false;}else{video.style.display='none';cameraEmpty.style.display='grid';captureBtn.disabled=true;}clearMessage();});
    saveDownloadBtn.addEventListener('click',async()=>{if(!finalImageData||!finalImageBlob){showMessage('err','Create your photo first.');return;}const p=selPlatform(),c=selCountry(),f=selFrame();if(!p||!c||!f){showMessage('err','Please choose a platform, country and frame first.');return;}saveDownloadBtn.disabled=true;saveDownloadBtn.textContent='Saving…';try{const fd=new FormData();fd.append('csrf',csrf);fd.append('photo',finalImageBlob,'wc2026-fan-filter.jpg');fd.append('country_id',c.id);fd.append('frame_id',f.id);fd.append('platform_id',p.id);const res=await fetch('/WC/api/save_filter_photo.php',{method:'POST',body:fd,credentials:'same-origin'});const data=await res.json();if(!data.ok)throw new Error(data.message||'Unable to save photo.');const a=document.createElement('a');a.download='wc2026-fan-filter.jpg';a.href=finalImageData;a.click();showMessage('ok','Photo saved and downloaded successfully.');}catch(e){const a=document.createElement('a');a.download='wc2026-fan-filter.jpg';a.href=finalImageData;a.click();showMessage('ok','Photo downloaded. (Server save not reachable.)');}finally{saveDownloadBtn.disabled=false;saveDownloadBtn.textContent='⚽ Save & Download';}});
    window.addEventListener('resize',()=>updFlagOverlay());
    filterFrames();updOverlays();updateFlip();
})();
</script>

</body>
</html>
