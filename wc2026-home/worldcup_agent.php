<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| World Cup 2026 Fan Agent — AR / EN  (now with live API-Football data)
|--------------------------------------------------------------------------
| Calls the AI Gateway endpoints AND pulls live football data directly from
| API-Football (api-sports.io) through a secure same-origin proxy so the API
| key never leaves the server. Team/league logos come from media.api-sports.io.
*/

/* ---- .env loader (same convention as EOL-review: /var/secrets/.env) ----
   Loads KEY=VALUE pairs into $_ENV / putenv. Also checks a local .env next to
   this file as a fallback. Credentials are never hardcoded in the repo. */
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

/* ---- API-Football (api-sports.io) configuration ----
   The key is read from .env (APISPORTS_KEY) and used ONLY server-side —
   it is never exposed to the browser. */
if (!defined('APISPORTS_KEY'))   define('APISPORTS_KEY',   (string)(getenv('APISPORTS_KEY') ?: ($_ENV['APISPORTS_KEY'] ?? '')));
if (!defined('APISPORTS_BASE'))  define('APISPORTS_BASE',  'https://v3.football.api-sports.io');
if (!defined('APISPORTS_MEDIA')) define('APISPORTS_MEDIA', 'https://media.api-sports.io');
if (!defined('WC_LEAGUE_ID'))    define('WC_LEAGUE_ID',    (int)(getenv('WC_LEAGUE_ID') ?: ($_ENV['WC_LEAGUE_ID'] ?? 1)));   // FIFA World Cup league id
if (!defined('WC_SEASON'))       define('WC_SEASON',       (int)(getenv('WC_SEASON')    ?: ($_ENV['WC_SEASON']    ?? 2026)));

$wcCacheDir = sys_get_temp_dir() . '/wc_apifootball';

/** GET an API-Football URL with the key header + short-lived file cache (saves quota). */
function wc_apifootball_get(string $url, string $cacheDir, int $ttl = 60): string {
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
    $cacheFile = $cacheDir . '/' . md5($url) . '.json';
    if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        return (string)file_get_contents($cacheFile);
    }
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 12,
            'header' => 'x-apisports-key: ' . APISPORTS_KEY . "\r\n"]]);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) { return is_file($cacheFile) ? (string)file_get_contents($cacheFile) : json_encode(['ok'=>false,'error'=>'request failed']); }
        @file_put_contents($cacheFile, $res);
        return $res;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['x-apisports-key: ' . APISPORTS_KEY],
    ]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($res === false || $code >= 400) {
        if (is_file($cacheFile)) return (string)file_get_contents($cacheFile); // serve stale on error
        return json_encode(['ok'=>false,'error'=>'API request failed'.($err ? ': '.$err : ''), 'http'=>$code]);
    }
    @file_put_contents($cacheFile, $res);
    return $res;
}

/* ---- Secure same-origin proxy: worldcup_agent.php?api=<endpoint>&<params...> ----
   Keeps the API key on the server. Only GET + an allow-list of endpoints. */
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $ep = (string)$_GET['api'];
    $allow = ['status','fixtures','fixtures/events','fixtures/lineups','fixtures/statistics','fixtures/players',
              'standings','teams','leagues','predictions','injuries','countries','timezone'];
    if (!in_array($ep, $allow, true)) {
        http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Endpoint not allowed.']); exit;
    }
    if (APISPORTS_KEY === '') {
        http_response_code(503); echo json_encode(['ok'=>false,'error'=>'API key not configured. Set the APISPORTS_KEY environment variable on the server.']); exit;
    }
    $ttlMap = ['status'=>30,'fixtures'=>30,'predictions'=>1800,'standings'=>300,'teams'=>3600,'leagues'=>3600,'countries'=>86400,'timezone'=>86400];
    $params = $_GET; unset($params['api']);
    $qs  = http_build_query($params);
    $url = APISPORTS_BASE . '/' . $ep . ($qs !== '' ? ('?' . $qs) : '');
    echo wc_apifootball_get($url, $wcCacheDir, $ttlMap[$ep] ?? 60);
    exit;
}
?>
<!doctype html>
<html lang="en" dir="ltr" data-theme="catrion">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>World Cup 2026 Fan Agent</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --brand:#05162F; --brand2:#08234A; --brand3:#0E63E6;
  --accent:#F5C85B; --accent2:#FFE19A; --good:#22C55E;
  --panel:rgba(7,31,66,.92); --line:rgba(168,231,255,.18);
  --text:#EAF3FF; --muted:rgba(234,243,255,.66);
  --shadow:0 26px 64px rgba(0,0,0,.4);
}
html[data-theme="saudi"]{
  --brand:#03190f; --brand2:#06371f; --brand3:#0e7c43; --accent:#FFE19A; --accent2:#F5C85B;
}
*{box-sizing:border-box}
html,body{margin:0;min-height:100%;font-family:'Inter','Tajawal',sans-serif;color:var(--text);
  background:radial-gradient(circle at 10% 6%,rgba(85,183,255,.12),transparent 30%),
    radial-gradient(circle at 92% 4%,rgba(14,99,230,.20),transparent 34%),
    linear-gradient(180deg,var(--brand) 0%,var(--brand2) 50%,var(--brand) 100%);background-attachment:fixed}
html[dir="rtl"] body{font-family:'Tajawal','Inter',sans-serif}
.app{max-width:1180px;margin:0 auto;padding:24px}
.hero{position:relative;overflow:hidden;background:linear-gradient(135deg,var(--brand) 0%,var(--brand2) 55%,var(--brand3) 100%);
  color:#fff;border-radius:26px;padding:26px;box-shadow:var(--shadow);margin-bottom:18px;
  display:flex;justify-content:space-between;gap:18px;align-items:flex-start;border:1px solid var(--line)}
.hero:after{content:"";position:absolute;width:320px;height:320px;border-radius:50%;top:-130px;inset-inline-end:-90px;
  background:radial-gradient(circle,rgba(245,200,91,.30),transparent 70%);pointer-events:none}
.hero-l{position:relative;z-index:1}
.hero h1{margin:0;font-size:30px;font-weight:900;line-height:1.15}
.hero h1 b{color:var(--accent)}
.hero p{margin:10px 0 0;color:rgba(255,255,255,.88);font-size:14px;line-height:1.7;max-width:560px}
.hero-r{position:relative;z-index:1;display:flex;flex-direction:column;gap:10px;align-items:flex-end}
.switch{display:flex;gap:6px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:999px;padding:5px}
.switch button{border:0;border-radius:999px;padding:8px 13px;background:transparent;color:#fff;font-weight:800;font-size:12px;cursor:pointer;font-family:inherit}
.switch button.active{background:#fff;color:var(--brand)}
.cards{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:18px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:18px;padding:14px;box-shadow:var(--shadow)}
.card .label{font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:800}
.card .value{margin-top:6px;font-size:14px;font-weight:800;color:#fff}
.grid{display:grid;grid-template-columns:360px 1fr;gap:18px}
.panel{background:var(--panel);border:1px solid var(--line);border-radius:22px;box-shadow:var(--shadow);overflow:hidden}
.panel-head{padding:16px 18px;background:linear-gradient(135deg,rgba(245,200,91,.16),rgba(85,183,255,.12));border-bottom:1px solid var(--line)}
.panel-head h2{margin:0;font-size:16px;font-weight:900}
.panel-head p{margin:5px 0 0;font-size:12px;color:var(--muted)}
.panel-body{padding:18px}
.field{margin-bottom:14px}
.field label{display:block;margin-bottom:7px;font-size:12px;font-weight:800;color:var(--accent)}
.field input,.field select,.field textarea{width:100%;border:1px solid var(--line);border-radius:14px;padding:12px 13px;
  font:inherit;font-size:13px;color:#fff;background:rgba(255,255,255,.06);outline:none}
.field option{color:#06202e}
.field textarea{min-height:120px;resize:vertical;line-height:1.7}
.btn{width:100%;min-height:46px;border:0;border-radius:14px;padding:0 16px;font-weight:900;font-size:13px;cursor:pointer;font-family:inherit;
  background:linear-gradient(135deg,var(--accent),var(--accent2));color:#06202e}
.btn.secondary{background:rgba(255,255,255,.07);color:#fff;border:1px solid var(--line)}
.btn-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.quick{display:grid;gap:8px;margin-top:14px}
.quick button{border:1px solid var(--line);background:rgba(255,255,255,.05);color:#fff;border-radius:12px;padding:10px 12px;
  text-align:start;font-weight:700;cursor:pointer;font-size:12px;font-family:inherit}
.quick button:hover{border-color:var(--accent);color:var(--accent)}
.status{margin-top:12px;padding:10px 12px;border-radius:14px;background:rgba(255,255,255,.05);font-size:12px;color:var(--muted)}
.chat{min-height:560px;display:flex;flex-direction:column;gap:12px}
.msg{max-width:88%;padding:12px 15px;border-radius:16px;line-height:1.85;font-size:14px;white-space:pre-wrap;word-wrap:break-word}
.msg.bot{align-self:flex-start;background:rgba(255,255,255,.07);border:1px solid var(--line);border-bottom-left-radius:5px}
.msg.user{align-self:flex-end;background:linear-gradient(135deg,var(--accent),var(--accent2));color:#06202e;font-weight:700;border-bottom-right-radius:5px}
html[dir="rtl"] .msg.user{border-bottom-right-radius:16px;border-bottom-left-radius:5px}
html[dir="rtl"] .msg.bot{border-bottom-left-radius:16px;border-bottom-right-radius:5px}
.typing{align-self:flex-start;display:flex;gap:5px;padding:13px 15px;background:rgba(255,255,255,.07);border:1px solid var(--line);border-radius:16px}
.typing i{width:7px;height:7px;border-radius:50%;background:var(--accent);animation:t 1.2s infinite}
.typing i:nth-child(2){animation-delay:.2s}.typing i:nth-child(3){animation-delay:.4s}
@keyframes t{0%{opacity:.25}20%{opacity:1}100%{opacity:.25}}
.raw{margin-top:16px;border-top:1px solid var(--line);padding-top:14px}
.raw summary{cursor:pointer;font-weight:800;color:var(--accent);font-size:12px}
.raw pre{background:rgba(0,0,0,.25);border:1px solid var(--line);border-radius:14px;padding:12px;overflow:auto;max-height:240px;font-size:11px;direction:ltr;text-align:left}
@media(max-width:960px){.grid{grid-template-columns:1fr}.cards{grid-template-columns:1fr 1fr}.hero{flex-direction:column}.hero-r{align-items:flex-start;flex-direction:row;flex-wrap:wrap}}
@media(max-width:600px){.app{padding:14px}.cards{grid-template-columns:1fr}.btn-row{grid-template-columns:1fr}}

/* ---- Powered-by badge + live API-Football panel ---- */
.api-badge{display:inline-flex;align-items:center;gap:7px;margin-top:14px;padding:7px 12px;border-radius:999px;
  background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.2);color:#fff;font-size:11px;font-weight:800;text-decoration:none}
.api-badge b{color:var(--accent)}
.api-badge .dot{width:8px;height:8px;border-radius:50%;background:#9aa7b8;box-shadow:0 0 10px rgba(154,167,184,.6)}
.api-badge .dot.live{background:var(--good);box-shadow:0 0 12px rgba(34,197,94,.85)}
.api-badge .dot.off{background:#E94747;box-shadow:0 0 12px rgba(233,71,71,.7)}
.live{margin-top:18px}
.live .panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.live-tabs{display:flex;gap:6px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.2);border-radius:999px;padding:5px}
.live-tabs button{border:0;border-radius:999px;padding:8px 13px;background:transparent;color:#fff;font-weight:800;font-size:12px;cursor:pointer;font-family:inherit}
.live-tabs button.active{background:#fff;color:var(--brand)}
.live-status{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.chip{display:inline-flex;align-items:center;gap:7px;padding:8px 12px;border-radius:12px;background:rgba(255,255,255,.06);border:1px solid var(--line);font-size:12px;font-weight:800}
.chip span{color:var(--muted);font-weight:700}
.fx-list{display:grid;gap:10px}
.fx{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:10px;padding:12px 14px;border-radius:14px;
  background:rgba(255,255,255,.05);border:1px solid var(--line);cursor:pointer;transition:.16s}
.fx:hover{border-color:var(--accent);background:rgba(245,200,91,.06)}
.fx-team{display:flex;align-items:center;gap:10px;min-width:0}
.fx-team.away{justify-content:flex-end}
.fx-team img{width:30px;height:30px;object-fit:contain;flex:none}
.fx-team span{font-weight:800;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fx-mid{text-align:center;min-width:96px}
.fx-score{font-weight:900;font-size:17px;color:#fff}
.fx-time{font-size:10.5px;color:var(--muted);font-weight:700;margin-top:2px}
.stbl{width:100%;border-collapse:collapse;font-size:13px}
.stbl th,.stbl td{padding:8px 10px;border-bottom:1px solid var(--line);text-align:start;white-space:nowrap}
.stbl th{color:var(--muted);font-size:10.5px;text-transform:uppercase;font-weight:900}
.stbl td{font-weight:700}
.stbl .tm{display:flex;align-items:center;gap:9px}
.stbl .tm img{width:22px;height:22px;object-fit:contain}
.stbl .pts{color:var(--accent);font-weight:900}
.live-empty{color:var(--muted);font-weight:700;text-align:center;padding:26px;font-size:13px}
.live-controls{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}
.live-controls input{border:1px solid var(--line);border-radius:11px;padding:9px 11px;background:rgba(255,255,255,.06);color:#fff;font:inherit;font-size:12px;font-weight:700;width:120px}
.live-controls .btn{width:auto;min-height:38px;padding:0 16px}
</style>
</head>
<body>
<div class="app">
  <section class="hero">
    <div class="hero-l">
      <h1 data-i18n-html="title">World Cup 2026 <b>Fan Agent</b></h1>
      <p data-i18n="subtitle">AI-powered fan assistant connected to API-Football and Azure OpenAI through your central AI Gateway.</p>
      <a class="api-badge" id="apiBadge" href="https://www.api-football.com" target="_blank" rel="noopener">
        <span class="dot" id="apiDot"></span>⚽ <span>Powered by</span> <b>API-Football</b>
      </a>
    </div>
    <div class="hero-r">
      <div class="switch" aria-label="Theme">
        <button type="button" data-theme-set="catrion" data-i18n="themeCatrion">CATRION</button>
        <button type="button" data-theme-set="saudi" data-i18n="themeSaudi">Saudi</button>
      </div>
      <div class="switch" aria-label="Language">
        <button type="button" data-lang-set="en">EN</button>
        <button type="button" data-lang-set="ar">عربي</button>
      </div>
    </div>
  </section>

  <div class="cards">
    <div class="card"><div class="label" data-i18n="cardModule">Module</div><div class="value">WORLDCUP</div></div>
    <div class="card"><div class="label" data-i18n="cardModeLabel">Mode</div><div class="value" id="cardMode">Fan Assistant</div></div>
    <div class="card"><div class="label" data-i18n="cardDateLabel">Date</div><div class="value" id="cardDate">-</div></div>
    <div class="card"><div class="label" data-i18n="cardFixtureLabel">Fixture</div><div class="value" id="cardFixture">-</div></div>
    <div class="card"><div class="label" data-i18n="cardTeamLabel">Team</div><div class="value" id="cardTeam">-</div></div>
  </div>

  <div class="grid">
    <section class="panel">
      <div class="panel-head"><h2 data-i18n="controlTitle">Agent Control</h2><p data-i18n="controlSub">Choose the AI skill and provide date, fixture ID, team ID, or question.</p></div>
      <div class="panel-body">
        <div class="field"><label data-i18n="functionLabel">AI Function</label>
          <select id="mode">
            <option value="fan" data-i18n="fan">Fan Assistant</option>
            <option value="predict" data-i18n="predict">Match Predictor</option>
            <option value="tactical" data-i18n="tactical">Tactical Analyst</option>
            <option value="summary" data-i18n="summary">Match Summary</option>
            <option value="command" data-i18n="command">Command Center</option>
          </select></div>
        <div class="field"><label data-i18n="dateLabel">Date</label><input id="date" type="date" value="2026-06-11"></div>
        <div class="field"><label data-i18n="fixtureLabel">Fixture ID (optional)</label><input id="fixture" type="number" placeholder="e.g. 123456"></div>
        <div class="field"><label data-i18n="teamLabel">Team ID (optional)</label><input id="team" type="number" placeholder="API-Football team ID"></div>
        <div class="field"><label data-i18n="questionLabel">Question / Instruction</label><textarea id="question">What should Saudi fans watch today?</textarea></div>
        <div class="btn-row">
          <button class="btn" type="button" id="runBtn" data-i18n="runBtn">Run Agent</button>
          <button class="btn secondary" type="button" id="clearBtn" data-i18n="clearBtn">Clear</button>
        </div>
        <div class="quick">
          <button type="button" data-quick="fan|daily" data-i18n="quickDaily">Saudi fan daily briefing</button>
          <button type="button" data-quick="predict|predict" data-i18n="quickPredict">Predict fixture</button>
          <button type="button" data-quick="tactical|tactical" data-i18n="quickTactical">Tactical analysis</button>
          <button type="button" data-quick="summary|summary" data-i18n="quickSummary">Match summary</button>
          <button type="button" data-quick="command|command" data-i18n="quickCommand">Command center</button>
        </div>
        <div class="status" id="status" data-i18n="ready">Ready.</div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head"><h2 data-i18n="outputTitle">Agent Output</h2><p data-i18n="outputSub">Response from Azure OpenAI using API-Football context.</p></div>
      <div class="panel-body">
        <div class="chat" id="chat"></div>
        <details class="raw"><summary data-i18n="rawJson">Raw JSON</summary><pre id="rawJson">{}</pre></details>
      </div>
    </section>
  </div>

  <!-- ===== Live API-Football data ===== -->
  <section class="panel live">
    <div class="panel-head">
      <div>
        <h2 data-i18n="liveTitle">Live Football Data</h2>
        <p data-i18n="liveSub">Fixtures, standings and connection status — live from API-Football, with official team & league logos.</p>
      </div>
      <div class="live-tabs" id="liveTabs">
        <button type="button" data-live="fixtures" class="active" data-i18n="tabFixtures">Fixtures</button>
        <button type="button" data-live="standings" data-i18n="tabStandings">Standings</button>
        <button type="button" data-live="status" data-i18n="tabStatus">API status</button>
      </div>
    </div>
    <div class="panel-body">
      <div class="live-controls" id="liveControls">
        <input id="wcLeague" type="number" value="<?= (int)WC_LEAGUE_ID ?>" title="League ID">
        <input id="wcSeason" type="number" value="<?= (int)WC_SEASON ?>" title="Season">
        <select id="wcWhen" title="Range">
          <option value="next" data-i18n="upcoming">Upcoming</option>
          <option value="last" data-i18n="finished">Finished (with stats)</option>
        </select>
        <button class="btn" type="button" id="liveRefresh" data-i18n="refresh">Refresh</button>
      </div>
      <div id="liveBox"><div class="live-empty" data-i18n="liveLoading">Loading…</div></div>
    </div>
  </section>
</div>

<script>
const APISPORTS_MEDIA='https://media.api-sports.io';
const teamLogo   = id => `${APISPORTS_MEDIA}/football/teams/${id}.png`;
const leagueLogo = id => `${APISPORTS_MEDIA}/football/leagues/${id}.png`;
const countryFlag= c  => `${APISPORTS_MEDIA}/flags/${(c||'').toLowerCase()}.svg`;
const endpointMap={
  fan:'/AI-Gateway/api/wc_ai_fan_assistant.php',
  predict:'/AI-Gateway/api/wc_ai_predict.php',
  tactical:'/AI-Gateway/api/wc_ai_tactical.php',
  summary:'/AI-Gateway/api/wc_ai_match_summary.php',
  command:'/AI-Gateway/api/wc_ai_command_center.php'
};
const i18n={
 en:{title:'World Cup 2026 <b>Fan Agent</b>',subtitle:'AI-powered fan assistant connected to API-Football and Azure OpenAI through your central AI Gateway.',
  themeCatrion:'CATRION',themeSaudi:'Saudi',cardModule:'Module',cardModeLabel:'Mode',cardDateLabel:'Date',cardFixtureLabel:'Fixture',cardTeamLabel:'Team',
  controlTitle:'Agent Control',controlSub:'Choose the AI skill and provide date, fixture ID, team ID, or question.',
  functionLabel:'AI Function',dateLabel:'Date',fixtureLabel:'Fixture ID (optional)',teamLabel:'Team ID (optional)',questionLabel:'Question / Instruction',
  runBtn:'Run Agent',clearBtn:'Clear',quickDaily:'Saudi fan daily briefing',quickPredict:'Predict fixture',quickTactical:'Tactical analysis',quickSummary:'Match summary',quickCommand:'Command center',
  ready:'Ready.',outputTitle:'Agent Output',outputSub:'Response from Azure OpenAI using API-Football context.',rawJson:'Raw JSON',
  running:'Running agent…',failed:'Failed',done:'Done',error:'Error',nonJson:'Failed: non-JSON response',greeting:'Welcome! Pick a skill, set the details, and run the agent.',
  fan:'Fan Assistant',predict:'Match Predictor',tactical:'Tactical Analyst',summary:'Match Summary',command:'Command Center',
  quickText:{daily:'What should Saudi fans watch today?',predict:'Predict this fixture and explain confidence clearly.',tactical:'Analyze the tactical strengths, weaknesses, and key matchups.',summary:'Summarize this match for social media and fans.',command:'Give executive dashboard insights for today.'},
  liveTitle:'Live Football Data',liveSub:'Fixtures, standings and connection status — live from API-Football, with official team & league logos.',tabFixtures:'Fixtures',tabStandings:'Standings',tabStatus:'API status',refresh:'Refresh',liveLoading:'Loading…',noFixtures:'No fixtures found for this league/season. Adjust the League ID / Season.',noStandings:'No standings available for this league/season.',apiErr:'API error',plan:'Plan',quota:'Daily quota',account:'Account',team:'Team',upcoming:'Upcoming',finished:'Finished (with stats)',
  aiInstruction:'Respond in English with clear, fan-friendly wording. Treat the "LIVE API-FOOTBALL MATCH DATA" section below as ground truth and base your lineups, formations, scorers, stats and prediction analysis on it. Only say a detail is unavailable if it is genuinely absent from that data.'},
 ar:{title:'وكيل جماهير <b>كأس العالم 2026</b>',subtitle:'مساعد ذكي للجماهير مرتبط ببيانات API-Football و Azure OpenAI عبر بوابة الذكاء الاصطناعي المركزية.',
  themeCatrion:'كاتريون',themeSaudi:'السعودية',cardModule:'الوحدة',cardModeLabel:'النمط',cardDateLabel:'التاريخ',cardFixtureLabel:'المباراة',cardTeamLabel:'الفريق',
  controlTitle:'لوحة التحكم',controlSub:'اختر وظيفة الذكاء الاصطناعي وأدخل التاريخ أو رقم المباراة أو رقم الفريق أو السؤال.',
  functionLabel:'وظيفة الذكاء الاصطناعي',dateLabel:'التاريخ',fixtureLabel:'رقم المباراة (اختياري)',teamLabel:'رقم الفريق (اختياري)',questionLabel:'السؤال / التعليمات',
  runBtn:'تشغيل الوكيل',clearBtn:'مسح',quickDaily:'موجز يومي للمشجع السعودي',quickPredict:'توقع المباراة',quickTactical:'تحليل تكتيكي',quickSummary:'ملخص المباراة',quickCommand:'مركز القيادة',
  ready:'جاهز.',outputTitle:'مخرجات الوكيل',outputSub:'استجابة من Azure OpenAI باستخدام سياق API-Football.',rawJson:'JSON الخام',running:'جاري التشغيل…',failed:'فشل',done:'تم',error:'خطأ',nonJson:'فشل: الاستجابة ليست JSON',greeting:'مرحبًا! اختر مهارة، حدّد التفاصيل، ثم شغّل الوكيل.',
  fan:'مساعد الجماهير',predict:'متوقّع المباراة',tactical:'محلل تكتيكي',summary:'ملخص المباراة',command:'مركز القيادة',
  quickText:{daily:'ما أهم ما يتابعه المشجع السعودي اليوم؟',predict:'توقّع نتيجة هذه المباراة واشرح مستوى الثقة بوضوح.',tactical:'حلل نقاط القوة والضعف التكتيكية والمواجهات المهمة.',summary:'لخص هذه المباراة للجماهير ووسائل التواصل.',command:'أعطني رؤى تنفيذية ولوحة قيادة لليوم.'},
  liveTitle:'بيانات كرة القدم الحية',liveSub:'المباريات والترتيب وحالة الاتصال — مباشرة من API-Football مع شعارات الفرق والبطولات الرسمية.',tabFixtures:'المباريات',tabStandings:'الترتيب',tabStatus:'حالة API',refresh:'تحديث',liveLoading:'جارٍ التحميل…',noFixtures:'لا توجد مباريات لهذه البطولة/الموسم. عدّل رقم البطولة/الموسم.',noStandings:'لا يوجد ترتيب متاح لهذه البطولة/الموسم.',apiErr:'خطأ في API',plan:'الباقة',quota:'الحصة اليومية',account:'الحساب',team:'الفريق',upcoming:'قادمة',finished:'منتهية (بالإحصاءات)',
  aiInstruction:'أجب بالعربية بأسلوب واضح ومناسب للجماهير. اعتبر قسم "LIVE API-FOOTBALL MATCH DATA" أدناه مصدراً موثوقاً، وابنِ تحليلك للتشكيلات والخطط والأهداف والإحصاءات والتوقعات عليه. لا تقل إن معلومة غير متوفرة إلا إذا كانت غائبة فعلاً عن تلك البيانات.'}
};
let lang=localStorage.getItem('wc_agent_lang')||'en';
let theme=localStorage.getItem('wc_agent_theme')||'catrion';
function t(k){return (i18n[lang]&&i18n[lang][k]!=null)?i18n[lang][k]:(i18n.en[k]!=null?i18n.en[k]:k);}
const $=id=>document.getElementById(id);
function setTheme(x){theme=(x==='saudi')?'saudi':'catrion';document.documentElement.setAttribute('data-theme',theme);
  document.querySelectorAll('[data-theme-set]').forEach(b=>b.classList.toggle('active',b.dataset.themeSet===theme));localStorage.setItem('wc_agent_theme',theme);}
function applyLang(l){lang=(l==='ar')?'ar':'en';document.documentElement.lang=lang;document.documentElement.dir=lang==='ar'?'rtl':'ltr';
  document.querySelectorAll('[data-i18n]').forEach(el=>el.textContent=t(el.dataset.i18n));
  document.querySelectorAll('[data-i18n-html]').forEach(el=>el.innerHTML=t(el.dataset.i18nHtml));
  document.querySelectorAll('[data-lang-set]').forEach(b=>b.classList.toggle('active',b.dataset.langSet===lang));
  localStorage.setItem('wc_agent_lang',lang);syncCards();
  if(!$('chat').children.length) addMsg(t('greeting'),'bot');}
function modeLabel(m){return t(m)||m;}
function addMsg(text,who){const m=document.createElement('div');m.className='msg '+who;m.textContent=text;$('chat').appendChild(m);$('chat').scrollTop=$('chat').scrollHeight;return m;}
function syncCards(){$('cardMode').textContent=modeLabel($('mode').value);$('cardDate').textContent=$('date').value||'-';$('cardFixture').textContent=$('fixture').value||'-';$('cardTeam').textContent=$('team').value||'-';}
function clearOutput(){$('chat').innerHTML='';$('rawJson').textContent='{}';$('status').textContent=t('ready');addMsg(t('greeting'),'bot');}
/* Build a ground-truth match-data context from API-Football for one fixture. */
function fmtEvents(ev){return (ev||[]).slice(0,45).map(e=>{const m=(e.time&&e.time.elapsed!=null?e.time.elapsed+"'":'')+(e.time&&e.time.extra?'+'+e.time.extra:'');return m+' '+((e.team&&e.team.name)||'')+' — '+e.type+(e.detail?' ('+e.detail+')':'')+': '+((e.player&&e.player.name)||'')+((e.assist&&e.assist.name)?' (assist '+e.assist.name+')':'');}).join('\n');}
function fmtLineups(lu){return (lu||[]).map(l=>{const xi=(l.startXI||[]).map(p=>'#'+(p.player.number||'')+' '+p.player.name+(p.player.pos?' ('+p.player.pos+')':'')).join(', ');const subs=(l.substitutes||[]).map(p=>p.player.name).join(', ');return ((l.team&&l.team.name)||'')+' — formation '+(l.formation||'?')+', coach '+((l.coach&&l.coach.name)||'?')+'\n  XI: '+xi+'\n  Subs: '+subs;}).join('\n\n');}
function fmtStats(st){return (st||[]).map(s=>((s.team&&s.team.name)||'')+': '+(s.statistics||[]).map(x=>x.type+' '+(x.value==null?'-':x.value)).join(', ')).join('\n');}
function fmtPred(p){
  if(!p) return '';
  const pr=p.predictions||{},pc=pr.percent||{},cmp=p.comparison||{},tm=p.teams||{};
  const L=[];
  L.push('winner '+((pr.winner&&pr.winner.name)||'-')+', win-or-draw '+pr.win_or_draw+', under/over '+(pr.under_over||'-')+', advice: '+(pr.advice||'-')+', % H/D/A '+(pc.home||'-')+'/'+(pc.draw||'-')+'/'+(pc.away||'-'));
  const fm=x=>x&&x.last_5?('last5 form '+(x.last_5.form||'-')+', att '+(x.last_5.att||'-')+', def '+(x.last_5.def||'-')+(x.last_5.goals?(' goals '+(x.last_5.goals.for&&x.last_5.goals.for.total)+'/'+(x.last_5.goals.against&&x.last_5.goals.against.total)):'')):'';
  if(tm.home) L.push('  Home ('+tm.home.name+'): '+fm(tm.home)+((tm.home.league&&tm.home.league.form)?(' | season form '+tm.home.league.form):''));
  if(tm.away) L.push('  Away ('+tm.away.name+'): '+fm(tm.away)+((tm.away.league&&tm.away.league.form)?(' | season form '+tm.away.league.form):''));
  const c2=(o)=>o?((o.home||'-')+'/'+(o.away||'-')):'-';
  if(cmp.form||cmp.att||cmp.def||cmp.total||cmp.h2h) L.push('  Comparison H/A — form '+c2(cmp.form)+', attack '+c2(cmp.att)+', defense '+c2(cmp.def)+', poisson '+c2(cmp.poisson_distribution)+', h2h '+c2(cmp.h2h)+', total '+c2(cmp.total));
  return L.join('\n');
}
function fmtInj(inj){return (inj||[]).slice(0,45).map(i=>(((i.team&&i.team.name)||'')+': '+((i.player&&i.player.name)||'')+' — '+((i.player&&i.player.type)||'')+' '+((i.player&&i.player.reason)||'')).trim()).join('\n');}
async function buildFixtureContext(fid){
  const fxR=await apiGet('fixtures',{id:fid});
  const f=(fxR&&fxR.response&&fxR.response[0])||null;
  if(!f) return {summary:'',raw:null};
  let events=f.events||[], lineups=f.lineups||[], stats=f.statistics||[];
  const jobs=[
    apiGet('predictions',{fixture:fid}).catch(()=>null),
    apiGet('injuries',{fixture:fid}).catch(()=>null)
  ];
  /* fall back to the dedicated endpoints when the embedded arrays are empty */
  if(!events.length)  jobs.push(apiGet('fixtures/events',{fixture:fid}).then(r=>{events=(r&&r.response)||events;}).catch(()=>{}));
  if(!lineups.length) jobs.push(apiGet('fixtures/lineups',{fixture:fid}).then(r=>{lineups=(r&&r.response)||lineups;}).catch(()=>{}));
  if(!stats.length)   jobs.push(apiGet('fixtures/statistics',{fixture:fid}).then(r=>{stats=(r&&r.response)||stats;}).catch(()=>{}));
  const out=await Promise.all(jobs);
  const pr=(out[0]&&out[0].response&&out[0].response[0])||null;
  const injuries=(out[1]&&out[1].response)||[];
  const sc=f.score||{},ht=sc.halftime||{};
  const P=[];
  P.push('FIXTURE: '+f.teams.home.name+' vs '+f.teams.away.name+' — '+((f.fixture.status&&f.fixture.status.long)||'')+' | '+f.fixture.date);
  P.push('VENUE: '+((f.fixture.venue&&f.fixture.venue.name)||'-')+((f.fixture.venue&&f.fixture.venue.city)?(', '+f.fixture.venue.city):'')+' | REFEREE: '+(f.fixture.referee||'-'));
  P.push('SCORE: '+(f.goals.home==null?'-':f.goals.home)+'-'+(f.goals.away==null?'-':f.goals.away)+' (HT '+(ht.home==null?'-':ht.home)+'-'+(ht.away==null?'-':ht.away)+')');
  if(events.length)   P.push('GOALS / CARDS / SUBS (events):\n'+fmtEvents(events));
  if(lineups.length)  P.push('LINEUPS & FORMATIONS:\n'+fmtLineups(lineups));
  if(stats.length)    P.push('TEAM STATISTICS (shots, possession, passes…):\n'+fmtStats(stats));
  if(pr)              P.push('MODEL PREDICTION: '+fmtPred(pr));
  if(injuries.length) P.push('INJURIES / SIDELINED:\n'+fmtInj(injuries));
  if(!events.length && !lineups.length && !stats.length){
    P.push('DATA AVAILABILITY: detailed events, lineups/formations and team statistics are NOT provided by API-Football for this fixture (it is likely not yet played, or this competition does not cover that data). Do not invent them.');
  }
  const lean=Object.assign({},f); delete lean.players; // drop the huge per-player array
  return {summary:P.join('\n\n'), raw:{fixture:lean, events, lineups, statistics:stats, predictions:pr, injuries}};
}

async function runAgent(){
  syncCards();
  const mode=$('mode').value,endpoint=endpointMap[mode];
  const q=$('question').value||'';
  addMsg(q||modeLabel(mode),'user');
  $('status').textContent=t('running');
  const typing=document.createElement('div');typing.className='typing';typing.innerHTML='<i></i><i></i><i></i>';$('chat').appendChild(typing);$('chat').scrollTop=$('chat').scrollHeight;
  let fid=$('fixture').value;
  /* No fixture but a Team ID → resolve that team's NEXT fixture (great for "predict the next match"). */
  if(!fid && $('team').value){
    try{
      const nx=await apiGet('fixtures',{team:parseInt($('team').value,10),next:1});
      const nf=(nx&&nx.response&&nx.response[0]);
      if(nf){ fid=String(nf.fixture.id); $('fixture').value=fid; syncCards(); }
    }catch(e){}
  }
  let ctxText='', apifootball=null;
  if(fid){
    try{
      const c=await buildFixtureContext(fid);
      if(c.summary){ ctxText='\n\n=== LIVE API-FOOTBALL MATCH DATA (ground truth) ===\n'+c.summary; apifootball=c.raw; }
    }catch(e){}
  }
  const payload={module:'WORLDCUP',lang,date:$('date').value,text:t('aiInstruction')+"\n\nUser request:\n"+q+ctxText};
  if(fid) payload.fixture=parseInt(fid,10);
  if($('team').value) payload.team=parseInt($('team').value,10);
  if(apifootball) payload.apifootball=apifootball;
  try{
    const res=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const raw=await res.text();typing.remove();
    let data;try{data=JSON.parse(raw);}catch(e){$('status').textContent=t('nonJson');addMsg(raw,'bot');$('rawJson').textContent=raw;return;}
    $('rawJson').textContent=JSON.stringify(data,null,2);
    if(data && data.ok===false){$('status').textContent=t('failed');addMsg(data.error||'Unknown error','bot');return;}
    $('status').textContent=t('done');addMsg((data&&(data.result||data.output))||JSON.stringify(data,null,2),'bot');
  }catch(e){typing.remove();$('status').textContent=t('error');addMsg(e.message,'bot');}
}
document.querySelectorAll('[data-theme-set]').forEach(b=>b.addEventListener('click',()=>setTheme(b.dataset.themeSet)));
document.querySelectorAll('[data-lang-set]').forEach(b=>b.addEventListener('click',()=>applyLang(b.dataset.langSet)));
document.querySelectorAll('[data-quick]').forEach(b=>b.addEventListener('click',()=>{const[m,k]=b.dataset.quick.split('|');$('mode').value=m;$('question').value=i18n[lang].quickText[k]||'';syncCards();}));
$('runBtn').addEventListener('click',runAgent);
$('clearBtn').addEventListener('click',clearOutput);
['mode','date','fixture','team'].forEach(id=>$(id).addEventListener('change',syncCards));

/* ===== Live API-Football data (via the secure same-origin proxy) ===== */
function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
let liveTab='fixtures';
async function apiGet(ep,params){
  const qs=new URLSearchParams(Object.assign({api:ep},params||{})).toString();
  const res=await fetch('worldcup_agent.php?'+qs,{headers:{'Accept':'application/json'}});
  return res.json();
}
function setApiDot(state){const d=$('apiDot');if(d)d.className='dot'+(state==='live'?' live':state==='off'?' off':'');}
function wcLeague(){return $('wcLeague').value||'<?= (int)WC_LEAGUE_ID ?>';}
function wcSeason(){return $('wcSeason').value||'<?= (int)WC_SEASON ?>';}

async function pingStatus(){
  try{const d=await apiGet('status');
    const sub=(d&&d.response&&d.response.subscription)||{};
    setApiDot((d&&d.ok===false)?'off':(sub.active?'live':'off'));
  }catch(_){setApiDot('off');}
}
async function loadStatus(){
  const box=$('liveBox');box.innerHTML='<div class="live-empty">'+t('liveLoading')+'</div>';
  try{
    const d=await apiGet('status');
    if(d&&d.ok===false){setApiDot('off');box.innerHTML='<div class="live-empty">'+esc(d.error||t('apiErr'))+'</div>';return;}
    const acc=(d&&d.response)||{},sub=acc.subscription||{},req=acc.requests||{},a=acc.account||{};
    setApiDot(sub.active?'live':'off');
    box.innerHTML='<div class="live-status">'
      +'<div class="chip"><span>'+t('plan')+':</span> '+esc(sub.plan||'-')+'</div>'
      +'<div class="chip"><span>'+t('quota')+':</span> '+esc((req.current!=null?req.current:'-')+' / '+(req.limit_day!=null?req.limit_day:'-'))+'</div>'
      +'<div class="chip"><span>'+t('account')+':</span> '+esc(((a.firstname||'')+' '+(a.lastname||'')).trim()||'-')+'</div>'
      +'</div>';
  }catch(e){setApiDot('off');box.innerHTML='<div class="live-empty">'+esc(e.message)+'</div>';}
}
async function loadFixtures(){
  const box=$('liveBox');box.innerHTML='<div class="live-empty">'+t('liveLoading')+'</div>';
  try{
    const when=($('wcWhen')&&$('wcWhen').value)||'next';
    const params={league:wcLeague(),season:wcSeason()}; params[when]=20;
    const d=await apiGet('fixtures',params);
    if(d&&d.ok===false){box.innerHTML='<div class="live-empty">'+esc(d.error||t('apiErr'))+'</div>';return;}
    const r=(d&&d.response)||[];
    if(!r.length){box.innerHTML='<div class="live-empty">'+t('noFixtures')+'</div>';return;}
    box.innerHTML='<div class="fx-list">'+r.map(f=>{
      const h=f.teams.home,a=f.teams.away,fx=f.fixture;
      const gh=(f.goals.home==null?'-':f.goals.home),ga=(f.goals.away==null?'-':f.goals.away);
      let when='';try{when=new Date(fx.date).toLocaleString(lang==='ar'?'ar':'en-GB',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'});}catch(_){when=fx.date||'';}
      return '<div class="fx" data-fid="'+fx.id+'" title="'+t('fixtureLabel')+': '+fx.id+'">'
        +'<div class="fx-team"><img src="'+teamLogo(h.id)+'" alt="" loading="lazy" onerror="this.style.visibility=\'hidden\'"><span>'+esc(h.name)+'</span></div>'
        +'<div class="fx-mid"><div class="fx-score">'+esc(gh)+' : '+esc(ga)+'</div><div class="fx-time">'+esc((fx.status&&fx.status.short)||'')+' · '+esc(when)+'</div></div>'
        +'<div class="fx-team away"><span>'+esc(a.name)+'</span><img src="'+teamLogo(a.id)+'" alt="" loading="lazy" onerror="this.style.visibility=\'hidden\'"></div>'
        +'</div>';
    }).join('')+'</div>';
    box.querySelectorAll('.fx').forEach(el=>el.addEventListener('click',()=>{
      $('fixture').value=el.getAttribute('data-fid');syncCards();
      $('fixture').scrollIntoView({behavior:'smooth',block:'center'});
    }));
  }catch(e){box.innerHTML='<div class="live-empty">'+esc(e.message)+'</div>';}
}
async function loadStandings(){
  const box=$('liveBox');box.innerHTML='<div class="live-empty">'+t('liveLoading')+'</div>';
  try{
    const d=await apiGet('standings',{league:wcLeague(),season:wcSeason()});
    if(d&&d.ok===false){box.innerHTML='<div class="live-empty">'+esc(d.error||t('apiErr'))+'</div>';return;}
    const lg=(d&&d.response&&d.response[0]&&d.response[0].league)||null;
    const groups=(lg&&lg.standings)||[];
    if(!groups.length){box.innerHTML='<div class="live-empty">'+t('noStandings')+'</div>';return;}
    box.innerHTML=groups.map(rows=>{
      const grp=(rows[0]&&rows[0].group)?('<h3 style="margin:14px 0 8px;font-size:13px;color:var(--accent)">'+esc(rows[0].group)+'</h3>'):'';
      return grp+'<table class="stbl"><thead><tr><th>#</th><th>'+t('team')+'</th><th>P</th><th>W</th><th>D</th><th>L</th><th>GD</th><th>Pts</th></tr></thead><tbody>'
        +rows.map(s=>{const al=s.all||{};
          return '<tr><td>'+esc(s.rank)+'</td><td><div class="tm"><img src="'+teamLogo(s.team.id)+'" alt="" loading="lazy" onerror="this.style.visibility=\'hidden\'">'+esc(s.team.name)+'</div></td>'
            +'<td>'+esc(al.played)+'</td><td>'+esc(al.win)+'</td><td>'+esc(al.draw)+'</td><td>'+esc(al.lose)+'</td>'
            +'<td>'+esc(s.goalsDiff)+'</td><td class="pts">'+esc(s.points)+'</td></tr>';
        }).join('')+'</tbody></table>';
    }).join('');
  }catch(e){box.innerHTML='<div class="live-empty">'+esc(e.message)+'</div>';}
}
function loadLive(){if(liveTab==='status')loadStatus();else if(liveTab==='standings')loadStandings();else loadFixtures();}
document.querySelectorAll('#liveTabs button').forEach(b=>b.addEventListener('click',()=>{
  liveTab=b.dataset.live;
  document.querySelectorAll('#liveTabs button').forEach(x=>x.classList.toggle('active',x===b));
  $('liveControls').style.display=(liveTab==='status')?'none':'flex';
  loadLive();
}));
$('liveRefresh').addEventListener('click',loadLive);

setTheme(theme);applyLang(lang);
pingStatus();loadLive();
</script>
</body>
</html>
