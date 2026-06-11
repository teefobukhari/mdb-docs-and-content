<?php
/**
 * كأس العالم 2026 — الخريطة التفاعلية للمنتخبات المشاركة
 *
 * صفحة قائمة بذاتها: تعرض خريطة عالم تفاعلية بعلامات الدول المتأهلة،
 * والضغط على أي دولة يفتح لوحة جانبية بقصتها الكروية وأبرز نجومها ولحظاتها المميزة.
 *
 * تعتمد على المصدر المشترك ../_teams_map.php (الذي يضم ../teams_data.php).
 * تُجلب المنتخبات المتأهلة فعلياً من قاعدة البيانات (wc_fixtures) عند توفّرها،
 * مع الرجوع إلى القائمة الكاملة لضمان عمل الصفحة دائماً.
 */

declare(strict_types=1);
require_once __DIR__ . '/../_teams_map.php';

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

/* Data source: the curated wc2026_countries() dataset (data/countries.php) —
   Arabic + English names, flags, confederation, group, host — enriched with
   each team's story/stars/moments. Falls back to the real DB fixtures, then to
   the full static list, so the page always shows data. */
$nations = wc_countries_nations();
if (!$nations) {
    $wcCfg = __DIR__ . '/../connections/config.php';
    if (is_file($wcCfg)) {
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
if (!$nations) { $nations = wc_tm_all_nations(); }

$confedNames = [
    'UEFA'     => 'أوروبا',
    'CONMEBOL' => 'أمريكا الجنوبية',
    'CONCACAF' => 'أمريكا الشمالية والوسطى',
    'CAF'      => 'أفريقيا',
    'AFC'      => 'آسيا',
    'OFC'      => 'أوقيانوسيا',
];
$counts = array_count_values(array_column($nations, 'confed'));
$nationsJson = json_encode($nations, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>كأس العالم 2026 — الخريطة التفاعلية للمنتخبات</title>
    <meta name="description" content="خريطة تفاعلية لمنتخبات كأس العالم 2026: بيانات كل دولة وتاريخها الكروي، أبرز نجومها ولحظاتهم المميزة.">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <style>
        :root{--navy:#06182f;--navy2:#0b2c55;--ink:#eaf6ff;--gold:#f5c85b;--sky:#a8e7ff}
        *{box-sizing:border-box}
        body{margin:0;font-family:'Cairo',system-ui,sans-serif;background:var(--navy);color:var(--ink);min-height:100vh}
        .topbar{display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:14px 20px;background:linear-gradient(120deg,#06182f,#0b2c55);border-bottom:1px solid rgba(168,231,255,.15)}
        .brand{display:flex;align-items:center;gap:12px}
        .brand-trophy{font-size:30px}
        .brand-text h1{margin:0;font-size:20px;font-weight:900;color:#fff}
        .brand-text p{margin:2px 0 0;font-size:12px;color:rgba(255,255,255,.7);font-weight:700}
        .hosts{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:800;color:rgba(255,255,255,.8)}
        .host-flag{width:26px;height:18px;object-fit:cover;border-radius:3px;box-shadow:0 2px 6px rgba(0,0,0,.4)}
        .searchbox{position:relative;flex:1;min-width:200px;max-width:340px}
        #search{width:100%;padding:9px 14px;border-radius:999px;border:1px solid rgba(168,231,255,.25);background:rgba(255,255,255,.08);color:#fff;font-family:inherit;font-weight:700;font-size:13px}
        #search::placeholder{color:rgba(255,255,255,.5)}
        .search-results{position:absolute;inset-inline:0;top:46px;margin:0;padding:6px;list-style:none;background:#0b2c55;border:1px solid rgba(168,231,255,.22);border-radius:14px;max-height:300px;overflow:auto;z-index:1200;box-shadow:0 18px 40px rgba(0,0,0,.5)}
        .search-results li{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:10px;cursor:pointer;font-weight:800;font-size:13px}
        .search-results li:hover{background:rgba(168,231,255,.12)}
        .search-results img{width:24px;height:16px;object-fit:cover;border-radius:3px}
        .back-home{margin-inline-start:auto;padding:8px 14px;border-radius:999px;background:#0e63e6;color:#fff;text-decoration:none;font-weight:800;font-size:13px;white-space:nowrap}
        .filters{display:flex;gap:8px;flex-wrap:wrap;padding:12px 20px;background:#081f3c;border-bottom:1px solid rgba(168,231,255,.1)}
        .chip{display:inline-flex;align-items:center;gap:7px;padding:7px 14px;border-radius:999px;border:1px solid rgba(168,231,255,.22);background:rgba(255,255,255,.06);color:#dff1ff;font-family:inherit;font-weight:800;font-size:12.5px;cursor:pointer;transition:.18s}
        .chip:hover{border-color:var(--sky)}
        .chip.is-active{background:var(--sky);color:#06202e;border-color:var(--sky)}
        .chip small{opacity:.7;font-weight:800}
        .dot{width:9px;height:9px;border-radius:50%}
        .dot-uefa{background:#3a8bf6}.dot-conmebol{background:#f5c85b}.dot-concacaf{background:#7ef4ae}.dot-caf{background:#ff8b5b}.dot-afc{background:#e94747}.dot-ofc{background:#b78bff}
        .stage{position:relative;height:calc(100vh - 132px);min-height:460px}
        #map{position:absolute;inset:0;z-index:1;background:#0a1b30}
        .leaflet-container{background:#0a1b30}
        .leaflet-control-attribution{background:rgba(6,26,54,.7);color:rgba(255,255,255,.55)}
        .leaflet-control-attribution a{color:rgba(168,231,255,.8)}
        .tm-pin{display:block;width:30px;height:30px;border:2px solid rgba(255,255,255,.9);border-radius:50%;overflow:hidden;background:#0b2c55;box-shadow:0 3px 10px rgba(0,0,0,.5)}
        .tm-pin img{width:100%;height:100%;object-fit:cover;display:block}
        .panel{position:absolute;inset-inline-end:0;top:0;height:100%;width:min(380px,90vw);background:#0b2c55;border-inline-start:1px solid rgba(168,231,255,.2);box-shadow:-24px 0 60px rgba(0,0,0,.5);z-index:1000;transform:translateX(-105%);transition:transform .3s ease;overflow-y:auto;padding:22px}
        html[dir="rtl"] .panel{transform:translateX(105%)}
        .panel.open{transform:none !important}
        .panel-close{position:absolute;top:14px;inset-inline-start:14px;width:36px;height:36px;border-radius:50%;border:1px solid rgba(168,231,255,.25);background:rgba(255,255,255,.08);color:#fff;font-size:22px;line-height:1;cursor:pointer}
        .p-flag{width:90px;height:60px;object-fit:cover;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,.45);margin-bottom:12px}
        .p-name{font-size:24px;font-weight:900;margin:0 0 4px;color:#fff}
        .p-confed{display:inline-block;font-size:12px;font-weight:800;color:#06202e;background:var(--sky);border-radius:999px;padding:3px 12px;margin-bottom:14px}
        .p-story{font-size:14px;line-height:1.7;color:rgba(255,255,255,.88);font-weight:600;margin:0 0 18px}
        .p-sec{margin:0 0 18px}
        .p-lbl{display:block;font-size:13px;font-weight:900;color:var(--gold);margin-bottom:8px}
        .p-chips{display:flex;flex-wrap:wrap;gap:7px}
        .p-chip{font-size:12.5px;font-weight:800;color:#eaf6ff;background:rgba(168,231,255,.14);border:1px solid rgba(168,231,255,.22);border-radius:999px;padding:4px 12px}
        .p-list{margin:0;padding-inline-start:18px;color:rgba(255,255,255,.85);font-weight:600;font-size:13.5px;line-height:1.8}
        .p-fixtures{display:inline-block;margin-top:4px;padding:9px 16px;border-radius:999px;background:#0e63e6;color:#fff;text-decoration:none;font-weight:800;font-size:13px}
        .hint{position:absolute;inset:0;display:grid;place-items:center;z-index:900;pointer-events:none;padding:20px}
        .hint-card{background:rgba(11,44,85,.92);border:1px solid rgba(168,231,255,.2);border-radius:20px;padding:26px 30px;max-width:420px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.45)}
        .hint-emoji{font-size:40px}
        .hint-card h2{margin:8px 0 6px;font-size:22px;color:#fff}
        .hint-card p{margin:0;font-size:14px;color:rgba(255,255,255,.78);line-height:1.7;font-weight:600}
    </style>
</head>
<body>

<header class="topbar">
    <div class="brand">
        <span class="brand-trophy">🏆</span>
        <div class="brand-text">
            <h1>كأس العالم 2026</h1>
            <p><?= count($nations) ?> منتخباً على الخريطة التفاعلية</p>
        </div>
    </div>
    <div class="hosts" title="الدول المستضيفة">
        <span>المستضيف:</span>
        <img class="host-flag" src="https://flagcdn.com/w40/ca.png" alt="كندا" title="كندا">
        <img class="host-flag" src="https://flagcdn.com/w40/mx.png" alt="المكسيك" title="المكسيك">
        <img class="host-flag" src="https://flagcdn.com/w40/us.png" alt="الولايات المتحدة" title="الولايات المتحدة">
    </div>
    <div class="searchbox">
        <input type="search" id="search" placeholder="ابحث عن منتخب…" autocomplete="off" aria-label="ابحث عن منتخب">
        <ul id="search-results" class="search-results" hidden></ul>
    </div>
    <a href="/WC2026/" class="back-home">⬅ العودة إلى الموقع</a>
</header>

<div class="filters" id="filters">
    <button class="chip is-active" data-confed="ALL">الكل <small><?= count($nations) ?></small></button>
    <?php foreach ($confedNames as $key => $label): $n = $counts[$key] ?? 0; if (!$n) continue; ?>
        <button class="chip" data-confed="<?= $key ?>"><span class="dot dot-<?= strtolower($key) ?>"></span><?= $label ?> <small><?= $n ?></small></button>
    <?php endforeach; ?>
</div>

<main class="stage">
    <div id="map" aria-label="خريطة العالم التفاعلية"></div>

    <aside class="panel" id="panel" aria-hidden="true">
        <button class="panel-close" id="panel-close" aria-label="إغلاق">&times;</button>
        <div class="panel-body" id="panel-body"></div>
    </aside>

    <div class="hint" id="hint">
        <div class="hint-card">
            <span class="hint-emoji">🗺️</span>
            <h2>اضغط على أي دولة</h2>
            <p>استكشف المنتخبات المتأهلة لكأس العالم 2026 — قصة كل منتخب الكروية، وأبرز نجومه، ولحظاته المميزة.</p>
        </div>
    </div>
</main>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
(function(){
    var NATIONS = <?= $nationsJson ?>;
    function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }

    var map = L.map('map',{zoomControl:true,scrollWheelZoom:true,worldCopyJump:true}).setView([25,10],2);
    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',{
        maxZoom:9,minZoom:1,
        attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>'
    }).addTo(map);

    var panel=document.getElementById('panel'), body=document.getElementById('panel-body'), hint=document.getElementById('hint');
    var markers={}, bounds=[];

    function nm(n){ return n.nameAr || n.name; }
    function openPanel(n){
        var h='';
        h+='<img class="p-flag" src="https://flagcdn.com/w80/'+esc(n.code)+'.png" alt="">';
        h+='<h2 class="p-name">'+esc(nm(n))+'</h2>';
        if(n.confed) h+='<span class="p-confed">'+esc(n.confed)+(n.group?' · '+esc(n.group):'')+(n.host?' · مستضيف':'')+'</span>';
        h+='<p class="p-story">'+esc(n.storyAr || n.story || 'سيتوفر ملف هذا المنتخب قريباً.')+'</p>';
        if(n.stars && n.stars.length){
            h+='<div class="p-sec"><span class="p-lbl">★ أبرز النجوم</span><div class="p-chips">';
            n.stars.forEach(function(s){ h+='<span class="p-chip">'+esc(s)+'</span>'; });
            h+='</div></div>';
        }
        if(n.moments && n.moments.length){
            h+='<div class="p-sec"><span class="p-lbl">🏆 لحظات مميزة</span><ul class="p-list">';
            n.moments.forEach(function(s){ h+='<li>'+esc(s)+'</li>'; });
            h+='</ul></div>';
        }
        h+='<a class="p-fixtures" href="/WC2026/matches?q='+encodeURIComponent(n.name)+'">عرض المباريات ←</a>';
        body.innerHTML=h;
        panel.classList.add('open'); panel.setAttribute('aria-hidden','false');
        if(hint) hint.style.display='none';
    }
    document.getElementById('panel-close').addEventListener('click',function(){
        panel.classList.remove('open'); panel.setAttribute('aria-hidden','true');
    });

    NATIONS.forEach(function(n){
        var icon=L.divIcon({className:'tm-pin-wrap',
            html:'<span class="tm-pin"><img src="https://flagcdn.com/w40/'+esc(n.code)+'.png" alt="" loading="lazy"></span>',
            iconSize:[30,30],iconAnchor:[15,15]});
        var m=L.marker([n.lat,n.lng],{icon:icon,title:nm(n)}).addTo(map);
        m.on('click',function(){ openPanel(n); map.flyTo([n.lat,n.lng],Math.max(map.getZoom(),4),{duration:.6}); });
        markers[n.code]=m;
        bounds.push([n.lat,n.lng]);
    });
    if(bounds.length) map.fitBounds(bounds,{padding:[40,40],maxZoom:4});

    /* Confederation filter */
    document.getElementById('filters').addEventListener('click',function(e){
        var btn=e.target.closest('.chip'); if(!btn) return;
        document.querySelectorAll('.chip').forEach(function(c){ c.classList.remove('is-active'); });
        btn.classList.add('is-active');
        var conf=btn.getAttribute('data-confed');
        NATIONS.forEach(function(n){
            var m=markers[n.code]; if(!m) return;
            var show=(conf==='ALL'||n.confed===conf);
            if(show){ if(!map.hasLayer(m)) m.addTo(map); } else { if(map.hasLayer(m)) map.removeLayer(m); }
        });
    });

    /* Search */
    var input=document.getElementById('search'), results=document.getElementById('search-results');
    input.addEventListener('input',function(){
        var q=input.value.trim().toLowerCase();
        if(!q){ results.hidden=true; results.innerHTML=''; return; }
        var hits=NATIONS.filter(function(n){ return nm(n).toLowerCase().indexOf(q)>-1 || (n.name||'').toLowerCase().indexOf(q)>-1; }).slice(0,8);
        if(!hits.length){ results.hidden=true; results.innerHTML=''; return; }
        results.innerHTML=hits.map(function(n){
            return '<li data-code="'+esc(n.code)+'"><img src="https://flagcdn.com/w40/'+esc(n.code)+'.png" alt="">'+esc(nm(n))+'</li>';
        }).join('');
        results.hidden=false;
    });
    results.addEventListener('click',function(e){
        var li=e.target.closest('li'); if(!li) return;
        var code=li.getAttribute('data-code');
        var n=NATIONS.filter(function(x){ return x.code===code; })[0];
        if(n){ openPanel(n); map.flyTo([n.lat,n.lng],4,{duration:.7}); }
        results.hidden=true; input.value=n?nm(n):'';
    });
    document.addEventListener('click',function(e){ if(!e.target.closest('.searchbox')) results.hidden=true; });

    setTimeout(function(){ map.invalidateSize(); },200);
    window.addEventListener('resize',function(){ map.invalidateSize(); });
})();
</script>
</body>
</html>
