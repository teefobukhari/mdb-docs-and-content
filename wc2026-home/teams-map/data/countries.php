<?php
/**
 * كأس العالم 2026 — بيانات المنتخبات المشاركة (الـ48) للخريطة التفاعلية.
 *
 * wc2026_countries() ترجع مصفوفة مفتاحها رمز الدولة، وقيمتها:
 *   name_ar  الاسم بالعربية
 *   name_en  الاسم بالإنجليزية
 *   flag     رمز العلم (ISO) لاستخدامه مع flagcdn
 *   confed   الاتحاد القاري (UEFA/CONMEBOL/CONCACAF/CAF/AFC/OFC)
 *   group    المجموعة (A–L)
 *   host     true إذا كانت دولة مستضيفة
 *   coords   [lat, lng] إحداثيات المركز
 *
 * التفاصيل الكروية (القصة، النجوم، اللحظات) تُدمج من ../teams_data.php.
 * يُحاط التعريف بـ function_exists حتى لا يتعارض مع نسخة موجودة على الخادم.
 */

if (!function_exists('wc2026_countries')) {
    function wc2026_countries(): array {
        return [
            // ===== المستضيفون (CONCACAF) =====
            'mx' => ['name_ar'=>'المكسيك','name_en'=>'Mexico','flag'=>'mx','confed'=>'CONCACAF','group'=>'A','host'=>true,'coords'=>[23.6,-102.5]],
            'ca' => ['name_ar'=>'كندا','name_en'=>'Canada','flag'=>'ca','confed'=>'CONCACAF','group'=>'B','host'=>true,'coords'=>[56.1,-106.3]],
            'us' => ['name_ar'=>'الولايات المتحدة','name_en'=>'United States','flag'=>'us','confed'=>'CONCACAF','group'=>'D','host'=>true,'coords'=>[38.0,-97.0]],
            'cr' => ['name_ar'=>'كوستاريكا','name_en'=>'Costa Rica','flag'=>'cr','confed'=>'CONCACAF','group'=>'G','host'=>false,'coords'=>[9.7,-83.8]],
            'pa' => ['name_ar'=>'بنما','name_en'=>'Panama','flag'=>'pa','confed'=>'CONCACAF','group'=>'I','host'=>false,'coords'=>[8.5,-80.8]],
            'jm' => ['name_ar'=>'جامايكا','name_en'=>'Jamaica','flag'=>'jm','confed'=>'CONCACAF','group'=>'J','host'=>false,'coords'=>[18.1,-77.3]],

            // ===== أمريكا الجنوبية (CONMEBOL) =====
            'ar' => ['name_ar'=>'الأرجنتين','name_en'=>'Argentina','flag'=>'ar','confed'=>'CONMEBOL','group'=>'A','host'=>false,'coords'=>[-38.4,-63.6]],
            'br' => ['name_ar'=>'البرازيل','name_en'=>'Brazil','flag'=>'br','confed'=>'CONMEBOL','group'=>'B','host'=>false,'coords'=>[-14.2,-51.9]],
            'uy' => ['name_ar'=>'الأوروغواي','name_en'=>'Uruguay','flag'=>'uy','confed'=>'CONMEBOL','group'=>'E','host'=>false,'coords'=>[-32.5,-55.8]],
            'co' => ['name_ar'=>'كولومبيا','name_en'=>'Colombia','flag'=>'co','confed'=>'CONMEBOL','group'=>'E','host'=>false,'coords'=>[4.6,-74.1]],
            'ec' => ['name_ar'=>'الإكوادور','name_en'=>'Ecuador','flag'=>'ec','confed'=>'CONMEBOL','group'=>'E','host'=>false,'coords'=>[-1.8,-78.2]],
            'py' => ['name_ar'=>'باراغواي','name_en'=>'Paraguay','flag'=>'py','confed'=>'CONMEBOL','group'=>'K','host'=>false,'coords'=>[-23.4,-58.4]],
            've' => ['name_ar'=>'فنزويلا','name_en'=>'Venezuela','flag'=>'ve','confed'=>'CONMEBOL','group'=>'L','host'=>false,'coords'=>[6.4,-66.6]],

            // ===== أوروبا (UEFA) =====
            'fr' => ['name_ar'=>'فرنسا','name_en'=>'France','flag'=>'fr','confed'=>'UEFA','group'=>'B','host'=>false,'coords'=>[46.6,2.2]],
            'es' => ['name_ar'=>'إسبانيا','name_en'=>'Spain','flag'=>'es','confed'=>'UEFA','group'=>'B','host'=>false,'coords'=>[40.0,-4.0]],
            'de' => ['name_ar'=>'ألمانيا','name_en'=>'Germany','flag'=>'de','confed'=>'UEFA','group'=>'C','host'=>false,'coords'=>[51.2,10.4]],
            'pt' => ['name_ar'=>'البرتغال','name_en'=>'Portugal','flag'=>'pt','confed'=>'UEFA','group'=>'C','host'=>false,'coords'=>[39.4,-8.2]],
            'gb-eng' => ['name_ar'=>'إنجلترا','name_en'=>'England','flag'=>'gb-eng','confed'=>'UEFA','group'=>'C','host'=>false,'coords'=>[52.5,-1.5]],
            'nl' => ['name_ar'=>'هولندا','name_en'=>'Netherlands','flag'=>'nl','confed'=>'UEFA','group'=>'C','host'=>false,'coords'=>[52.1,5.3]],
            'be' => ['name_ar'=>'بلجيكا','name_en'=>'Belgium','flag'=>'be','confed'=>'UEFA','group'=>'D','host'=>false,'coords'=>[50.5,4.5]],
            'it' => ['name_ar'=>'إيطاليا','name_en'=>'Italy','flag'=>'it','confed'=>'UEFA','group'=>'D','host'=>false,'coords'=>[41.9,12.6]],
            'hr' => ['name_ar'=>'كرواتيا','name_en'=>'Croatia','flag'=>'hr','confed'=>'UEFA','group'=>'D','host'=>false,'coords'=>[45.1,15.2]],
            'ch' => ['name_ar'=>'سويسرا','name_en'=>'Switzerland','flag'=>'ch','confed'=>'UEFA','group'=>'F','host'=>false,'coords'=>[46.8,8.2]],
            'dk' => ['name_ar'=>'الدنمارك','name_en'=>'Denmark','flag'=>'dk','confed'=>'UEFA','group'=>'F','host'=>false,'coords'=>[56.0,9.5]],
            'rs' => ['name_ar'=>'صربيا','name_en'=>'Serbia','flag'=>'rs','confed'=>'UEFA','group'=>'H','host'=>false,'coords'=>[44.0,21.0]],
            'tr' => ['name_ar'=>'تركيا','name_en'=>'Türkiye','flag'=>'tr','confed'=>'UEFA','group'=>'J','host'=>false,'coords'=>[39.0,35.2]],
            'at' => ['name_ar'=>'النمسا','name_en'=>'Austria','flag'=>'at','confed'=>'UEFA','group'=>'K','host'=>false,'coords'=>[47.5,14.6]],
            'no' => ['name_ar'=>'النرويج','name_en'=>'Norway','flag'=>'no','confed'=>'UEFA','group'=>'J','host'=>false,'coords'=>[60.5,8.5]],
            'gb-wls' => ['name_ar'=>'ويلز','name_en'=>'Wales','flag'=>'gb-wls','confed'=>'UEFA','group'=>'K','host'=>false,'coords'=>[52.3,-3.7]],
            'pl' => ['name_ar'=>'بولندا','name_en'=>'Poland','flag'=>'pl','confed'=>'UEFA','group'=>'L','host'=>false,'coords'=>[51.9,19.1]],

            // ===== آسيا (AFC) =====
            'jp' => ['name_ar'=>'اليابان','name_en'=>'Japan','flag'=>'jp','confed'=>'AFC','group'=>'F','host'=>false,'coords'=>[36.2,138.3]],
            'kr' => ['name_ar'=>'كوريا الجنوبية','name_en'=>'South Korea','flag'=>'kr','confed'=>'AFC','group'=>'F','host'=>false,'coords'=>[36.5,127.9]],
            'au' => ['name_ar'=>'أستراليا','name_en'=>'Australia','flag'=>'au','confed'=>'AFC','group'=>'G','host'=>false,'coords'=>[-25.3,133.8]],
            'sa' => ['name_ar'=>'السعودية','name_en'=>'Saudi Arabia','flag'=>'sa','confed'=>'AFC','group'=>'G','host'=>false,'coords'=>[23.9,45.1]],
            'qa' => ['name_ar'=>'قطر','name_en'=>'Qatar','flag'=>'qa','confed'=>'AFC','group'=>'H','host'=>false,'coords'=>[25.3,51.2]],
            'ir' => ['name_ar'=>'إيران','name_en'=>'Iran','flag'=>'ir','confed'=>'AFC','group'=>'H','host'=>false,'coords'=>[32.4,53.7]],
            'uz' => ['name_ar'=>'أوزبكستان','name_en'=>'Uzbekistan','flag'=>'uz','confed'=>'AFC','group'=>'L','host'=>false,'coords'=>[41.4,64.6]],
            'jo' => ['name_ar'=>'الأردن','name_en'=>'Jordan','flag'=>'jo','confed'=>'AFC','group'=>'I','host'=>false,'coords'=>[30.6,36.2]],

            // ===== أفريقيا (CAF) =====
            'ma' => ['name_ar'=>'المغرب','name_en'=>'Morocco','flag'=>'ma','confed'=>'CAF','group'=>'A','host'=>false,'coords'=>[31.8,-7.1]],
            'sn' => ['name_ar'=>'السنغال','name_en'=>'Senegal','flag'=>'sn','confed'=>'CAF','group'=>'H','host'=>false,'coords'=>[14.5,-14.5]],
            'gh' => ['name_ar'=>'غانا','name_en'=>'Ghana','flag'=>'gh','confed'=>'CAF','group'=>'I','host'=>false,'coords'=>[7.9,-1.0]],
            'ng' => ['name_ar'=>'نيجيريا','name_en'=>'Nigeria','flag'=>'ng','confed'=>'CAF','group'=>'A','host'=>false,'coords'=>[9.1,8.7]],
            'cm' => ['name_ar'=>'الكاميرون','name_en'=>'Cameroon','flag'=>'cm','confed'=>'CAF','group'=>'J','host'=>false,'coords'=>[7.4,12.3]],
            'eg' => ['name_ar'=>'مصر','name_en'=>'Egypt','flag'=>'eg','confed'=>'CAF','group'=>'G','host'=>false,'coords'=>[26.8,30.8]],
            'dz' => ['name_ar'=>'الجزائر','name_en'=>'Algeria','flag'=>'dz','confed'=>'CAF','group'=>'K','host'=>false,'coords'=>[28.0,1.7]],
            'tn' => ['name_ar'=>'تونس','name_en'=>'Tunisia','flag'=>'tn','confed'=>'CAF','group'=>'L','host'=>false,'coords'=>[33.9,9.6]],
            'ci' => ['name_ar'=>'ساحل العاج','name_en'=>'Ivory Coast','flag'=>'ci','confed'=>'CAF','group'=>'I','host'=>false,'coords'=>[7.5,-5.5]],

            // ===== أوقيانوسيا (OFC) =====
            'nz' => ['name_ar'=>'نيوزيلندا','name_en'=>'New Zealand','flag'=>'nz','confed'=>'OFC','group'=>'J','host'=>false,'coords'=>[-41.0,174.0]],
        ];
    }
}
