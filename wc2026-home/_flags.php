<?php
/**
 * _flags.php — shared country-flag resolver.
 * Single source of truth for team/country flags: db_form.WC2026_Filter_Countries
 * (country_name / country_code / flag_path). Match by name or code with
 * accent-stripping + alias normalization so spellings like "Türkiye", "USA",
 * "Korea Republic" still resolve. Helpers are guarded so a page that already
 * defines them (e.g. home.php) is never broken by including this file.
 */

if (!function_exists('wc_norm_country')) {
    /** Normalize a country/team name to an accent-free, alnum-only token. */
    function wc_norm_country(string $s): string {
        $s = trim($s);
        if ($s === '') return '';
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($t !== false && $t !== '') $s = $t;
        }
        $s = mb_strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '', $s);
        return (string)$s;
    }
}

if (!function_exists('wc_country_aliases')) {
    /** All equivalent normalized tokens for a country (so either spelling resolves). */
    function wc_country_aliases(string $norm): array {
        static $map = null;
        if ($map === null) {
            $groups = [
                ['usa','us','unitedstates','unitedstatesofamerica'],
                ['turkiye','turkey'],
                ['southkorea','korearepublic','korea'],
                ['northkorea','koreadpr','koreademocraticpeoplesrepublic'],
                ['ivorycoast','cotedivoire'],
                ['czechia','czechrepublic'],
                ['iran','iranislamicrepublic'],
                ['china','chinapr'],
                ['drcongo','congodr','democraticrepublicofthecongo','congokinshasa'],
                ['congo','congorepublic','congobrazzaville'],
                ['caboverde','capeverde'],
                ['bosniaandherzegovina','bosnia','bosniaherzegovina'],
                ['unitedarabemirates','uae'],
                ['netherlands','holland'],
                ['russia','russianfederation'],
                ['bolivia','boliviaplurinationalstateof'],
                ['venezuela','venezuelabolivarianrepublicof'],
                ['moldova','republicofmoldova'],
                ['syria','syrianarabrepublic'],
                ['tanzania','unitedrepublicoftanzania'],
                ['saudiarabia','ksa'],
            ];
            $map = [];
            foreach ($groups as $g) { foreach ($g as $tok) $map[$tok] = $g; }
        }
        if ($norm === '') return [];
        return $map[$norm] ?? [$norm];
    }
}

if (!function_exists('wc_flag_maps_from_rows')) {
    /** Build name/code flag maps from WC2026_Filter_Countries rows. */
    function wc_flag_maps_from_rows(array $rows): array {
        $byName = []; $byCode = [];
        foreach ($rows as $fr) {
            $fp = trim((string)($fr['flag_path'] ?? ''));
            if ($fp === '') continue;
            $nm = trim((string)($fr['country_name'] ?? ''));
            $cd = trim((string)($fr['country_code'] ?? ''));
            if ($nm !== '') $byName[mb_strtolower($nm)] = $fp;
            if ($cd !== '') $byCode[strtolower($cd)]    = $fp;
            foreach (wc_country_aliases(wc_norm_country($nm)) as $tok) {
                if ($tok !== '' && !isset($byName[$tok])) $byName[$tok] = $fp;
            }
        }
        return ['byName' => $byName, 'byCode' => $byCode];
    }
}

if (!function_exists('wc_load_flag_maps')) {
    /** Load the flag maps directly from WC2026_Filter_Countries (Active rows). */
    function wc_load_flag_maps($conn): array {
        $rows = [];
        try {
            if ($conn && method_exists($conn, 'query')) {
                $res = @$conn->query("SELECT country_name, country_code, flag_path FROM WC2026_Filter_Countries WHERE status='Active'");
                if ($res) { while ($r = $res->fetch_assoc()) $rows[] = $r; }
            }
        } catch (Throwable $e) { /* table missing -> empty maps */ }
        return wc_flag_maps_from_rows($rows);
    }
}

if (!function_exists('wc_flag')) {
    /** Resolve a team/country name to its flag_path from the maps ('' if unknown). */
    function wc_flag(string $team, array $byName, array $byCode): string {
        $k = mb_strtolower(trim($team));
        if ($k === '') return '';
        if (isset($byName[$k])) return $byName[$k];
        if (isset($byCode[$k])) return $byCode[$k];
        foreach (wc_country_aliases(wc_norm_country($team)) as $tok) {
            if (isset($byName[$tok])) return $byName[$tok];
        }
        return '';
    }
}
