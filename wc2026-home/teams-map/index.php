<?php
/**
 * كأس العالم 2026 — الخريطة التفاعلية للمنتخبات المشاركة
 *
 * صفحة قائمة بذاتها بنفس هوية موقع CATRION: رأس موحّد، مبدّل الثيم (CATRION/السعودية)،
 * مبدّل اللغة (EN/عربي)، وأزرار التنقل. تعرض خريطة عالمية تفاعلية بعلامات المنتخبات،
 * والضغط على أي دولة يفتح لوحة جانبية بقصتها الكروية ونجومها ولحظاتها المميزة.
 *
 * البيانات من ../_teams_map.php (wc2026_countries مُخصّبة بالقصص)، مع الرجوع
 * إلى قاعدة البيانات ثم القائمة الكاملة لضمان عمل الصفحة دائماً.
 */

declare(strict_types=1);

/* Load the shared helpers if present. Guarded so the page never fatals on a
   partial deploy (e.g. a stale _teams_map.php missing wc_countries_nations). */
$wcShared = __DIR__ . '/../_teams_map.php';
if (is_file($wcShared)) { require_once $wcShared; }

/* Same minimal query helper used by the main pages (defined here so the
   standalone Teams-Map page can talk to the DB without pulling in auth). */
if (!function_exists('wc_rows')) {
    function wc_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array {
        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        if ($types && $params) $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) return [];
        $res = $stmt->get_result();
        return $res ? ($res->fetch_all(MYSQLI_ASSOC) ?: []) : [];
    }
}

/* Build the nation list defensively, every step guarded so a missing helper or
   file can only reduce the data — never blank the page:
   1) curated wc2026_countries() (with stories)  2) live DB fixtures
   3) full static list  4) inline build straight from data/countries.php. */
$nations = [];
if (function_exists('wc_countries_nations')) { $nations = wc_countries_nations(); }
if (!$nations) {
    $wcCfg = __DIR__ . '/../connections/config.php';
    if (is_file($wcCfg) && function_exists('wc_teams_map_nations')) {
        try {
            require_once $wcCfg; // expected to define $conn (mysqli)
            if (isset($conn) && $conn instanceof mysqli) {
                $nations = wc_teams_map_nations($conn);
            }
        } catch (Throwable $e) {
            $nations = [];
        }
    }
}
if (!$nations && function_exists('wc_tm_all_nations')) { $nations = wc_tm_all_nations(); }
if (!$nations) {
    /* Last resort: read the curated dataset directly, with no dependency on
       _teams_map.php at all, so the map always has its markers. */
    $cf = __DIR__ . '/data/countries.php';
    if (is_file($cf)) { require_once $cf; }
    if (function_exists('wc2026_countries')) {
        foreach (wc2026_countries() as $code => $c) {
            $ll = $c['coords'] ?? null;
            if (!$ll || !isset($ll[0], $ll[1])) continue;
            $nations[] = [
                'name'    => (string)($c['name_en'] ?? $code),
                'nameAr'  => (string)($c['name_ar'] ?? ''),
                'code'    => strtolower((string)($c['flag'] ?? $code)),
                'lat'     => (float)$ll[0],
                'lng'     => (float)$ll[1],
                'confed'  => (string)($c['confed'] ?? ''),
                'group'   => (string)($c['group'] ?? ''),
                'host'    => !empty($c['host']),
                'story'   => '', 'storyAr' => '', 'stars' => [], 'moments' => [],
            ];
        }
        usort($nations, fn($a, $b) => strcmp($a['name'], $b['name']));
    }
}

/* Confederation region labels (English markup; JS swaps to Arabic) */
$confedEn = [
    'UEFA'     => 'Europe',
    'CONMEBOL' => 'South America',
    'CONCACAF' => 'N. & C. America',
    'CAF'      => 'Africa',
    'AFC'      => 'Asia',
    'OFC'      => 'Oceania',
];
$confedOrder = ['UEFA','CONMEBOL','CONCACAF','CAF','AFC','OFC'];
$counts = array_count_values(array_column($nations, 'confed'));
$nationsJson = json_encode($nations, JSON_UNESCAPED_UNICODE);
$logoPath = '/WC2026/partials/CATRION%20logo.png';
?>
<!DOCTYPE html>
<html lang="en" dir="ltr" data-theme="catrion">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CATRION · World Cup 2026 — Interactive Teams Map</title>
    <meta name="description" content="Interactive map of the FIFA World Cup 2026 nations: each team's football story, key players and standout moments.">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <style>
        :root{
            --bg1:#061A36;--bg2:#08254D;--bg3:#0A3A76;
            --bar1:#06182f;--bar2:#0b2c55;
            --ink:#eaf6ff;--muted:rgba(234,244,255,.72);
            --accent:#A8E7FF;--accent2:#3A8BF6;--gold:#F5C85B;
            --line:rgba(168,231,255,.20);--surface:#0b2c55;--deep:#0B2C55;
            --chip:#A8E7FF;--pin:#0b2c55;--btn:#0e63e6;
        }
        html[data-theme="saudi"]{
            --bg1:#03190f;--bg2:#06291a;--bg3:#0a3f27;
            --bar1:#04211a;--bar2:#06371f;
            --accent:#7EF4AE;--accent2:#22C55E;--gold:#FFE19A;
            --line:rgba(126,244,174,.22);--surface:#06291a;--deep:#06371f;
            --chip:#7EF4AE;--pin:#06291a;--btn:#1FB573;
        }
        *{box-sizing:border-box}
        html{-webkit-text-size-adjust:100%;text-size-adjust:100%}
        body{margin:0;font-family:'Inter','Cairo',system-ui,sans-serif;min-height:100vh;color:var(--ink);overflow-x:hidden;
            display:flex;flex-direction:column;
            background:radial-gradient(circle at 100% 0%,rgba(14,99,230,.18),transparent 38%),linear-gradient(135deg,var(--bg1),var(--bg2) 58%,var(--bg3))}
        html[dir="rtl"] body{font-family:'Cairo','Inter',system-ui,sans-serif}
        a{text-decoration:none}

        /* ===== Header (matches CATRION site) ===== */
        .topbar{display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:12px 20px;
            background:linear-gradient(120deg,var(--bar1),var(--bar2));border-bottom:1px solid var(--line);
            position:sticky;top:0;z-index:1500}
        .brand{display:flex;align-items:center;gap:11px;min-width:0}
        .brand-logo{height:40px;width:auto;display:block;border-radius:8px}
        .brand-trophy{font-size:26px;display:none}
        .brand-text h1{margin:0;font-size:16px;font-weight:900;color:#fff;letter-spacing:-.3px;white-space:nowrap}
        .brand-text p{margin:1px 0 0;font-size:11.5px;color:var(--muted);font-weight:700}
        .top-actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-inline-start:auto}

        .theme-switch,.lang-switch{display:inline-flex;gap:4px;padding:4px;border-radius:999px;
            background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.22)}
        .theme-btn,.lang-btn{border:0;border-radius:999px;padding:8px 12px;background:transparent;color:#fff;
            font-weight:900;font-size:12px;cursor:pointer;font-family:inherit;line-height:1;transition:.2s}
        .theme-btn.active,.lang-btn.active{background:#fff;color:var(--deep)}
        html[data-theme="saudi"] .theme-btn.active,html[data-theme="saudi"] .lang-btn.active{color:#06371f}

        .top-link{display:inline-flex;align-items:center;gap:6px;padding:9px 14px;border-radius:999px;
            background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.18);color:#fff;
            font-weight:800;font-size:12.5px;cursor:pointer;font-family:inherit;transition:.18s}
        .top-link:hover{background:rgba(255,255,255,.18)}
        .top-link.active{background:var(--accent);border-color:var(--accent);color:var(--deep)}
        html[data-theme="saudi"] .top-link.active{color:#06371f}

        .hosts{display:none;align-items:center;gap:6px;font-size:12px;font-weight:800;color:var(--muted)}
        .host-flag{width:24px;height:16px;object-fit:cover;border-radius:3px;box-shadow:0 2px 6px rgba(0,0,0,.4)}
        @media(min-width:1100px){.hosts{display:inline-flex}}

        /* Mobile burger menu (like the main site): header keeps logo + search;
           theme/language/nav move into a slide-out drawer. */
        .tm-burger{display:none;width:42px;height:42px;flex:none;border:1px solid rgba(255,255,255,.22);
            background:rgba(255,255,255,.12);color:#fff;border-radius:12px;cursor:pointer;align-items:center;justify-content:center}
        .tm-burger svg{width:22px;height:22px}
        .tm-backdrop{position:fixed;inset:0;z-index:1990;background:rgba(4,12,28,.55);opacity:0;visibility:hidden;transition:.25s}
        .tm-backdrop.show{opacity:1;visibility:visible}
        @media(max-width:760px){
            .topbar{padding:10px 14px;gap:10px;flex-wrap:nowrap}
            .brand-logo{height:34px}
            .brand-text{display:none}
            .searchbox{order:2;flex:1 1 auto;max-width:none;min-width:0}
            .tm-burger{display:inline-flex;order:3}
            .hosts{display:none !important}
            .top-actions{position:fixed;top:0;inset-inline-end:0;height:100vh;height:100dvh;width:min(80vw,300px);margin:0;
                flex-direction:column;align-items:stretch;gap:10px;overflow-y:auto;-webkit-overflow-scrolling:touch;
                background:#0c1830;border-inline-start:1px solid var(--line);padding:66px 14px 24px;
                box-shadow:-24px 0 60px rgba(0,0,0,.55);transform:translateX(105%);transition:transform .28s ease;z-index:2000}
            html[dir="rtl"] .top-actions{box-shadow:24px 0 60px rgba(0,0,0,.55);transform:translateX(-105%)}
            .top-actions.open{transform:none}
            html.tm-nav-open .topbar{z-index:2002}
            html.tm-nav-open .tm-burger{display:none}
            .top-actions .theme-switch,.top-actions .lang-switch{justify-content:center}
            .top-actions .top-link{width:100%;justify-content:center;text-align:center}
            .stage{min-height:56vh}
        }

        .searchbox{position:relative;flex:1;min-width:180px;max-width:300px;order:5}
        #search{width:100%;padding:9px 16px;border-radius:999px;border:1px solid var(--line);
            background:rgba(255,255,255,.08);color:#fff;font-family:inherit;font-weight:700;font-size:13px}
        #search::placeholder{color:rgba(255,255,255,.5)}
        .search-results{position:absolute;inset-inline:0;top:46px;margin:0;padding:6px;list-style:none;
            background:var(--surface);border:1px solid var(--line);border-radius:14px;max-height:320px;overflow:auto;
            z-index:1600;box-shadow:0 18px 40px rgba(0,0,0,.5)}
        .search-results li{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:10px;cursor:pointer;font-weight:800;font-size:13px}
        .search-results li:hover{background:rgba(168,231,255,.12)}
        .search-results img{width:24px;height:16px;object-fit:cover;border-radius:3px}

        /* ===== Filters ===== */
        .filters{display:flex;gap:8px;flex-wrap:wrap;padding:12px 20px;background:rgba(6,24,47,.55);border-bottom:1px solid var(--line)}
        .chip{display:inline-flex;align-items:center;gap:7px;padding:7px 14px;border-radius:999px;border:1px solid var(--line);
            background:rgba(255,255,255,.06);color:var(--ink);font-family:inherit;font-weight:800;font-size:12.5px;cursor:pointer;transition:.18s}
        .chip:hover{border-color:var(--accent)}
        .chip.is-active{background:var(--chip);color:var(--deep);border-color:var(--chip)}
        html[data-theme="saudi"] .chip.is-active{color:#06371f}
        .chip small{opacity:.7;font-weight:800}
        .dot{width:9px;height:9px;border-radius:50%}
        .dot-uefa{background:#3a8bf6}.dot-conmebol{background:#f5c85b}.dot-concacaf{background:#7ef4ae}
        .dot-caf{background:#ff8b5b}.dot-afc{background:#e94747}.dot-ofc{background:#b78bff}

        /* ===== Map stage ===== */
        .stage{position:relative;flex:1 1 auto;min-height:460px;overflow:hidden}
        /* Footer — matches the CATRION site */
        .tm-footer{padding:15px 20px;background:linear-gradient(120deg,var(--bar1),var(--bar2));border-top:1px solid var(--line);
            display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}
        .tm-footer b{font-size:12.5px;font-weight:900;color:#fff}
        .tm-footer .tm-foot-note{font-size:11.5px;color:var(--muted);font-weight:700}
        @media(max-width:680px){.tm-footer{flex-direction:column;text-align:center;gap:8px}}
        #map{position:absolute;inset:0;z-index:1}
        .leaflet-container{background:radial-gradient(120% 120% at 50% 0%,#123a6b 0%,#0a1f3e 55%,#06152c 100%)}
        html[data-theme="saudi"] .leaflet-container{background:radial-gradient(120% 120% at 50% 0%,#0a3f27 0%,#06291a 55%,#03190f 100%)}
        .leaflet-control-attribution{background:rgba(6,26,54,.7);color:rgba(255,255,255,.55)}
        .leaflet-control-attribution a{color:var(--accent)}
        .tm-pin{display:block;width:30px;height:30px;border:2px solid rgba(255,255,255,.9);border-radius:50%;
            overflow:hidden;background:var(--pin);box-shadow:0 3px 10px rgba(0,0,0,.5)}
        .tm-pin img{width:100%;height:100%;object-fit:cover;display:block}

        /* ===== Detail panel ===== */
        .panel{position:absolute;inset-inline-end:0;top:0;height:100%;width:min(390px,92vw);background:var(--surface);
            border-inline-start:1px solid var(--line);box-shadow:-24px 0 60px rgba(0,0,0,.5);z-index:1200;
            transform:translateX(105%);transition:transform .3s ease;overflow-y:auto;padding:22px}
        html[dir="rtl"] .panel{transform:translateX(-105%)}
        .panel.open{transform:none !important}
        .panel-close{position:absolute;top:14px;inset-inline-start:14px;width:36px;height:36px;border-radius:50%;
            border:1px solid var(--line);background:rgba(255,255,255,.08);color:#fff;font-size:22px;line-height:1;cursor:pointer}
        .p-flag{width:92px;height:61px;object-fit:cover;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,.45);margin-bottom:12px}
        .p-name{font-size:24px;font-weight:900;margin:0 0 6px;color:#fff}
        .p-confed{display:inline-block;font-size:12px;font-weight:800;color:var(--deep);background:var(--accent);border-radius:999px;padding:3px 12px;margin-bottom:14px}
        html[data-theme="saudi"] .p-confed{color:#06371f}
        .p-story{font-size:14px;line-height:1.7;color:rgba(255,255,255,.88);font-weight:600;margin:0 0 16px}
        .p-wc{display:flex;align-items:center;gap:11px;margin:0 0 18px;padding:11px 14px;border-radius:14px;
            background:rgba(245,200,91,.10);border:1px solid rgba(245,200,91,.28);font-size:13px;font-weight:800;color:#fff;line-height:1.5}
        html[data-theme="saudi"] .p-wc{background:rgba(255,225,154,.10);border-color:rgba(255,225,154,.30)}
        .p-wc-ico{font-size:22px;flex:none}
        .p-wc-lbl{display:block;font-size:11px;font-weight:900;color:var(--gold);letter-spacing:.02em;margin-bottom:2px}
        .p-sec{margin:0 0 18px}
        .p-lbl{display:block;font-size:13px;font-weight:900;color:var(--gold);margin-bottom:8px}
        .p-chips{display:flex;flex-wrap:wrap;gap:7px}
        .p-chip{font-size:12.5px;font-weight:800;color:#eaf6ff;background:rgba(168,231,255,.14);border:1px solid var(--line);border-radius:999px;padding:4px 12px}
        .p-list{margin:0;padding-inline-start:18px;color:rgba(255,255,255,.85);font-weight:600;font-size:13.5px;line-height:1.8}
        .p-fixtures{display:inline-block;margin-top:4px;padding:10px 18px;border-radius:999px;background:var(--btn);color:#fff;font-weight:800;font-size:13px}

        /* ===== Welcome hint ===== */
        .hint{position:absolute;inset:0;display:grid;place-items:center;z-index:900;pointer-events:none;padding:20px}
        .hint-card{background:rgba(11,44,85,.92);border:1px solid var(--line);border-radius:20px;padding:26px 30px;max-width:430px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.45)}
        html[data-theme="saudi"] .hint-card{background:rgba(6,41,26,.92)}
        .hint-emoji{font-size:40px}
        .hint-card h2{margin:8px 0 6px;font-size:22px;color:#fff}
        .hint-card p{margin:0;font-size:14px;color:rgba(255,255,255,.78);line-height:1.7;font-weight:600}

        /* ===== Mobile-friendly typography ===== */
        @media(max-width:560px){
            /* 16px search input avoids iOS auto-zoom on focus */
            #search{font-size:16px;padding:10px 14px}
            .chip{font-size:12px;padding:6px 12px}
            .chip small{font-size:11px}
            .top-link{font-size:13px}
            .theme-btn,.lang-btn{font-size:12px}
            /* detail panel — scale down the larger headings/text */
            .panel{padding:18px;width:min(360px,94vw)}
            .p-flag{width:80px;height:53px}
            .p-name{font-size:20px}
            .p-confed{font-size:11px}
            .p-story{font-size:13px;line-height:1.65}
            .p-wc{font-size:12.5px;padding:10px 12px}
            .p-wc-ico{font-size:20px}
            .p-lbl{font-size:12px}
            .p-chip{font-size:12px}
            .p-list{font-size:12.5px;line-height:1.75}
            .p-fixtures{font-size:12.5px;padding:9px 16px}
            /* welcome hint */
            .hint-card{padding:20px 22px;max-width:340px}
            .hint-emoji{font-size:34px}
            .hint-card h2{font-size:18px}
            .hint-card p{font-size:13px;line-height:1.6}
            /* footer */
            .tm-footer b{font-size:11.5px}
            .tm-footer .tm-foot-note{font-size:11px}
        }
    </style>
</head>
<body>

<header class="topbar">
    <div class="brand">
        <img class="brand-logo" src="<?= $logoPath ?>" alt="CATRION" onerror="this.style.display='none';this.nextElementSibling.style.display='inline'">
        <span class="brand-trophy">🏆</span>
        <div class="brand-text">
            <h1>CATRION · World Cup 2026</h1>
            <p><span id="brandSub" data-i18n="brandSub"><?= count($nations) ?> teams on the interactive map</span></p>
        </div>
    </div>

    <div class="searchbox">
        <input type="search" id="search" autocomplete="off" data-i18n-ph="searchPh" placeholder="Search a team…" aria-label="Search a team">
        <ul id="search-results" class="search-results" hidden></ul>
    </div>

    <button type="button" class="tm-burger" id="tmBurger" aria-label="Menu" aria-expanded="false">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </button>

    <div class="top-actions">
        <div class="hosts" title="Hosts">
            <span data-i18n="hostLabel">Hosts:</span>
            <img class="host-flag" src="https://flagcdn.com/w40/ca.png" alt="Canada" title="Canada">
            <img class="host-flag" src="https://flagcdn.com/w40/mx.png" alt="Mexico" title="Mexico">
            <img class="host-flag" src="https://flagcdn.com/w40/us.png" alt="United States" title="United States">
        </div>
        <div class="theme-switch" role="group" aria-label="Theme">
            <button type="button" class="theme-btn" data-theme-set="catrion" data-i18n="themeCatrion">CATRION</button>
            <button type="button" class="theme-btn" data-theme-set="saudi" data-i18n="themeSaudi">Saudi</button>
        </div>
        <div class="lang-switch" role="group" aria-label="Language">
            <button type="button" class="lang-btn" data-lang-set="en">EN</button>
            <button type="button" class="lang-btn" data-lang-set="ar">عربي</button>
        </div>
        <a href="/WC2026/" class="top-link" data-i18n="navHome">Home</a>
        <a href="/WC2026/matches" class="top-link" data-i18n="navMatches">Matches</a>
        <a href="/WC2026/teams-map/" class="top-link active" data-i18n="navTeamsMap">Teams Map</a>
    </div>
</header>

<div class="filters" id="filters">
    <button class="chip is-active" data-confed="ALL"><span data-i18n="filterAll">All</span> <small><?= count($nations) ?></small></button>
    <?php foreach ($confedOrder as $key): $n = $counts[$key] ?? 0; if (!$n) continue; ?>
        <button class="chip" data-confed="<?= $key ?>"><span class="dot dot-<?= strtolower($key) ?>"></span><span data-i18n="conf<?= $key ?>"><?= $confedEn[$key] ?></span> <small><?= $n ?></small></button>
    <?php endforeach; ?>
</div>

<main class="stage">
    <div id="map" aria-label="Interactive world map"></div>

    <aside class="panel" id="panel" aria-hidden="true">
        <button class="panel-close" id="panel-close" aria-label="Close">&times;</button>
        <div class="panel-body" id="panel-body"></div>
    </aside>

    <div class="hint" id="hint">
        <div class="hint-card">
            <span class="hint-emoji">🗺️</span>
            <h2 data-i18n="hintTitle">Tap any country</h2>
            <p data-i18n="hintText">Explore the nations of the 2026 World Cup — each team's football story, key players and standout moments.</p>
        </div>
    </div>
</main>

<footer class="tm-footer">
    <b>Developed by CATRION &copy; IT Digital &amp; Transformation</b>
    <span class="tm-foot-note" data-i18n="footerNote">CATRION FIFA World Cup 2026 Challenge</span>
</footer>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
(function(){
    var NATIONS = <?= $nationsJson ?>;
    var TEAM_COUNT = NATIONS.length;
    var root = document.documentElement, d = document;
    function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }

    /* ---------- i18n (EN / AR) ---------- */
    var T = {
      en:{
        brandSub:TEAM_COUNT+' teams on the interactive map', hostLabel:'Hosts:', searchPh:'Search a team…',
        navHome:'Home', navMatches:'Matches', navTeamsMap:'Teams Map',
        themeCatrion:'CATRION', themeSaudi:'Saudi', filterAll:'All',
        confUEFA:'Europe', confCONMEBOL:'South America', confCONCACAF:'N. & C. America', confCAF:'Africa', confAFC:'Asia', confOFC:'Oceania',
        hintTitle:'Tap any country', hintText:"Explore the nations of the 2026 World Cup — each team's football story, key players and standout moments.",
        stars:'★ Key players', moments:'🏆 Key moments', wcRecord:'World Cup record', didyouknow:'💡 Did you know?', fixtures:'View fixtures →', soon:'Full team profile coming soon.',
        group:'Group', host:'Host', footerNote:'CATRION FIFA World Cup 2026 Challenge'
      },
      ar:{
        brandSub:TEAM_COUNT+' منتخباً على الخريطة التفاعلية', hostLabel:'المستضيف:', searchPh:'ابحث عن منتخب…',
        navHome:'الرئيسية', navMatches:'المباريات', navTeamsMap:'خريطة المنتخبات',
        themeCatrion:'CATRION', themeSaudi:'السعودية', filterAll:'الكل',
        confUEFA:'أوروبا', confCONMEBOL:'أمريكا الجنوبية', confCONCACAF:'أمريكا الشمالية والوسطى', confCAF:'أفريقيا', confAFC:'آسيا', confOFC:'أوقيانوسيا',
        hintTitle:'اضغط على أي دولة', hintText:'استكشف منتخبات كأس العالم 2026 — قصة كل منتخب الكروية، وأبرز نجومه، ولحظاته المميزة.',
        stars:'★ أبرز النجوم', moments:'🏆 لحظات مميزة', wcRecord:'سجل كأس العالم', didyouknow:'💡 هل تعلم؟', fixtures:'عرض المباريات ←', soon:'سيتوفر ملف هذا المنتخب قريباً.',
        group:'المجموعة', host:'مستضيف', footerNote:'تحدي كاتريون لكأس العالم 2026'
      }
    };
    var lang = (function(){ try{ return localStorage.getItem('wc_lang')||'en'; }catch(e){ return 'en'; } })();
    function tr(k){ return (T[lang]&&T[lang][k]!=null)?T[lang][k]:(T.en[k]!=null?T.en[k]:k); }
    function nmOf(n){ return (lang==='ar' && n.nameAr) ? n.nameAr : n.name; }

    function applyLang(l){
        lang = (l==='ar')?'ar':'en';
        root.lang = lang; root.dir = (lang==='ar')?'rtl':'ltr';
        d.querySelectorAll('[data-i18n]').forEach(function(el){ var k=el.getAttribute('data-i18n'); if(T.en[k]!=null) el.textContent=tr(k); });
        d.querySelectorAll('[data-i18n-ph]').forEach(function(el){ var k=el.getAttribute('data-i18n-ph'); if(T.en[k]!=null) el.setAttribute('placeholder',tr(k)); });
        d.querySelectorAll('.lang-btn').forEach(function(b){ b.classList.toggle('active', b.getAttribute('data-lang-set')===lang); });
        var bs=d.getElementById('brandSub'); if(bs) bs.textContent=tr('brandSub');
        try{ localStorage.setItem('wc_lang', lang); }catch(e){}
        if(currentNation) openPanel(currentNation); /* re-render open panel in new language */
    }
    d.querySelectorAll('.lang-btn').forEach(function(b){ b.addEventListener('click', function(){ applyLang(b.getAttribute('data-lang-set')); }); });

    /* ---------- theme (CATRION / Saudi), shared with the main site ---------- */
    var theme = (function(){ try{ return localStorage.getItem('wc_theme')||'catrion'; }catch(e){ return 'catrion'; } })();
    function applyTheme(t){
        theme = (t==='saudi')?'saudi':'catrion';
        root.setAttribute('data-theme', theme);
        d.querySelectorAll('.theme-btn').forEach(function(b){ b.classList.toggle('active', b.getAttribute('data-theme-set')===theme); });
        try{ localStorage.setItem('wc_theme', theme); }catch(e){}
    }
    d.querySelectorAll('.theme-btn').forEach(function(b){ b.addEventListener('click', function(){ applyTheme(b.getAttribute('data-theme-set')); }); });

    /* ---------- map ---------- */
    /* If the Leaflet CDN fails to load, keep the page usable (header, theme +
       language toggles) instead of crashing on an undefined L. */
    if (typeof L === 'undefined') {
        var mEl=d.getElementById('map');
        if(mEl) mEl.innerHTML='<div style="display:grid;place-items:center;height:100%;padding:24px;text-align:center;color:#cfe6ff;font-weight:800;line-height:1.7">⚠️<br>Map library could not load.<br>Please check your connection and refresh.</div>';
        applyTheme(theme); applyLang(lang);
        return;
    }
    var map = L.map('map',{zoomControl:true,scrollWheelZoom:true,worldCopyJump:true}).setView([25,10],2);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',{
        maxZoom:9,minZoom:1,
        errorTileUrl:'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==',
        attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>'
    }).addTo(map);

    var panel=d.getElementById('panel'), body=d.getElementById('panel-body'), hint=d.getElementById('hint');
    var markers={}, bounds=[], currentNation=null;

    function openPanel(n){
        currentNation=n;
        var ar=(lang==='ar');
        var meta=[]; if(n.confed) meta.push(esc(n.confed)); if(n.group) meta.push(tr('group')+' '+esc(n.group)); if(n.host) meta.push(tr('host'));
        var story=ar ? (n.storyAr || n.story) : (n.story || n.storyAr);
        var h='';
        h+='<img class="p-flag" src="https://flagcdn.com/w160/'+esc(n.code)+'.png" alt="">';
        h+='<h2 class="p-name">'+esc(nmOf(n))+'</h2>';
        if(meta.length) h+='<span class="p-confed">'+meta.join(' · ')+'</span>';
        h+='<p class="p-story">'+esc(story||tr('soon'))+'</p>';
        var wcRec=ar ? (n.wcAr || n.wc) : (n.wc || n.wcAr);
        if(wcRec){ h+='<div class="p-wc"><span class="p-wc-ico">🏆</span><div><span class="p-wc-lbl">'+tr('wcRecord')+'</span>'+esc(wcRec)+'</div></div>'; }
        if(n.stars && n.stars.length){
            h+='<div class="p-sec"><span class="p-lbl">'+tr('stars')+'</span><div class="p-chips">';
            n.stars.forEach(function(s){ h+='<span class="p-chip">'+esc(s)+'</span>'; });
            h+='</div></div>';
        }
        if(n.moments && n.moments.length){
            h+='<div class="p-sec"><span class="p-lbl">'+tr('moments')+'</span><ul class="p-list">';
            n.moments.forEach(function(s){ h+='<li>'+esc(s)+'</li>'; });
            h+='</ul></div>';
        }
        var fcts=ar ? (n.factsAr && n.factsAr.length ? n.factsAr : n.facts) : (n.facts && n.facts.length ? n.facts : n.factsAr);
        if(fcts && fcts.length){
            h+='<div class="p-sec"><span class="p-lbl">'+tr('didyouknow')+'</span><ul class="p-list">';
            fcts.forEach(function(s){ h+='<li>'+esc(s)+'</li>'; });
            h+='</ul></div>';
        }
        h+='<a class="p-fixtures" href="/WC2026/matches?q='+encodeURIComponent(n.name)+'">'+tr('fixtures')+'</a>';
        body.innerHTML=h;
        panel.classList.add('open'); panel.setAttribute('aria-hidden','false');
        if(hint) hint.style.display='none';
    }
    d.getElementById('panel-close').addEventListener('click',function(){
        panel.classList.remove('open'); panel.setAttribute('aria-hidden','true'); currentNation=null;
    });

    NATIONS.forEach(function(n){
        var icon=L.divIcon({className:'tm-pin-wrap',
            html:'<span class="tm-pin"><img src="https://flagcdn.com/w40/'+esc(n.code)+'.png" alt="" loading="lazy"></span>',
            iconSize:[30,30],iconAnchor:[15,15]});
        var m=L.marker([n.lat,n.lng],{icon:icon,title:nmOf(n)}).addTo(map);
        m.on('click',function(){ openPanel(n); map.flyTo([n.lat,n.lng],Math.max(map.getZoom(),4),{duration:.6}); });
        markers[n.code]=m;
        bounds.push([n.lat,n.lng]);
    });
    if(bounds.length) map.fitBounds(bounds,{padding:[40,40],maxZoom:4});

    /* ---------- confederation filter ---------- */
    d.getElementById('filters').addEventListener('click',function(e){
        var btn=e.target.closest('.chip'); if(!btn) return;
        d.querySelectorAll('.chip').forEach(function(c){ c.classList.remove('is-active'); });
        btn.classList.add('is-active');
        var conf=btn.getAttribute('data-confed');
        NATIONS.forEach(function(n){
            var m=markers[n.code]; if(!m) return;
            var show=(conf==='ALL'||n.confed===conf);
            if(show){ if(!map.hasLayer(m)) m.addTo(map); } else if(map.hasLayer(m)) map.removeLayer(m);
        });
    });

    /* ---------- search ---------- */
    var input=d.getElementById('search'), results=d.getElementById('search-results');
    input.addEventListener('input',function(){
        var q=input.value.trim().toLowerCase();
        if(!q){ results.hidden=true; results.innerHTML=''; return; }
        var hits=NATIONS.filter(function(n){ return nmOf(n).toLowerCase().indexOf(q)>-1 || (n.name||'').toLowerCase().indexOf(q)>-1; }).slice(0,8);
        if(!hits.length){ results.hidden=true; results.innerHTML=''; return; }
        results.innerHTML=hits.map(function(n){
            return '<li data-code="'+esc(n.code)+'"><img src="https://flagcdn.com/w40/'+esc(n.code)+'.png" alt="">'+esc(nmOf(n))+'</li>';
        }).join('');
        results.hidden=false;
    });
    results.addEventListener('click',function(e){
        var li=e.target.closest('li'); if(!li) return;
        var code=li.getAttribute('data-code');
        var n=NATIONS.filter(function(x){ return x.code===code; })[0];
        if(n){ openPanel(n); map.flyTo([n.lat,n.lng],4,{duration:.7}); }
        results.hidden=true; input.value=n?nmOf(n):'';
    });
    d.addEventListener('click',function(e){ if(!e.target.closest('.searchbox')) results.hidden=true; });

    /* ---------- init ---------- */
    applyTheme(theme);
    applyLang(lang);
    setTimeout(function(){ map.invalidateSize(); },200);
    window.addEventListener('resize',function(){ map.invalidateSize(); });
})();

/* Mobile burger drawer (separate IIFE so it works even if the map fails to load) */
(function(){
    var d=document, root=d.documentElement;
    var b=d.getElementById('tmBurger'), a=d.querySelector('.top-actions');
    if(!b||!a) return;
    var bd=d.createElement('div'); bd.className='tm-backdrop'; d.body.appendChild(bd);
    function setOpen(open){ a.classList.toggle('open',open); bd.classList.toggle('show',open); root.classList.toggle('tm-nav-open',open); b.setAttribute('aria-expanded',open?'true':'false'); }
    b.addEventListener('click',function(e){ e.stopPropagation(); setOpen(!a.classList.contains('open')); });
    bd.addEventListener('click',function(){ setOpen(false); });
    d.addEventListener('keydown',function(e){ if(e.key==='Escape') setOpen(false); });
    /* close on nav-link click (theme/language toggles keep the drawer open) */
    a.querySelectorAll('a').forEach(function(el){ el.addEventListener('click',function(){ setOpen(false); }); });
})();
</script>
</body>
</html>
