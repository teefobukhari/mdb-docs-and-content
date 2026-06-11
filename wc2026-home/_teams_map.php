<?php
/**
 * Shared helper for the native "Participating Teams Map".
 * Builds a JSON list of the nations found in wc_fixtures, each resolved to an
 * ISO flag code + capital/centroid coordinates, so the home + matches pages can
 * render a real Leaflet map of the qualified teams (no external teams-map page).
 *
 * Each nation is also enriched with its football story, star players and key
 * moments (see teams_data.php) so the map popups show real detail when tapped.
 */

require_once __DIR__ . '/teams_data.php';

if (!function_exists('wc_tm_country_code')) {
    function wc_tm_country_code(string $name): string {
        $name = strtolower(trim($name));
        $name = str_replace('&', 'and', $name);
        $name = preg_replace('/[^a-z]/', '', $name);
        static $map = [
            'usa'=>'us','unitedstates'=>'us','canada'=>'ca','mexico'=>'mx',
            'argentina'=>'ar','brazil'=>'br','brasil'=>'br','uruguay'=>'uy','colombia'=>'co','chile'=>'cl','peru'=>'pe','paraguay'=>'py','ecuador'=>'ec','venezuela'=>'ve','bolivia'=>'bo',
            'france'=>'fr','spain'=>'es','germany'=>'de','portugal'=>'pt','england'=>'gb-eng','scotland'=>'gb-sct','wales'=>'gb-wls','netherlands'=>'nl','belgium'=>'be','italy'=>'it','croatia'=>'hr','switzerland'=>'ch','denmark'=>'dk','sweden'=>'se','norway'=>'no','poland'=>'pl','austria'=>'at','serbia'=>'rs','ukraine'=>'ua','czechia'=>'cz','czechrepublic'=>'cz','turkey'=>'tr','turkiye'=>'tr','greece'=>'gr','hungary'=>'hu','romania'=>'ro','slovenia'=>'si','slovakia'=>'sk','iceland'=>'is','republicofireland'=>'ie','ireland'=>'ie','albania'=>'al','bosniaandherzegovina'=>'ba','bosnia'=>'ba','northmacedonia'=>'mk','georgia'=>'ge',
            'japan'=>'jp','southkorea'=>'kr','korearepublic'=>'kr','australia'=>'au','saudiarabia'=>'sa','qatar'=>'qa','iran'=>'ir','iraq'=>'iq','uae'=>'ae','unitedarabemirates'=>'ae','jordan'=>'jo','oman'=>'om','uzbekistan'=>'uz','china'=>'cn','india'=>'in','indonesia'=>'id','palestine'=>'ps','bahrain'=>'bh','kuwait'=>'kw','vietnam'=>'vn','thailand'=>'th',
            'morocco'=>'ma','senegal'=>'sn','ghana'=>'gh','nigeria'=>'ng','cameroon'=>'cm','egypt'=>'eg','algeria'=>'dz','tunisia'=>'tn','ivorycoast'=>'ci','cotedivoire'=>'ci','southafrica'=>'za','mali'=>'ml','capeverde'=>'cv','caboverde'=>'cv','guinea'=>'gn','drcongo'=>'cd','congodr'=>'cd','gabon'=>'ga','angola'=>'ao','zambia'=>'zm','burkinafaso'=>'bf','equatorialguinea'=>'gq',
            'costarica'=>'cr','panama'=>'pa','jamaica'=>'jm','honduras'=>'hn','curacao'=>'cw','suriname'=>'sr','haiti'=>'ht','elsalvador'=>'sv','guatemala'=>'gt','trinidadandtobago'=>'tt',
            'newzealand'=>'nz','fiji'=>'fj','papuanewguinea'=>'pg','newcaledonia'=>'nc','tahiti'=>'pf',
        ];
        return $map[$name] ?? '';
    }
}

if (!function_exists('wc_tm_latlng')) {
    function wc_tm_latlng(string $code): ?array {
        static $m = [
            'us'=>[38.0,-97.0],'ca'=>[56.1,-106.3],'mx'=>[23.6,-102.5],
            'ar'=>[-38.4,-63.6],'br'=>[-14.2,-51.9],'uy'=>[-32.5,-55.8],'co'=>[4.6,-74.1],'cl'=>[-35.7,-71.5],'pe'=>[-9.2,-75.0],'py'=>[-23.4,-58.4],'ec'=>[-1.8,-78.2],'ve'=>[6.4,-66.6],'bo'=>[-16.3,-63.6],
            'fr'=>[46.6,2.2],'es'=>[40.0,-4.0],'de'=>[51.2,10.4],'pt'=>[39.4,-8.2],'gb-eng'=>[52.5,-1.5],'gb-sct'=>[56.5,-4.2],'gb-wls'=>[52.3,-3.7],'nl'=>[52.1,5.3],'be'=>[50.5,4.5],'it'=>[41.9,12.6],'hr'=>[45.1,15.2],'ch'=>[46.8,8.2],'dk'=>[56.0,9.5],'se'=>[60.1,18.6],'no'=>[60.5,8.5],'pl'=>[51.9,19.1],'at'=>[47.5,14.6],'rs'=>[44.0,21.0],'ua'=>[48.4,31.2],'cz'=>[49.8,15.5],'tr'=>[39.0,35.2],'gr'=>[39.1,21.8],'hu'=>[47.2,19.5],'ro'=>[45.9,24.97],'si'=>[46.2,14.8],'sk'=>[48.7,19.7],'is'=>[64.96,-19.0],'ie'=>[53.4,-8.2],'al'=>[41.2,20.2],'ba'=>[43.9,17.7],'mk'=>[41.6,21.7],'ge'=>[42.3,43.4],
            'jp'=>[36.2,138.3],'kr'=>[36.5,127.9],'au'=>[-25.3,133.8],'sa'=>[23.9,45.1],'qa'=>[25.3,51.2],'ir'=>[32.4,53.7],'iq'=>[33.2,43.7],'ae'=>[23.4,53.8],'jo'=>[30.6,36.2],'om'=>[21.5,55.9],'uz'=>[41.4,64.6],'cn'=>[35.9,104.2],'in'=>[22.0,79.0],'id'=>[-2.5,118.0],'ps'=>[31.9,35.2],'bh'=>[26.0,50.5],'kw'=>[29.3,47.6],'vn'=>[14.1,108.3],'th'=>[15.1,101.0],
            'ma'=>[31.8,-7.1],'sn'=>[14.5,-14.5],'gh'=>[7.9,-1.0],'ng'=>[9.1,8.7],'cm'=>[7.4,12.3],'eg'=>[26.8,30.8],'dz'=>[28.0,1.7],'tn'=>[33.9,9.6],'ci'=>[7.5,-5.5],'za'=>[-30.6,22.9],'ml'=>[17.6,-4.0],'cv'=>[16.0,-24.0],'gn'=>[9.9,-9.7],'cd'=>[-4.0,21.8],'ga'=>[-0.8,11.6],'ao'=>[-11.2,17.9],'zm'=>[-13.1,27.8],'bf'=>[12.2,-1.6],'gq'=>[1.6,10.3],
            'cr'=>[9.7,-83.8],'pa'=>[8.5,-80.8],'jm'=>[18.1,-77.3],'hn'=>[15.2,-86.2],'cw'=>[12.2,-69.0],'sr'=>[4.0,-56.0],'ht'=>[19.0,-72.3],'sv'=>[13.8,-88.9],'gt'=>[15.8,-90.2],'tt'=>[10.7,-61.2],
            'nz'=>[-41.0,174.0],'fj'=>[-17.7,178.0],'pg'=>[-6.3,143.9],'nc'=>[-21.3,165.6],'pf'=>[-17.6,-149.4],
        ];
        $code = strtolower(trim($code));
        return $m[$code] ?? null;
    }
}

if (!function_exists('wc_tm_name')) {
    /** English display name for a flag code (used by the standalone Teams-Map page). */
    function wc_tm_name(string $code): string {
        static $n = [
            'us'=>'United States','ca'=>'Canada','mx'=>'Mexico','cr'=>'Costa Rica','pa'=>'Panama','jm'=>'Jamaica','hn'=>'Honduras','sv'=>'El Salvador','gt'=>'Guatemala','cw'=>'Curaçao','tt'=>'Trinidad & Tobago','ht'=>'Haiti','sr'=>'Suriname',
            'ar'=>'Argentina','br'=>'Brazil','uy'=>'Uruguay','co'=>'Colombia','cl'=>'Chile','pe'=>'Peru','py'=>'Paraguay','ec'=>'Ecuador','ve'=>'Venezuela','bo'=>'Bolivia',
            'fr'=>'France','es'=>'Spain','de'=>'Germany','pt'=>'Portugal','gb-eng'=>'England','gb-sct'=>'Scotland','gb-wls'=>'Wales','nl'=>'Netherlands','be'=>'Belgium','it'=>'Italy','hr'=>'Croatia','ch'=>'Switzerland','dk'=>'Denmark','se'=>'Sweden','no'=>'Norway','pl'=>'Poland','at'=>'Austria','rs'=>'Serbia','ua'=>'Ukraine','cz'=>'Czechia','tr'=>'Türkiye','gr'=>'Greece','hu'=>'Hungary','ro'=>'Romania','si'=>'Slovenia','sk'=>'Slovakia','is'=>'Iceland','ie'=>'Ireland','al'=>'Albania','ba'=>'Bosnia & Herzegovina','mk'=>'North Macedonia','ge'=>'Georgia',
            'jp'=>'Japan','kr'=>'South Korea','au'=>'Australia','sa'=>'Saudi Arabia','qa'=>'Qatar','ir'=>'Iran','iq'=>'Iraq','ae'=>'United Arab Emirates','jo'=>'Jordan','om'=>'Oman','uz'=>'Uzbekistan','cn'=>'China','in'=>'India','id'=>'Indonesia','ps'=>'Palestine','bh'=>'Bahrain','kw'=>'Kuwait','vn'=>'Vietnam','th'=>'Thailand',
            'ma'=>'Morocco','sn'=>'Senegal','gh'=>'Ghana','ng'=>'Nigeria','cm'=>'Cameroon','eg'=>'Egypt','dz'=>'Algeria','tn'=>'Tunisia','ci'=>'Ivory Coast','za'=>'South Africa','ml'=>'Mali','cv'=>'Cape Verde','gn'=>'Guinea','cd'=>'DR Congo','ga'=>'Gabon','ao'=>'Angola','zm'=>'Zambia','bf'=>'Burkina Faso','gq'=>'Equatorial Guinea',
            'nz'=>'New Zealand','fj'=>'Fiji','pg'=>'Papua New Guinea','nc'=>'New Caledonia','pf'=>'Tahiti',
        ];
        $code = strtolower(trim($code));
        return $n[$code] ?? strtoupper($code);
    }
}

if (!function_exists('wc_tm_all_nations')) {
    /**
     * DB-independent list of every nation that has both coordinates and a story.
     * Used by the standalone /teams-map/ page so it works without a DB connection.
     * @return array<int,array<string,mixed>>
     */
    function wc_tm_all_nations(): array {
        $out = [];
        foreach (wc_team_meta() as $code => $meta) {
            $ll = wc_tm_latlng($code);
            if (!$ll) continue;
            $out[] = [
                'name'    => wc_tm_name($code),
                'code'    => $code,
                'lat'     => $ll[0],
                'lng'     => $ll[1],
                'confed'  => $meta['confed']  ?? '',
                'story'   => $meta['story']   ?? '',
                'storyAr' => $meta['story_ar']?? '',
                'stars'   => $meta['stars']   ?? [],
                'moments' => $meta['moments'] ?? [],
            ];
        }
        usort($out, fn($a,$b)=>strcmp($a['name'],$b['name']));
        return $out;
    }
}

if (!function_exists('wc_teams_map_nations')) {
    /** @return array<int,array{name:string,code:string,lat:float,lng:float}> */
    function wc_teams_map_nations($conn): array {
        $out = [];
        $seen = [];
        $rows = wc_rows($conn, "
            SELECT DISTINCT name FROM (
                SELECT home_name AS name FROM wc_fixtures
                UNION
                SELECT away_name AS name FROM wc_fixtures
            ) t
            WHERE name IS NOT NULL AND name <> '' AND UPPER(name) <> 'TBA'
            ORDER BY name
        ");
        foreach ($rows as $r) {
            $nm = trim((string)$r['name']);
            if ($nm === '') continue;
            $code = wc_tm_country_code($nm);
            if ($code === '') continue;
            $ll = wc_tm_latlng($code);
            if (!$ll) continue;
            if (isset($seen[$code])) continue;
            $seen[$code] = true;
            $meta = wc_team_meta($code);
            $out[] = [
                'name'    => $nm,
                'code'    => $code,
                'lat'     => $ll[0],
                'lng'     => $ll[1],
                'confed'  => $meta['confed']  ?? '',
                'story'   => $meta['story']   ?? '',
                'storyAr' => $meta['story_ar']?? '',
                'stars'   => $meta['stars']   ?? [],
                'moments' => $meta['moments'] ?? [],
            ];
        }
        return $out;
    }
}
