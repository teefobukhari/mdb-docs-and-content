<?php
/**
 * كأس العالم 2026 — الخريطة التفاعلية للمنتخبات المشاركة
 * صفحة رئيسية: تعرض خريطة عالم تفاعلية بعلامات الدول الـ48،
 * والضغط على أي دولة يجلب تفاصيلها من api.php.
 *
 * تعيش هذه الصفحة ضمن موقع WC2026 على المسار: /WC2026/teams-map/
 * المتطلبات بجانب هذا الملف: data/countries.php و assets/(css|js|flags).
 */

declare(strict_types=1);
require_once __DIR__ . '/data/countries.php';

$countries = wc2026_countries();

$confedNames = [
    'UEFA'     => 'أوروبا',
    'CONMEBOL' => 'أمريكا الجنوبية',
    'CONCACAF' => 'أمريكا الشمالية والوسطى',
    'CAF'      => 'أفريقيا',
    'AFC'      => 'آسيا',
    'OFC'      => 'أوقيانوسيا',
];

// بيانات خفيفة للعلامات فقط (التفاصيل الكاملة تُجلب عند الضغط)
$markers = [];
foreach ($countries as $code => $c) {
    $markers[] = [
        'code'   => $code,
        'name'   => $c['name_ar'],
        'en'     => $c['name_en'],
        'flag'   => $c['flag'],
        'confed' => $c['confed'],
        'group'  => $c['group'],
        'host'   => !empty($c['host']),
        'lat'    => $c['coords'][0],
        'lng'    => $c['coords'][1],
    ];
}
$markersJson = json_encode($markers, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>كأس العالم 2026 — الخريطة التفاعلية للمنتخبات</title>
    <meta name="description" content="خريطة تفاعلية للمنتخبات الـ48 المشاركة في كأس العالم 2026: بيانات كل دولة وتاريخها الكروي، أبرز نجومها ولحظاتهم المميزة.">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">

    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<header class="topbar">
    <div class="brand">
        <span class="brand-trophy">🏆</span>
        <div class="brand-text">
            <h1>كأس العالم 2026</h1>
            <p>الخريطة التفاعلية لمنتخبات البطولة الـ48</p>
        </div>
    </div>
    <div class="hosts" title="الدول المستضيفة">
        <span>المستضيف:</span>
        <img class="host-flag" src="assets/flags/ca.png" alt="كندا" title="كندا">
        <img class="host-flag" src="assets/flags/mx.png" alt="المكسيك" title="المكسيك">
        <img class="host-flag" src="assets/flags/us.png" alt="الولايات المتحدة" title="الولايات المتحدة">
    </div>
    <div class="searchbox">
        <input type="search" id="search" placeholder="ابحث عن منتخب…" autocomplete="off" aria-label="ابحث عن منتخب">
        <ul id="search-results" class="search-results" hidden></ul>
    </div>
    <a href="/WC2026/" class="back-home" style="margin-inline-start:auto;padding:8px 14px;border-radius:999px;background:#0E63E6;color:#fff;text-decoration:none;font-weight:800;font-size:13px;white-space:nowrap;">⬅ العودة إلى الموقع</a>
</header>

<div class="filters" id="filters">
    <button class="chip is-active" data-confed="ALL">الكل <small>48</small></button>
    <?php
    $counts = array_count_values(array_column($markers, 'confed'));
    foreach ($confedNames as $key => $label) {
        $n = $counts[$key] ?? 0;
        echo '<button class="chip" data-confed="' . $key . '"><span class="dot dot-' . strtolower($key) . '"></span>' . $label . ' <small>' . $n . '</small></button>';
    }
    ?>
</div>

<main class="stage">
    <div id="map" aria-label="خريطة العالم التفاعلية"></div>

    <!-- اللوحة الجانبية للتفاصيل -->
    <aside class="panel" id="panel" aria-hidden="true">
        <button class="panel-close" id="panel-close" aria-label="إغلاق">&times;</button>
        <div class="panel-body" id="panel-body">
            <!-- يُملأ ديناميكياً -->
        </div>
    </aside>

    <!-- شاشة الترحيب -->
    <div class="hint" id="hint">
        <div class="hint-card">
            <span class="hint-emoji">🗺️</span>
            <h2>اضغط على أي دولة</h2>
            <p>استكشف المنتخبات الـ48 المشاركة في كأس العالم 2026 — بيانات كل دولة، تاريخها الكروي، أبرز نجومها ولحظاتهم المميزة.</p>
        </div>
    </div>
</main>

<script
    src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
    window.WC2026_MARKERS = <?= $markersJson ?>;
</script>
<script src="assets/js/app.js"></script>
</body>
</html>
