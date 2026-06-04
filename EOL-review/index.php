<?php
/**
 * CATRION Asset Lifecycle (End-of-Life) Platform
 * /var/www/html/EOL/index.php
 *
 * Auth:  PRN+OTP (Taqnyat SMS) or Microsoft Entra ID SSO (Azure AD OAuth2).
 * Roles: eol_admins / eol_segment_heads (self-contained; no hf_users).
 * Sync:  Direct pull from ManageEngine ServiceDesk Plus (MSSQL) — no Excel.
 * Logs:  eol_login_log, eol_audit_log, eol_app_log, eol_sync_runs.
 * Config: ALL credentials loaded from /var/secrets/.env — nothing hardcoded.
 *
 * NOTE: This revision extends the IT Asset Lifecycle dashboard with additional
 *       KPIs/measures and visualizations (including cross-source metrics from
 *       eol_intune / eol_ad / eol_darksight). All authentication, database,
 *       sync, upload and business logic is UNCHANGED; the new dashboard
 *       figures are additive, read-only aggregates.
 */
if (session_status() === PHP_SESSION_NONE) session_start();

/* ---------- .env loader ---------- */
(function () {
    $f = '/var/secrets/.env';
    if (!file_exists($f)) return;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#' || strpos($t, '=') === false) continue;
        [$k, $v] = array_map('trim', explode('=', $t, 2));
        if ($k !== '') { $_ENV[$k] = $v; putenv("$k=$v"); }
    }
})();

/* ---------- helpers ---------- */
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function client_ip(){ return $_SERVER['REMOTE_ADDR'] ?? null; }
function client_ua(){ $u=$_SERVER['HTTP_USER_AGENT']??''; return $u!==''?substr($u,0,255):null; }

/* Dashboard query helpers — tolerate a missing source table without fatal */
function dash_row($conn,$sql){ try{ $r=$conn->query($sql); return $r?($r->fetch_assoc()?:[]):[]; }catch(\Throwable $e){ return []; } }
function dash_all($conn,$sql){ try{ $r=$conn->query($sql); $o=[]; if($r) while($x=$r->fetch_assoc())$o[]=$x; return $o; }catch(\Throwable $e){ return []; } }

/* Normalise a hostname/device key for cross-source correlation (strip domain, upper-case) */
function norm_host($s){ $s=strtoupper(trim((string)$s)); if($s==='')return ''; $s=explode('.',$s)[0]; return preg_replace('/\s+/','',$s); }
/* AD "account status" varies by export: Enabled / Yes / True / Active / 1 all mean enabled */
function ad_enabled($v){ $v=strtolower(trim((string)$v)); return in_array($v,['enabled','yes','true','active','1','y','enable'],true); }

const UNIFIED_PAGE=200;

/* Build the full cross-source correlation: returns [list, coverage]. */
function unified_rows($conn){
    $U=[];
    $touch=function($k,$disp)use(&$U){ if($k==='')return; if(!isset($U[$k]))$U[$k]=['name'=>$disp,'me'=>0,'ad'=>0,'intune'=>0,'dark'=>0,'segment'=>'','eol'=>0,'decision'=>'','ad_status'=>'','ad_site'=>'','compliance'=>'','dark_eol'=>0]; };
    foreach(dash_all($conn,"SELECT asset_name,department,is_eol,decision FROM eol_assets LIMIT 20000") as $r){ $k=norm_host($r['asset_name']); $touch($k,$r['asset_name']); if($k){ $U[$k]['me']=1;$U[$k]['segment']=$r['department']??'';$U[$k]['eol']=(int)$r['is_eol'];$U[$k]['decision']=$r['decision']??''; } }
    foreach(dash_all($conn,"SELECT computer_name,account_status,site FROM eol_ad LIMIT 20000") as $r){ $k=norm_host($r['computer_name']); $touch($k,$r['computer_name']); if($k){ $U[$k]['ad']=1;$U[$k]['ad_status']=$r['account_status']??'';$U[$k]['ad_site']=$r['site']??''; } }
    foreach(dash_all($conn,"SELECT device_name,compliance FROM eol_intune LIMIT 20000") as $r){ $k=norm_host($r['device_name']); $touch($k,$r['device_name']); if($k){ $U[$k]['intune']=1;$U[$k]['compliance']=$r['compliance']??''; } }
    foreach(dash_all($conn,"SELECT hostname,eol FROM eol_darksight LIMIT 20000") as $r){ $k=norm_host($r['hostname']); $touch($k,$r['hostname']); if($k){ $U[$k]['dark']=1;$U[$k]['dark_eol']=(int)($r['eol']??0); } }
    $cov=['unique'=>count($U),'core3'=>0,'me'=>0,'ad'=>0,'intune'=>0,'dark'=>0,'me_only'=>0,'ad_only'=>0,'intune_only'=>0,'dark_only'=>0,'unmanaged'=>0,'missing_ad'=>0,'not_in_me'=>0];
    foreach($U as $u){
        $cov['me']+=$u['me'];$cov['ad']+=$u['ad'];$cov['intune']+=$u['intune'];$cov['dark']+=$u['dark'];
        $only=($u['me']+$u['ad']+$u['intune']+$u['dark'])===1;
        if($u['me']&&$u['ad']&&$u['intune'])$cov['core3']++;
        if($only&&$u['me'])$cov['me_only']++; if($only&&$u['ad'])$cov['ad_only']++;
        if($only&&$u['intune'])$cov['intune_only']++; if($only&&$u['dark'])$cov['dark_only']++;
        if(!$u['intune'])$cov['unmanaged']++;
        if($u['me']&&!$u['ad'])$cov['missing_ad']++;
        if(!$u['me']&&($u['ad']||$u['intune']||$u['dark']))$cov['not_in_me']++;
    }
    return [array_values($U),$cov];
}
/* Apply search + filter + sort to the correlation list. */
function unified_apply($list,$uq,$uf,$usort,$udir){
    $cnt=fn($u)=>$u['me']+$u['ad']+$u['intune']+$u['dark'];
    if($uq!=='') $list=array_filter($list,fn($u)=>stripos($u['name'],$uq)!==false||stripos($u['segment'],$uq)!==false||stripos($u['ad_site'],$uq)!==false);
    if($uf==='gaps') $list=array_filter($list,fn($u)=>$cnt($u)<3);
    elseif($uf==='unmanaged') $list=array_filter($list,fn($u)=>!$u['intune']);
    elseif($uf==='eol') $list=array_filter($list,fn($u)=>$u['eol']);
    elseif($uf==='missing_ad') $list=array_filter($list,fn($u)=>$u['me']&&!$u['ad']);
    elseif($uf==='not_in_me') $list=array_filter($list,fn($u)=>!$u['me']&&$cnt($u)>0);
    $list=array_values($list);
    $dir=$udir==='desc'?-1:1;
    $keys=['device','segment','sources','me','ad','intune','dark'];
    $k=in_array($usort,$keys,true)?$usort:'sources';
    usort($list,function($a,$b)use($k,$dir,$cnt){
        if($k==='device') $r=strcasecmp($a['name'],$b['name']);
        elseif($k==='segment') $r=strcasecmp($a['segment'],$b['segment'])?:strcasecmp($a['name'],$b['name']);
        elseif($k==='sources') $r=($cnt($a)<=>$cnt($b))?:strcasecmp($a['name'],$b['name']);
        else { $r=($a[$k]<=>$b[$k])?:strcasecmp($a['name'],$b['name']); }
        return $r*$dir;
    });
    return $list;
}
/* Minimal dependency-free XLSX writer (inline strings). Streams a download then exits. */
function xlsx_download($filename,$headers,$rows){
    if(!class_exists('ZipArchive')){ // graceful CSV fallback
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename='.preg_replace('/\.xlsx$/','.csv',$filename));
        $o=fopen('php://output','w'); fputcsv($o,$headers); foreach($rows as $r) fputcsv($o,$r); fclose($o); exit;
    }
    $colRef=function($i){ $s=''; $i++; while($i>0){ $m=($i-1)%26; $s=chr(65+$m).$s; $i=intdiv($i-1,26); } return $s; };
    $cell=function($ci,$ri,$v)use($colRef){ $v=htmlspecialchars((string)$v,ENT_XML1|ENT_QUOTES,'UTF-8'); return '<c r="'.$colRef($ci).$ri.'" t="inlineStr"><is><t xml:space="preserve">'.$v.'</t></is></c>'; };
    $sheet='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    $ri=1; $line='<row r="1">'; foreach($headers as $ci=>$h) $line.=$cell($ci,1,$h); $line.='</row>'; $sheet.=$line;
    foreach($rows as $r){ $ri++; $line='<row r="'.$ri.'">'; $ci=0; foreach($r as $v){ $line.=$cell($ci,$ri,$v); $ci++; } $line.='</row>'; $sheet.=$line; }
    $sheet.='</sheetData></worksheet>';
    $tmp=tempnam(sys_get_temp_dir(),'xlsx'); $z=new ZipArchive(); $z->open($tmp,ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $z->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Unified" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $z->addFromString('xl/worksheets/sheet1.xml',$sheet);
    $z->close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename='.$filename);
    header('Content-Length: '.filesize($tmp));
    readfile($tmp); @unlink($tmp); exit;
}

/**
 * Minimal dependency-free XLSX reader (first worksheet).
 * Returns [headerRow(array of strings), dataRows(array of numeric arrays)] or [null,null].
 * Uses ZipArchive + SimpleXML — no composer/PhpSpreadsheet needed.
 */
$_XLSX_ERR='';
function read_xlsx($path){
    global $_XLSX_ERR; $_XLSX_ERR='';
    if(!class_exists('ZipArchive')){ $_XLSX_ERR='PHP zip extension is not enabled on the server — cannot read .xlsx. Enable php-zip, or upload a CSV.'; return [null,null]; }
    $zip=new ZipArchive();
    if($zip->open($path)!==true){ $_XLSX_ERR='Not a valid .xlsx (Excel) file.'; return [null,null]; }

    // Strip BOM + namespace prefixes/declarations so SimpleXML works regardless of
    // whether the exporter uses a default namespace (Excel) or an "x:" prefix (Open XML SDK).
    $clean=function($s){
        if($s===false||$s===null) return '';
        $s=preg_replace('/^\xEF\xBB\xBF/','',$s);                       // UTF-8 BOM
        $s=preg_replace('#<(/?)[A-Za-z0-9._-]+:#','<$1',$s);            // <x:row> -> <row>
        $s=preg_replace('/\sxmlns(:[A-Za-z0-9._-]+)?="[^"]*"/','',$s);  // drop xmlns declarations
        $s=preg_replace('/\s[A-Za-z0-9._-]+:([A-Za-z0-9._-]+=)/',' $1',$s); // r:id="x" -> id="x"
        return $s;
    };
    $load=function($xmlStr)use($clean){ libxml_use_internal_errors(true); return @simplexml_load_string($clean($xmlStr)); };

    // shared strings
    $shared=[];
    if(($s=$zip->getFromName('xl/sharedStrings.xml'))!==false){
        $sx=$load($s);
        if($sx) foreach($sx->si as $si){
            $t=''; if(isset($si->t)&&count($si->t)) $t=(string)$si->t;
            if($t===''){ foreach($si->r as $r){ $t.=(string)$r->t; } }   // rich-text runs
            $shared[]=$t;
        }
    }

    // pick the worksheet with the lowest index among all xl/worksheets/sheet*.xml
    $sheetName=''; $low=PHP_INT_MAX;
    for($i=0;$i<$zip->numFiles;$i++){
        $nm=$zip->getNameIndex($i);
        if(preg_match('#^xl/worksheets/sheet(\d+)\.xml$#i',$nm,$m)){ if((int)$m[1]<$low){ $low=(int)$m[1]; $sheetName=$nm; } }
    }
    if($sheetName==='') $sheetName='xl/worksheets/sheet1.xml';
    $sheet=$zip->getFromName($sheetName);
    $zip->close();
    if($sheet===false){ $_XLSX_ERR='No worksheet found inside the .xlsx file.'; return [null,null]; }

    $xml=$load($sheet);
    if(!$xml){ $_XLSX_ERR='Could not parse the worksheet XML.'; return [null,null]; }
    $colIdx=function($ref){ $c=preg_replace('/[0-9]/','',$ref); $n=0; $c=strtoupper($c); for($i=0;$i<strlen($c);$i++){ $n=$n*26+(ord($c[$i])-64); } return $n-1; };
    $data=$xml->sheetData ?? null;
    if($data===null){ $_XLSX_ERR='Worksheet has no data.'; return [null,null]; }
    $rows=[];
    foreach($data->row as $row){
        $cells=[]; $max=-1; $auto=0;
        foreach($row->c as $c){
            $ref=(string)$c['r']; $i=$ref!==''?$colIdx($ref):$auto; $auto=$i+1;
            $type=(string)$c['t']; $val='';
            if($type==='s'){ $val=$shared[(int)$c->v]??''; }
            elseif($type==='inlineStr'){ $val=isset($c->is->t)?(string)$c->is->t:''; }
            elseif($type==='str'){ $val=(string)$c->v; }
            else { $val=isset($c->v)?(string)$c->v:''; }
            $cells[$i]=trim($val); if($i>$max)$max=$i;
        }
        $line=[]; for($i=0;$i<=$max;$i++) $line[]=$cells[$i]??'';
        $rows[]=$line;
    }
    while($rows && count(array_filter($rows[0],fn($x)=>$x!==''))===0) array_shift($rows);
    if(!$rows){ $_XLSX_ERR='The Excel sheet appears to be empty.'; return [null,null]; }
    $hdr=array_map(function($h){return strtolower(trim(preg_replace('/\s+/',' ',preg_replace('/^\xEF\xBB\xBF/','',$h))));},array_shift($rows));
    $rows=array_values(array_filter($rows,fn($r)=>count(array_filter($r,fn($x)=>$x!==''))>0));
    return [$hdr,$rows];
}

/* Inline SVG icon set for KPI tiles / chart headers */
function icon($n,$cls='ic'){
    $p=[
        'box'=>'<path d="m21 8-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8"/>',
        'check-circle'=>'<circle cx="12" cy="12" r="10"/><path d="m8.5 12 2.5 2.5 5-5"/>',
        'check'=>'<path d="M20 6 9 17l-5-5"/>',
        'alert'=>'<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
        'clock'=>'<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'queue'=>'<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'shield'=>'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'activity'=>'<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
        'cpu'=>'<rect x="6" y="6" width="12" height="12" rx="2"/><path d="M9 2v2M15 2v2M9 20v2M15 20v2M2 9h2M2 15h2M20 9h2M20 15h2"/>',
        'cloud'=>'<path d="M17.5 19a4.5 4.5 0 0 0 .5-9 6 6 0 0 0-11.6-1.5A4 4 0 0 0 6.5 19z"/>',
        'users'=>'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>',
        'layers'=>'<path d="m12 2 9 5-9 5-9-5 9-5zM3 12l9 5 9-5M3 17l9 5 9-5"/>',
        'arrow'=>'<path d="M5 12h14M13 6l6 6-6 6"/>',
    ];
    $b=$p[$n]??$p['box'];
    return '<svg class="'.$cls.'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.$b.'</svg>';
}

/* ---------- MySQL (db_form) ---------- */
function get_db(){
    static $c=null; if($c) return $c;
    $m=mysqli_init();
    mysqli_options($m,MYSQLI_OPT_CONNECT_TIMEOUT,5);
    mysqli_options($m,MYSQLI_OPT_READ_TIMEOUT,5);
    if(!@mysqli_real_connect($m,$_ENV['DB_HOST']??'',$_ENV['DB_USER']??'',
        $_ENV['DB_PASS']??'',$_ENV['DB_NAME']??'',(int)($_ENV['DB_PORT']??3306))){
        error_log('EOL MySQL connect: '.mysqli_connect_error()); return null;
    }
    mysqli_set_charset($m,'utf8mb4'); return $c=$m;
}

/* ---------- ManageEngine MSSQL (AMER) ---------- */
$_AMER_LAST_ERROR = '';
function get_amer(){
    global $_AMER_LAST_ERROR; $_AMER_LAST_ERROR='';
    static $c=null; if($c) return $c;
    $srv=$_ENV['AMER_SERVER']??''; $db=$_ENV['AMER_DB_Name']??'';
    $uid=$_ENV['AMER_USERID']??''; $pwd=$_ENV['AMER_PASSWORD']??'';
    if(!$srv){ $_AMER_LAST_ERROR='AMER_SERVER not set in .env'; error_log("EOL AMER: $_AMER_LAST_ERROR"); return null; }
    if(!$db){ $_AMER_LAST_ERROR='AMER_DB_Name not set in .env'; error_log("EOL AMER: $_AMER_LAST_ERROR"); return null; }
    if(!$uid){ $_AMER_LAST_ERROR='AMER_USERID not set in .env'; error_log("EOL AMER: $_AMER_LAST_ERROR"); return null; }

    $drivers=[];
    if(function_exists('sqlsrv_connect')) $drivers[]='sqlsrv';
    if(class_exists('PDO')&&in_array('sqlsrv',PDO::getAvailableDrivers())) $drivers[]='pdo_sqlsrv';
    if(class_exists('PDO')&&in_array('dblib',PDO::getAvailableDrivers())) $drivers[]='pdo_dblib';
    if(!$drivers){ $_AMER_LAST_ERROR='No MSSQL PHP driver installed (need sqlsrv, pdo_sqlsrv, or pdo_dblib). Run: pecl install sqlsrv pdo_sqlsrv'; error_log("EOL AMER: $_AMER_LAST_ERROR"); return null; }

    $tried=[];
    // Try sqlsrv (Microsoft driver) first
    if(function_exists('sqlsrv_connect')){
        $c=@sqlsrv_connect($srv,['Database'=>$db,'UID'=>$uid,'PWD'=>$pwd,
            'LoginTimeout'=>10,'ReturnDatesAsStrings'=>true,'CharacterSet'=>'UTF-8',
            'TrustServerCertificate'=>true,'Encrypt'=>false]);
        if($c) return $c;
        $errs=sqlsrv_errors()?:[];
        $msg=''; foreach($errs as $e) $msg.="[{$e['code']}] {$e['message']} ";
        $tried[]="sqlsrv → ".trim($msg);
    }
    // Fallback: PDO sqlsrv
    if(class_exists('PDO') && in_array('sqlsrv',PDO::getAvailableDrivers())){
        try{ $c=new PDO("sqlsrv:Server=$srv;Database=$db;TrustServerCertificate=1;Encrypt=0;LoginTimeout=10",$uid,$pwd,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
            return $c;
        }catch(\Exception $x){ $tried[]='pdo_sqlsrv → '.$x->getMessage(); }
    }
    // Fallback: PDO dblib (FreeTDS on Linux)
    if(class_exists('PDO') && in_array('dblib',PDO::getAvailableDrivers())){
        try{ $c=new PDO("dblib:host=$srv;dbname=$db",$uid,$pwd,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
            return $c;
        }catch(\Exception $x){ $tried[]='pdo_dblib → '.$x->getMessage(); }
    }
    $_AMER_LAST_ERROR="Connection failed to $srv/$db (user: $uid). Drivers available: ".implode(', ',$drivers).". Attempts: ".implode(' | ',$tried);
    error_log("EOL AMER: $_AMER_LAST_ERROR"); return null;
}
/** Run a SELECT on the AMER (ManageEngine) connection; returns array of rows */
function amer_query($sql){
    $c=get_amer(); if(!$c) return false;
    if($c instanceof PDO){
        try{ $st=$c->query($sql); return $st?$st->fetchAll(PDO::FETCH_ASSOC):false; }
        catch(\Exception $x){ global $_AMER_LAST_ERROR; $_AMER_LAST_ERROR='Query error: '.$x->getMessage(); return false; }
    }
    // sqlsrv path
    $st=sqlsrv_query($c,$sql);
    if(!$st){ $errs=sqlsrv_errors()?:[]; $msg=''; foreach($errs as $e) $msg.="[{$e['code']}] {$e['message']} ";
        global $_AMER_LAST_ERROR; $_AMER_LAST_ERROR='Query failed: '.trim($msg); return false; }
    $rows=[]; while($r=sqlsrv_fetch_array($st,SQLSRV_FETCH_ASSOC)) $rows[]=$r;
    sqlsrv_free_stmt($st); return $rows;
}

/* ---------- Taqnyat OTP ---------- */
function taqnyat_msisdn($raw){
    $d=preg_replace('/\D+/','',(string)$raw); if($d==='') return '';
    if(strpos($d,'966')!==0) $d='966'.ltrim($d,'0'); return $d;
}
function sendOTP($conn,$phone,$otp){
    $url=$_ENV['TAQNYAT_API']??'https://api.taqnyat.sa/v1/messages';
    $token=$_ENV['TAQNYAT_TOKEN_RPA']??'';
    $sender=$_ENV['TAQNYAT_SENDER_RPA']??'CATRION-IT';
    if(!$token){ applog($conn,'ERROR','otp','Taqnyat token missing'); return false; }
    $payload=['recipients'=>[$phone],'body'=>"Your verification code: $otp For CATRION Asset Lifecycle portal.",'sender'=>$sender];
    $ch=curl_init(); curl_setopt_array($ch,[CURLOPT_URL=>$url,CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER=>["Authorization: Bearer $token","Content-Type: application/json"],
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);
    $resp=curl_exec($ch); $http=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $ok=($resp!==false && $http>=200 && $http<300);
    if(!$ok) applog($conn,'ERROR','otp','Taqnyat send failed http='.$http);
    return $ok;
}
function otp_value(){
    return str_pad((string)random_int(0,9999),4,'0',STR_PAD_LEFT);
}
function otp_matches($entered,$stored,$expiry){
    return $entered!=='' && $entered===(string)$stored && $stored!==null && $stored!=='' && strtotime((string)$expiry)>time();
}

/* ---------- logging ---------- */
function applog($conn,$level,$ctx,$msg,$prn=null){
    error_log("EOL[$level][$ctx] $msg");
    if(!$conn) return; $ip=client_ip();
    if($st=$conn->prepare("INSERT INTO eol_app_log (level,context,message,prn,ip_address) VALUES (?,?,?,?,?)")){
        $st->bind_param('sssss',$level,$ctx,$msg,$prn,$ip); $st->execute(); $st->close(); }
}
function login_event($conn,$prn,$name,$role,$status){
    if(!$conn) return; $ip=client_ip(); $ua=client_ua();
    if($st=$conn->prepare("INSERT INTO eol_login_log (prn,full_name,role,status,ip_address,user_agent) VALUES (?,?,?,?,?,?)")){
        $st->bind_param('ssssss',$prn,$name,$role,$status,$ip,$ua); $st->execute(); $st->close(); }
}
function audit($conn,$action,$entityType,$entityId,$tag,$detail,$meta=null){
    if(!$conn) return; $ip=client_ip(); $ua=client_ua();
    $aprn=$_SESSION['eol_prn']??null; $aname=$_SESSION['eol_name']??null; $arole=$_SESSION['eol_role']??null;
    $mj=$meta!==null?json_encode($meta,JSON_UNESCAPED_UNICODE):null;
    if($st=$conn->prepare("INSERT INTO eol_audit_log (actor_prn,actor_name,actor_role,action,entity_type,entity_id,asset_tag,detail,meta,ip_address,user_agent) VALUES (?,?,?,?,?,?,?,?,?,?,?)")){
        $st->bind_param('sssssisssss',$aprn,$aname,$arole,$action,$entityType,$entityId,$tag,$detail,$mj,$ip,$ua);
        $st->execute(); $st->close(); }
}

/* ---------- role (self-contained: eol_admins / eol_segment_heads) ---------- */
function resolve_role($conn,$prn){
    $out=['role'=>'none','scope'=>'','departments'=>[]];
    if(!$conn||$prn==='') return $out;
    if($st=$conn->prepare("SELECT role,scope FROM eol_admins WHERE prn=? AND is_active=1 LIMIT 1")){
        $st->bind_param('s',$prn); $st->execute();
        if($row=$st->get_result()->fetch_assoc()){ $st->close(); $out['role']='admin'; $out['scope']=$row['scope']; return $out; }
        $st->close(); }
    if($st=$conn->prepare("SELECT department FROM eol_segment_heads WHERE head_prn=? AND is_active=1")){
        $st->bind_param('s',$prn); $st->execute(); $r=$st->get_result();
        while($row=$r->fetch_assoc()) $out['departments'][]=$row['department']; $st->close(); }
    if($out['departments']) $out['role']='head';
    return $out;
}

/* ---------- SSO (shared launcher at /SSO/sso_login.php) ---------- */
function sso_available(){ return true; /* shared SSO is always available */ }
function sso_url(){ return '/SSO/sso_login.php?app=eol&return=/EOL/index.php'; }
/* Decode the OAuth "state" robustly: tolerates base64url, URL-encoding,
   missing padding, or a state that is already plain JSON. Returns array|null. */
function sso_decode_state($raw){
    $raw=trim((string)$raw); if($raw==='') return null;
    foreach([$raw, urldecode($raw)] as $s){
        foreach([$s, strtr($s,'-_','+/')] as $b){
            $pad=strlen($b)%4; if($pad) $b.=str_repeat('=',4-$pad);
            $dec=base64_decode($b,true);
            if($dec!==false){ $j=json_decode($dec,true); if(is_array($j)) return $j; }
        }
        $j=json_decode($s,true); if(is_array($j)) return $j;   // already plain JSON
    }
    return null;
}

/* ---------- ManageEngine sync ---------- */
function sync_from_amer($conn,&$error,&$summary){
    $summary=['processed'=>0,'inserted'=>0,'updated'=>0,'skipped'=>0];
    if(!$conn){ $error='MySQL connection failed.'; return; }
    $amerRows=amer_query("
        SELECT
            r.RESOURCEID AS me_resource_id, r.RESOURCENAME AS asset_name,
            LTRIM(RTRIM(ISNULL(usr.FIRSTNAME,'')+CASE WHEN usr.LASTNAME IS NOT NULL AND usr.LASTNAME<>'' THEN ' '+usr.LASTNAME ELSE '' END)) AS asset_user,
            si.LOGGEDONUSER AS last_login_user,
            rs.DISPLAYSTATE AS asset_state,
            dept.DEPTNAME AS department,
            CASE WHEN aaf.LAST_SCAN_SUCCESS_TIME IS NOT NULL AND (aaf.LAST_SCAN_FAILURE_TIME IS NULL OR aaf.LAST_SCAN_SUCCESS_TIME>=aaf.LAST_SCAN_FAILURE_TIME) THEN 'SUCCESS'
                 WHEN aaf.LAST_SCAN_FAILURE_TIME IS NOT NULL THEN 'FAILED' ELSE ISNULL(sjs.STATUS,'UNKNOWN') END AS last_scan_status,
            r.IPADDRESSES AS ip_addresses,
            si.SERVICETAG AS service_tag,
            si.MANUFACTURER AS manufacturer, si.MODEL AS model,
            CASE WHEN r.WARRANTYEXPIRY IS NOT NULL AND r.WARRANTYEXPIRY>0 THEN CONVERT(DATE,DATEADD(SECOND,r.WARRANTYEXPIRY/1000,'1970-01-01')) ELSE NULL END AS warranty_expiry,
            CASE WHEN r.WARRANTYEXPIRY IS NOT NULL AND r.WARRANTYEXPIRY>0 AND DATEADD(SECOND,r.WARRANTYEXPIRY/1000,'1970-01-01')<DATEADD(YEAR,-4,GETDATE()) THEN 1 ELSE 0 END AS over_four_years
        FROM dbo.Resources r
        INNER JOIN dbo.SystemInfo si ON si.WORKSTATIONID=r.RESOURCEID
        LEFT JOIN dbo.ResourceState rs ON rs.RESOURCESTATEID=r.RESOURCESTATEID
        LEFT JOIN dbo.ResourceOwner ro ON ro.RESOURCEID=r.RESOURCEID
        LEFT JOIN dbo.SDUser usr ON usr.USERID=ro.USERID
        LEFT JOIN dbo.DepartmentDefinition dept ON dept.DEPTID=ro.DEPTID
        LEFT JOIN dbo.AssetActivityField aaf ON aaf.RESOURCEID=r.RESOURCEID
        LEFT JOIN dbo.ScanJobStatuses sjs ON sjs.STATUSID=aaf.SCAN_STATUS_ID
        ORDER BY r.RESOURCENAME
    ");
    if($amerRows===false){ global $_AMER_LAST_ERROR;
        $error='ManageEngine sync failed. '.$_AMER_LAST_ERROR;
        applog($conn,'ERROR','sync','AMER connection/query failed: '.$_AMER_LAST_ERROR); return; }
    $stmt=$conn->prepare("INSERT INTO eol_assets
        (asset_name,asset_user,last_login_user,asset_state,department,last_scan_status,
         ip_addresses,service_tag,manufacturer,model,warranty_expiry,over_four_years,source_synced_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE asset_name=VALUES(asset_name),asset_user=VALUES(asset_user),
        last_login_user=VALUES(last_login_user),asset_state=VALUES(asset_state),department=VALUES(department),
        last_scan_status=VALUES(last_scan_status),ip_addresses=VALUES(ip_addresses),
        manufacturer=VALUES(manufacturer),model=VALUES(model),warranty_expiry=VALUES(warranty_expiry),
        over_four_years=VALUES(over_four_years),source_synced_at=NOW()");
    if(!$stmt){ $error='MySQL prepare failed: '.$conn->error; return; }
    foreach($amerRows as $r){
        $tag=trim($r['service_tag']??'');
        if($tag===''||$tag==='NULL'){ $summary['skipped']++; continue; }
        $summary['processed']++;
        $name=trim($r['asset_name']??''); $user=trim($r['asset_user']??'');
        $login=($r['last_login_user']??null)==='NULL'?'':trim($r['last_login_user']??'');
        $state=trim($r['asset_state']??''); $dept=($r['department']??null)==='NULL'?'':trim($r['department']??'');
        $scan=trim($r['last_scan_status']??''); $ip=trim($r['ip_addresses']??'');
        $mfr=($r['manufacturer']??null)==='NULL'?'':trim($r['manufacturer']??'');
        $model=($r['model']??null)==='NULL'?'':trim($r['model']??'');
        $warr=$r['warranty_expiry']??null; if($warr==='NULL'||$warr==='')$warr=null;
        if($warr && is_object($warr)) $warr=$warr->format('Y-m-d');
        $over4=(int)($r['over_four_years']??0);
        $stmt->bind_param('sssssssssssi',$name,$user,$login,$state,$dept,$scan,$ip,$tag,$mfr,$model,$warr,$over4);
        if($stmt->execute()){ $stmt->affected_rows===1?$summary['inserted']++:$summary['updated']++; }
        else { $summary['skipped']++; }
    }
    $stmt->close();
}

/* ============ REQUEST HANDLING ============ */
$conn=get_db();
$error=''; $success=''; $showOtp=false;
if(!$conn) applog(null,'ERROR','db','MySQL connection failed');

if(isset($_POST['logout'])){
    login_event($conn,$_SESSION['eol_prn']??null,$_SESSION['eol_name']??null,$_SESSION['eol_role']??null,'LOGOUT');
    session_unset(); session_destroy(); header('Location: index.php'); exit();
}
$loggedIn=!empty($_SESSION['eol_authed']);
$role=$_SESSION['eol_role']??'none'; $prn=$_SESSION['eol_prn']??'';
$fullName=$_SESSION['eol_name']??''; $myDepts=$_SESSION['eol_departments']??[];

/* SSO callback: bridge stores code/state in sessionStorage+cookies, redirects here with ?sso=1 */
if(!$loggedIn && isset($_GET['sso'])){
    // Check POST first (from our JS below), then cookies
    $sso_code=$_POST['sso_code']??$_COOKIE['sso_code']??'';
    $sso_state=$_POST['sso_state']??$_COOKIE['sso_state']??'';
    error_log('EOL SSO callback: code='.($sso_code?'yes('.strlen($sso_code).')':'EMPTY').' state='.($sso_state?'yes('.strlen($sso_state).')':'EMPTY').' method='.$_SERVER['REQUEST_METHOD']);
    if($sso_code!=='' && $sso_state!==''){
        $sso_ok=false; $sso_err='';
        try {
            $sso_autoload=__DIR__.'/../SSO/vendor/autoload.php';
            if(!file_exists($sso_autoload)) throw new \RuntimeException('SSO vendor not found.');
            require_once __DIR__.'/../SSO/connections/sso_config.php';
            require_once $sso_autoload;

            // Validate CSRF state
            $statePayload=sso_decode_state($sso_state);
            if(!is_array($statePayload)||empty($statePayload['csrf'])) throw new \RuntimeException('Bad SSO state (could not decode the state token).');
            // CSRF token may live in this PHP session OR in a cookie set by the shared launcher.
            $expectCsrf=(string)$statePayload['csrf'];
            $sessCsrf=$_SESSION['sso_csrf']??''; $cookieCsrf=$_COOKIE['sso_csrf']??'';
            if($sessCsrf===''&&$cookieCsrf!==''){$_SESSION['sso_csrf']=$cookieCsrf;$sessCsrf=$cookieCsrf;}
            $csrfOk = ($sessCsrf!==''&&hash_equals($sessCsrf,$expectCsrf)) || ($cookieCsrf!==''&&hash_equals($cookieCsrf,$expectCsrf));
            if(!$csrfOk){ error_log('EOL SSO CSRF: sess='.($sessCsrf?'set':'empty').' cookie='.($cookieCsrf?'set':'empty').' state_csrf='.($expectCsrf?'set':'empty')); throw new \RuntimeException('Session expired during sign-in (CSRF). Please click “Continue with Microsoft SSO” again.'); }

            // Exchange code for token
            $provider=new \TheNetworg\OAuth2\Client\Provider\Azure([
                'clientId'=>OAUTH_CLIENT_ID,'clientSecret'=>OAUTH_CLIENT_SECRET,
                'redirectUri'=>OAUTH_REDIRECT_URI,
                'scopes'=>['openid','profile','email','offline_access','https://graph.microsoft.com/.default'],
                'defaultEndPointVersion'=>'2.0','tenant'=>OAUTH_TENANT_ID,
            ]);
            $provider->resource='https://graph.microsoft.com/';
            $accessToken=$provider->getAccessToken('authorization_code',['code'=>$sso_code]);

            // Get email from Graph /me
            $graph=new \Microsoft\Graph\Graph();
            $graph->setAccessToken($accessToken->getToken());
            $me=$graph->createRequest('GET','/me?$select=mail,displayName,userPrincipalName')
                ->setReturnType(\Microsoft\Graph\Model\User::class)->execute();
            $email=$me->getMail()?:$me->getUserPrincipalName();
            if(!$email) throw new \RuntimeException('No email from Microsoft.');

            // Look up PRN
            if(!$conn) throw new \RuntimeException('Database unavailable.');
            $st=$conn->prepare("SELECT PRN,FULL_NAME FROM Unified_Employees_MasterList WHERE LOWER(Email)=LOWER(?) LIMIT 1");
            $st->bind_param('s',$email); $st->execute(); $emp=$st->get_result()->fetch_assoc(); $st->close();
            if(!$emp){ login_event($conn,null,$email,null,'FAIL_NOT_FOUND'); throw new \RuntimeException("Email $email not in master list."); }

            // Resolve EOL role
            $rr=resolve_role($conn,$emp['PRN']);
            if($rr['role']==='none'){ login_event($conn,$emp['PRN'],$emp['FULL_NAME'],null,'FAIL_UNAUTHORIZED'); throw new \RuntimeException('Not authorized for Asset Lifecycle.'); }

            // Set session
            session_regenerate_id(true);
            $_SESSION['eol_authed']=true; $_SESSION['eol_prn']=$emp['PRN'];
            $_SESSION['eol_name']=$emp['FULL_NAME']; $_SESSION['eol_role']=$rr['role'];
            $_SESSION['eol_scope']=$rr['scope']; $_SESSION['eol_departments']=$rr['departments'];
            if($rr['role']==='admin'){ $u=$conn->prepare("UPDATE eol_admins SET last_login=NOW() WHERE prn=?");
                $u->bind_param('s',$emp['PRN']); $u->execute(); $u->close(); }
            login_event($conn,$emp['PRN'],$emp['FULL_NAME'],$rr['role'],'SUCCESS');
            audit($conn,'LOGIN','session',null,null,'Signed in via Microsoft SSO');

            // Clear SSO cookies
            unset($_SESSION['sso_return'],$_SESSION['sso_csrf'],$_SESSION['oauth2state']);
            foreach(['sso_state','sso_csrf','sso_return','prov_state','sso_code'] as $ck)
                setcookie($ck,'',time()-3600,'/EOL','',true,true);

            header('Location: /EOL/index.php'); exit();
        } catch(\Throwable $ex) {
            $error='SSO: '.$ex->getMessage();
            error_log('EOL SSO error: '.$ex->getMessage());
        }
    } else if($_SERVER['REQUEST_METHOD']==='GET'){
        // Cookies might be empty (code too large). Use JS to read sessionStorage and POST.
        error_log('EOL SSO: GET with empty cookies, will try sessionStorage via JS');
        ?><!doctype html><html><head><meta charset="utf-8"><title>Signing in…</title>
        <style>body{font-family:'Gotham',system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f4f8ff;color:#003F53}
        .box{text-align:center;padding:40px}.spin{display:inline-block;width:32px;height:32px;border:3px solid #d7e5f7;border-top-color:#5A92DB;border-radius:50%;animation:r .6s linear infinite}@keyframes r{to{transform:rotate(360deg)}}</style></head>
        <body><div class="box"><div class="spin"></div><p>Completing sign-in…</p></div>
        <script>
        (function(){
            var code = sessionStorage.getItem('sso_code') || '';
            var state = sessionStorage.getItem('sso_state') || '';
            if (!code || !state) {
                document.querySelector('p').textContent = 'SSO session data not found. Redirecting…';
                setTimeout(function(){ location.replace('/EOL/index.php'); }, 2000);
                return;
            }
            // Submit via hidden form POST
            var f = document.createElement('form');
            f.method = 'POST';
            f.action = '/EOL/index.php?sso=1';
            var ic = document.createElement('input'); ic.type='hidden'; ic.name='sso_code'; ic.value=code; f.appendChild(ic);
            var is = document.createElement('input'); is.type='hidden'; is.name='sso_state'; is.value=state; f.appendChild(is);
            document.body.appendChild(f);
            // Clear sessionStorage
            sessionStorage.removeItem('sso_code');
            sessionStorage.removeItem('sso_state');
            f.submit();
        })();
        </script></body></html><?php
        exit();
    } else {
        $error='SSO authentication failed. Please try again.';
        error_log('EOL SSO: POST but code/state still empty');
    }
}

/* PRN + OTP login */
if(!$loggedIn && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['eol_login'])){
    $inPrn=preg_replace('/\D+/','',trim($_POST['login_prn']??''));
    if(!preg_match('/^140\d{5}$/',$inPrn)){
        $error='Invalid PRN format. Use 140xxxxx.'; login_event($conn,$inPrn,null,null,'FAIL_FORMAT');
    } elseif(!$conn){ $error='Database connection failed.';
    } else {
        $st=$conn->prepare("SELECT PRN,FULL_NAME,Mobile,otp,otp_expiry FROM Unified_Employees_MasterList WHERE PRN=? LIMIT 1");
        $st->bind_param('s',$inPrn); $st->execute(); $emp=$st->get_result()->fetch_assoc(); $st->close();
        $rr=resolve_role($conn,$inPrn);
        if(!$emp){ $error='PRN not found.'; login_event($conn,$inPrn,null,null,'FAIL_NOT_FOUND'); }
        elseif($rr['role']==='none'){ $error='Not authorized for Asset Lifecycle.'; login_event($conn,$inPrn,$emp['FULL_NAME'],null,'FAIL_UNAUTHORIZED'); }
        elseif(isset($_POST['otp'])){
            if(otp_matches(trim($_POST['otp']),$emp['otp'],$emp['otp_expiry'])){
                $_SESSION['eol_authed']=true; $_SESSION['eol_prn']=$emp['PRN'];
                $_SESSION['eol_name']=$emp['FULL_NAME']; $_SESSION['eol_role']=$rr['role'];
                $_SESSION['eol_scope']=$rr['scope']; $_SESSION['eol_departments']=$rr['departments'];
                $clr=$conn->prepare("UPDATE Unified_Employees_MasterList SET otp=NULL,otp_expiry=NULL WHERE PRN=?");
                $clr->bind_param('s',$inPrn); $clr->execute(); $clr->close();
                if($rr['role']==='admin'){ $u=$conn->prepare("UPDATE eol_admins SET last_login=NOW() WHERE prn=?");
                    $u->bind_param('s',$inPrn); $u->execute(); $u->close(); }
                login_event($conn,$emp['PRN'],$emp['FULL_NAME'],$rr['role'],'SUCCESS');
                audit($conn,'LOGIN','session',null,null,'Signed in via PRN+OTP');
                header('Location: index.php'); exit();
            } else { $error='Invalid or expired OTP.'; $showOtp=true; $_SESSION['eol_pending_prn']=$inPrn;
                login_event($conn,$inPrn,$emp['FULL_NAME'],$rr['role'],'FAIL_OTP'); }
        } else {
            $otp=otp_value(); $exp=date('Y-m-d H:i:s',strtotime('+10 minutes'));
            $u=$conn->prepare("UPDATE Unified_Employees_MasterList SET otp=?,otp_expiry=? WHERE PRN=?");
            $u->bind_param('sss',$otp,$exp,$inPrn); $u->execute(); $u->close();
            $mobile=taqnyat_msisdn($emp['Mobile']??'');
            if(!$mobile){ $error='No mobile number on file.'; }
            elseif(sendOTP($conn,$mobile,$otp)){
                $_SESSION['eol_pending_prn']=$inPrn; $showOtp=true;
                login_event($conn,$inPrn,$emp['FULL_NAME'],$rr['role'],'OTP_SENT');
                $success='Verification code sent to your registered mobile.';
            } else { $error='Failed to send OTP.'; }
        }
    }
}

/* ADMIN actions */
if($loggedIn && $role==='admin' && $_SERVER['REQUEST_METHOD']==='POST'){
    if(isset($_POST['toggle_eol'])){
        $id=(int)$_POST['asset_id'];
        $row=$conn->query("SELECT is_eol,service_tag FROM eol_assets WHERE id=".$id)->fetch_assoc();
        $tag=$row['service_tag']??null; $new=$row&&(int)$row['is_eol']===1?0:1;
        if($new===1){ $note=trim($_POST['eol_note']??''); if($note==='')$note='Flagged End-of-Life by IT.';
            $st=$conn->prepare("UPDATE eol_assets SET is_eol=1,eol_note=?,eol_flagged_by=?,eol_flagged_at=NOW() WHERE id=?");
            $st->bind_param('ssi',$note,$prn,$id);
        } else { $st=$conn->prepare("UPDATE eol_assets SET is_eol=0,decision=NULL,decision_note=NULL,decided_by_prn=NULL,decided_by_name=NULL,decided_at=NULL WHERE id=?");
            $st->bind_param('i',$id); }
        $st->execute(); $st->close();
        audit($conn,$new?'EOL_FLAG':'EOL_UNFLAG','asset',$id,$tag,$new?'Flagged EoL':'Set Active');
        $success=$new?'Device flagged End-of-Life.':'Device set Active.';
    }
    if(isset($_POST['save_warranty'])){
        $id=(int)$_POST['asset_id']; $w=trim($_POST['warranty_expiry']??''); $w=$w?:null;
        $row=$conn->query("SELECT service_tag FROM eol_assets WHERE id=".$id)->fetch_assoc();
        $st=$conn->prepare("UPDATE eol_assets SET warranty_expiry=? WHERE id=?"); $st->bind_param('si',$w,$id); $st->execute(); $st->close();
        audit($conn,'WARRANTY_EDIT','asset',$id,$row['service_tag']??null,'Warranty → '.($w??'null'));
        $success='Warranty updated.';
    }
    if(isset($_POST['sync_amer'])){
        $syncErr=''; $summary=null;
        sync_from_amer($conn,$syncErr,$summary);
        $status=$syncErr===''?'OK':'ERROR';
        if($st=$conn->prepare("INSERT INTO eol_sync_runs (run_by_prn,run_by_name,source,processed,inserted,updated,skipped,status,message) VALUES (?,?,?,?,?,?,?,?,?)")){
            $src='ManageEngine AMER'; $st->bind_param('sssiiiiss',$prn,$fullName,$src,
                $summary['processed'],$summary['inserted'],$summary['updated'],$summary['skipped'],$status,$syncErr);
            $st->execute(); $st->close(); }
        audit($conn,'ASSET_SYNC','report',null,null,'Sync from ManageEngine',$summary);
        if($syncErr==='') $success="Sync complete — {$summary['processed']} processed, {$summary['inserted']} new, {$summary['updated']} updated, {$summary['skipped']} skipped.";
        else $error=$syncErr;
    }
    if(isset($_POST['save_admin'])){
        $ap=preg_replace('/\D+/','',trim($_POST['admin_prn']??''));
        [$arole,$ascope]=array_pad(explode(' · ',$_POST['admin_role']??'IT Admin · All segments'),2,'All segments');
        $chk=$conn->prepare("SELECT 1 FROM Unified_Employees_MasterList WHERE PRN=? LIMIT 1");
        $chk->bind_param('s',$ap); $chk->execute(); $exists=$chk->get_result()->fetch_assoc(); $chk->close();
        if(!preg_match('/^140\d{5}$/',$ap)) $error='Invalid PRN.';
        elseif(!$exists) $error='PRN not in master list.';
        else { $st=$conn->prepare("INSERT INTO eol_admins (prn,role,scope,is_active) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE role=VALUES(role),scope=VALUES(scope),is_active=1");
            $st->bind_param('sss',$ap,$arole,$ascope); $st->execute(); $st->close();
            audit($conn,'ADMIN_ADD','admin',null,null,"Admin $ap ($arole)"); $success='Admin saved.'; }
    }
    if(isset($_POST['admin_action'])){
        $ap=preg_replace('/\D+/','',trim($_POST['admin_prn']??'')); $act=$_POST['admin_action'];
        if($act==='suspend'){ $conn->query("UPDATE eol_admins SET is_active=0 WHERE prn='".$conn->real_escape_string($ap)."'"); audit($conn,'ADMIN_SUSPEND','admin',null,null,"Suspended $ap"); $success='Suspended.'; }
        elseif($act==='activate'){ $conn->query("UPDATE eol_admins SET is_active=1 WHERE prn='".$conn->real_escape_string($ap)."'"); audit($conn,'ADMIN_ACTIVATE','admin',null,null,"Activated $ap"); $success='Activated.'; }
        elseif($act==='remove'){ $conn->query("DELETE FROM eol_admins WHERE prn='".$conn->real_escape_string($ap)."'"); audit($conn,'ADMIN_REMOVE','admin',null,null,"Removed $ap"); $success='Removed.'; }
    }
    if(isset($_POST['save_head'])){
        $dept=trim($_POST['department']??''); $hp=preg_replace('/\D+/','',trim($_POST['head_prn']??''));
        if($dept===''||!preg_match('/^140\d{5}$/',$hp)) $error='Department and valid PRN required.';
        else { $st=$conn->prepare("INSERT INTO eol_segment_heads (department,head_prn,is_active) VALUES (?,?,1) ON DUPLICATE KEY UPDATE head_prn=VALUES(head_prn),is_active=1");
            $st->bind_param('ss',$dept,$hp); $st->execute(); $st->close();
            audit($conn,'HEAD_MAP','segment_head',null,null,"$dept → $hp"); $success='Mapping saved.'; }
    }
    /* CSV uploads for data sources */
    if(isset($_POST['upload_source']) && isset($_FILES['csv_file']) && $_FILES['csv_file']['error']===UPLOAD_ERR_OK){
        $source=$_POST['upload_source']; $site=trim($_POST['ad_site']??'');
        $rows=[]; $hdr=null;
        $tmp=$_FILES['csv_file']['tmp_name'];
        // Detect format by content, not extension: a .xlsx/.xlsm is a ZIP (PK\x03\x04).
        $magic=@file_get_contents($tmp,false,null,0,4);
        if($magic==="PK\x03\x04"){
            [$hdr,$rows]=read_xlsx($tmp);
            if(!$hdr){ global $_XLSX_ERR; $error=$_XLSX_ERR?:'Could not read the Excel file (need .xlsx with a header row).'; }
        } else {
            // Delimited text — strip BOM and auto-detect delimiter (comma / tab / semicolon / pipe)
            $raw=@file_get_contents($tmp);
            if($raw===false||$raw===''){ $error='File is empty or unreadable.'; }
            else {
                $raw=preg_replace('/^\xEF\xBB\xBF/','',$raw);
                $firstLine=strtok($raw,"\r\n"); $delim=','; $best=-1;
                foreach([','=>substr_count($firstLine,','),"\t"=>substr_count($firstLine,"\t"),';'=>substr_count($firstLine,';'),'|'=>substr_count($firstLine,'|')] as $d=>$cnt){ if($cnt>$best){ $best=$cnt; $delim=$d; } }
                $fh=fopen('php://temp','r+'); fwrite($fh,$raw); rewind($fh);
                $rawHdr=fgetcsv($fh,0,$delim);
                if($rawHdr) $hdr=array_map(function($h){return strtolower(trim(preg_replace('/\s+/',' ',$h)));},$rawHdr);
                while(($r=fgetcsv($fh,0,$delim))!==false){ if(count(array_filter($r,fn($x)=>trim((string)$x)!==''))) $rows[]=$r; }
                fclose($fh);
            }
        }
        if($error!==''){ /* keep prior parse error */ }
        elseif(!$hdr||!$rows){ $error='File is empty or unreadable (no header row or no data found).'; }
        else {
            $col=function($name)use($hdr){ $n=strtolower(trim($name));
                foreach($hdr as $i=>$h){if($h===$n||str_replace(' ','_',$h)===$n||str_replace('_',' ',$h)===$n)return $i;} return null; };
            $v=function($row,$name)use($col){$i=$col($name);return($i!==null&&isset($row[$i])&&trim($row[$i])!=='')?trim($row[$i]):null;};
            $esc=function($val)use($conn){return $val!==null?"'".$conn->real_escape_string($val)."'":"NULL";};
            $dt=function($val){if(!$val)return 'NULL';$t=strtotime($val);return $t?"'".date('Y-m-d H:i:s',$t)."'":"NULL";};
            $num=function($val){return($val!==null&&is_numeric($val))?(int)$val:"NULL";};
            $ins=0;$skip=0;$total=count($rows);

            if($source==='darksight'){
                foreach($rows as $r){
                    $h=$v($r,'hostname'); if(!$h){$skip++;continue;}
                    $sql="INSERT INTO eol_darksight (hostname,owner,domain,patch_level,ins,eol,dsc,pat,total,scan_type,operating_system,ip_addresses,ip_subnets,last_scan,source_synced_at) VALUES (
                        ".$esc($h).",".$esc($v($r,'owner')).",".$esc($v($r,'domain')).",".$esc($v($r,'patch level')).",
                        ".$num($v($r,'ins')).",".$num($v($r,'eol')).",".$num($v($r,'dsc')).",".$num($v($r,'pat')).",".$num($v($r,'total')).",
                        ".$esc($v($r,'scan type')).",".$esc($v($r,'operating system')).",".$esc($v($r,'ip addresses')).",".$esc($v($r,'ip subnets')).",
                        ".$dt($v($r,'last scan')).",NOW())
                    ON DUPLICATE KEY UPDATE owner=VALUES(owner),domain=VALUES(domain),patch_level=VALUES(patch_level),
                        ins=VALUES(ins),eol=VALUES(eol),dsc=VALUES(dsc),pat=VALUES(pat),total=VALUES(total),
                        scan_type=VALUES(scan_type),operating_system=VALUES(operating_system),ip_addresses=VALUES(ip_addresses),
                        ip_subnets=VALUES(ip_subnets),last_scan=VALUES(last_scan),source_synced_at=NOW()";
                    if($conn->query($sql))$ins++;else $skip++;
                }
            } elseif($source==='intune'){
                foreach($rows as $r){
                    $did=$v($r,'device id'); if(!$did){$skip++;continue;}
                    $sql="INSERT INTO eol_intune (device_id,device_name,managed_by,ownership,compliance,os,os_version,primary_user_upn,last_checkin,intune_registered,model,serial_number,primary_user_display_name,source_synced_at) VALUES (
                        ".$esc($did).",".$esc($v($r,'device name')).",".$esc($v($r,'managed by')).",".$esc($v($r,'ownership')).",
                        ".$esc($v($r,'compliance')).",".$esc($v($r,'os')).",".$esc($v($r,'os version')).",".$esc($v($r,'primary user upn')).",
                        ".$dt($v($r,'last check-in')).",".$dt($v($r,'intune registered')).",".$esc($v($r,'model')).",
                        ".$esc($v($r,'serial number')).",".$esc($v($r,'primary user display name')).",NOW())
                    ON DUPLICATE KEY UPDATE device_name=VALUES(device_name),managed_by=VALUES(managed_by),ownership=VALUES(ownership),
                        compliance=VALUES(compliance),os=VALUES(os),os_version=VALUES(os_version),primary_user_upn=VALUES(primary_user_upn),
                        last_checkin=VALUES(last_checkin),intune_registered=VALUES(intune_registered),model=VALUES(model),
                        serial_number=VALUES(serial_number),primary_user_display_name=VALUES(primary_user_display_name),source_synced_at=NOW()";
                    if($conn->query($sql))$ins++;else $skip++;
                }
            } elseif($source==='ad'){
                foreach($rows as $r){
                    $cn=$v($r,'computer name'); if(!$cn){$skip++;continue;}
                    $sql="INSERT INTO eol_ad (computer_name,account_status,last_logon_timestamp,os_name,os_version,machine_type,dns_host_name,description,ou,managed_by,sid,sam_account_name,last_logon,modified_date,distinguished_name,location,site,source_synced_at) VALUES (
                        ".$esc($cn).",".$esc($v($r,'account status')).",".$dt($v($r,'last logon timestamp')).",
                        ".$esc($v($r,'os name')).",".$esc($v($r,'os version')).",".$esc($v($r,'machine type')).",
                        ".$esc($v($r,'dns host name')).",".$esc($v($r,'description')).",".$esc($v($r,'ou')).",
                        ".$esc($v($r,'managed by')).",".$esc($v($r,'sid')).",".$esc($v($r,'samaccountname')).",
                        ".$dt($v($r,'last logon')).",".$dt($v($r,'modified date')).",
                        ".$esc($v($r,'distinguished name')).",".$esc($v($r,'location')).",
                        ".($site!==''?$esc($site):"NULL").",NOW())
                    ON DUPLICATE KEY UPDATE account_status=VALUES(account_status),last_logon_timestamp=VALUES(last_logon_timestamp),
                        os_name=VALUES(os_name),os_version=VALUES(os_version),machine_type=VALUES(machine_type),
                        dns_host_name=VALUES(dns_host_name),description=VALUES(description),ou=VALUES(ou),managed_by=VALUES(managed_by),
                        sid=VALUES(sid),sam_account_name=VALUES(sam_account_name),last_logon=VALUES(last_logon),
                        modified_date=VALUES(modified_date),distinguished_name=VALUES(distinguished_name),
                        location=VALUES(location),site=VALUES(site),source_synced_at=NOW()";
                    if($conn->query($sql))$ins++;else $skip++;
                }
            }
            $skip=$total-$ins;
            audit($conn,'CSV_UPLOAD','data_source',null,null,"$source: $ins upserted, $skip skipped of $total".($site?" [site=$site]":''));
            $success=ucfirst($source)." CSV imported: $ins processed, $skip skipped.";
        }
    }
}

/* HEAD decision */
if($loggedIn && $role==='head' && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['submit_decision'])){
    $id=(int)$_POST['asset_id']; $dec=$_POST['decision']??''; $note=trim($_POST['decision_note']??'');
    if(!in_array($dec,['Replace','Extend','Return'],true)) $error='Choose an action.';
    else {
        $ph=rtrim(str_repeat('?,',count($myDepts)),','); $types='i'.str_repeat('s',count($myDepts));
        $st=$conn->prepare("SELECT id,service_tag FROM eol_assets WHERE id=? AND is_eol=1 AND department IN ($ph) LIMIT 1");
        $st->bind_param($types,$id,...$myDepts); $st->execute(); $ok=$st->get_result()->fetch_assoc(); $st->close();
        if(!$ok) $error='Device not in your queue.';
        else { $st=$conn->prepare("UPDATE eol_assets SET decision=?,decision_note=?,decided_by_prn=?,decided_by_name=?,decided_at=NOW() WHERE id=?");
            $st->bind_param('ssssi',$dec,$note,$prn,$fullName,$id); $st->execute(); $st->close();
            audit($conn,'DECISION','asset',$id,$ok['service_tag'],"Decision: $dec",['decision'=>$dec,'note'=>$note]);
            $success="Decision \"$dec\" recorded."; }
    }
}

/* CSV export */
if($loggedIn && $role==='admin' && isset($_GET['export']) && $_GET['export']==='assets'){
    $kind=$_GET['kind']??'all'; $where=$kind==='eol'?'WHERE is_eol=1':($kind==='over4'?'WHERE over_four_years=1':'');
    audit($conn,'EXPORT','report',null,null,"Export $kind");
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename=catrion_eol_'.$kind.'_'.date('Ymd_His').'.csv');
    $out=fopen('php://output','w');
    fputcsv($out,['Asset Name','User','Last Login User','Asset State','Department','Last Scan Status','IP Addresses','Service Tag','Manufacturer','Model','Warranty Expiry','Over 4 Years?','EoL Flag','Flagged By','Decision','Decision Notes','Decided By','Decided At']);
    $res=$conn->query("SELECT * FROM eol_assets $where ORDER BY is_eol DESC, department ASC, asset_name ASC");
    while($r=$res->fetch_assoc()) fputcsv($out,[$r['asset_name'],$r['asset_user'],$r['last_login_user'],$r['asset_state'],$r['department'],$r['last_scan_status'],$r['ip_addresses'],$r['service_tag'],$r['manufacturer'],$r['model'],$r['warranty_expiry'],((int)$r['over_four_years']?'YES':'no'),((int)$r['is_eol']?'EoL':'Active'),$r['eol_flagged_by'],$r['decision'],$r['decision_note'],$r['decided_by_name'],$r['decided_at']]);
    fclose($out); exit;
}

/* Unified cross-source XLSX export (respects search/filter/sort, not pagination) */
if($loggedIn && $role==='admin' && isset($_GET['export']) && $_GET['export']==='unified'){
    $uq=trim($_GET['uq']??''); $ufilter=$_GET['uf']??'';
    $usort=$_GET['usort']??'sources'; $udir=(($_GET['udir']??'asc')==='desc')?'desc':'asc';
    [$ulist,$ucov]=unified_rows($conn);
    $rowsF=unified_apply($ulist,$uq,$ufilter,$usort,$udir);
    audit($conn,'EXPORT','report',null,null,'Export unified ('.count($rowsF).' rows)');
    $yn=fn($b)=>$b?'Yes':'No';
    $headers=['Device','Segment','Sources (n/4)','In ManageEngine','EoL Flagged','Decision','In Active Directory','AD Status','AD Site/Company','In Intune','Intune Compliance','In Darksight','Darksight EoL Software'];
    $out=[];
    foreach($rowsF as $u){
        $cnt=$u['me']+$u['ad']+$u['intune']+$u['dark'];
        $out[]=[$u['name'],$u['segment'],$cnt.'/4',$yn($u['me']),$u['me']?$yn($u['eol']):'',$u['decision'],
            $yn($u['ad']),$u['ad_status'],$u['ad_site'],$yn($u['intune']),$u['compliance'],$yn($u['dark']),$u['dark']?(int)$u['dark_eol']:''];
    }
    xlsx_download('catrion_unified_'.date('Ymd_His').'.xlsx',$headers,$out);
}

/* ============ DATA ============ */
$screen=$_GET['screen']??($role==='head'?'queue':'dashboard');
$assets=[]; $stats=['total'=>0,'active'=>0,'eol'=>0,'over4'=>0]; $admins=[]; $heads=[]; $segments=[];
$auditRows=[]; $loginRows=[]; $appRows=[]; $syncRows=[]; $logtab=$_GET['logtab']??'audit';
$dash=['kpi'=>[],'segTotals'=>[],'decByDay'=>[],'scan'=>[],'warranty'=>[],'intune'=>[],'adOs'=>[],'src'=>[]];
$unified=[]; $ucov=['unique'=>0,'core3'=>0,'me'=>0,'ad'=>0,'intune'=>0,'dark'=>0,'me_only'=>0,'ad_only'=>0,'intune_only'=>0,'dark_only'=>0,'unmanaged'=>0,'missing_ad'=>0,'not_in_me'=>0]; $unifiedTotal=0; $upage=1; $upages=1; $usort='sources'; $udir='asc';
if($loggedIn&&$conn){
    if($role==='admin'){
        if($r=$conn->query("SELECT COUNT(*) t,SUM(is_eol) ee,SUM(over_four_years) o FROM eol_assets")->fetch_assoc()){
            $stats['total']=(int)$r['t'];$stats['eol']=(int)$r['ee'];$stats['over4']=(int)$r['o'];$stats['active']=$stats['total']-$stats['eol']; }
        if($screen==='dashboard'){
            // ---- lifecycle measures (eol_assets) ----
            $r=dash_row($conn,"SELECT
                SUM(is_eol=1 AND decision IS NULL) pend,
                SUM(decision IS NOT NULL) decided,
                SUM(last_scan_status='FAILED') scanFail,
                SUM(last_scan_status='SUCCESS') scanOk,
                SUM(warranty_expiry IS NOT NULL AND warranty_expiry<CURDATE()) warrExp,
                SUM(warranty_expiry IS NOT NULL AND warranty_expiry>=CURDATE() AND warranty_expiry<DATE_ADD(CURDATE(),INTERVAL 90 DAY)) warrSoon,
                SUM(warranty_expiry IS NOT NULL AND warranty_expiry>=DATE_ADD(CURDATE(),INTERVAL 90 DAY)) warrAct,
                SUM(warranty_expiry IS NULL) warrUnk,
                COUNT(DISTINCT NULLIF(department,'')) segs, COUNT(*) tot
                FROM eol_assets");
            $dash['kpi']=array_map('intval',$r);
            $dash['scan']=['SUCCESS'=>(int)($r['scanOk']??0),'FAILED'=>(int)($r['scanFail']??0),'OTHER'=>max(0,$stats['total']-(int)($r['scanOk']??0)-(int)($r['scanFail']??0))];
            $dash['warranty']=['expired'=>(int)($r['warrExp']??0),'soon'=>(int)($r['warrSoon']??0),'active'=>(int)($r['warrAct']??0),'unknown'=>(int)($r['warrUnk']??0)];
            foreach(dash_all($conn,"SELECT department,COUNT(*) total,SUM(is_eol) eol FROM eol_assets WHERE department<>'' AND department IS NOT NULL GROUP BY department ORDER BY total DESC LIMIT 8") as $row)
                $dash['segTotals'][]=['dept'=>$row['department'],'total'=>(int)$row['total'],'eol'=>(int)$row['eol']];
            $tmp=[]; foreach(dash_all($conn,"SELECT DATE(decided_at) d,COUNT(*) c FROM eol_assets WHERE decided_at IS NOT NULL GROUP BY DATE(decided_at) ORDER BY d DESC LIMIT 14") as $row) $tmp[]=['d'=>$row['d'],'c'=>(int)$row['c']];
            $dash['decByDay']=array_reverse($tmp);
            // ---- cross-source measures ----
            $intuneTot=(int)(dash_row($conn,"SELECT COUNT(*) c FROM eol_intune")['c']??0);
            $intuneComp=(int)(dash_row($conn,"SELECT COUNT(*) c FROM eol_intune WHERE compliance='Compliant'")['c']??0);
            $intuneNon=(int)(dash_row($conn,"SELECT COUNT(*) c FROM eol_intune WHERE compliance IS NOT NULL AND compliance<>'' AND compliance<>'Compliant'")['c']??0);
            $dash['intune']=['total'=>$intuneTot,'compliant'=>$intuneComp,'noncompliant'=>$intuneNon,'unknown'=>max(0,$intuneTot-$intuneComp-$intuneNon),
                'pct'=>$intuneTot?round($intuneComp/$intuneTot*100):0];
            $adTot=(int)(dash_row($conn,"SELECT COUNT(*) c FROM eol_ad")['c']??0);
            $adEnabled=(int)(dash_row($conn,"SELECT COUNT(*) c FROM eol_ad WHERE account_status='Enabled'")['c']??0);
            $adStale=(int)(dash_row($conn,"SELECT COUNT(*) c FROM eol_ad WHERE last_logon_timestamp IS NOT NULL AND last_logon_timestamp<DATE_SUB(NOW(),INTERVAL 90 DAY)")['c']??0);
            $dash['ad']=['total'=>$adTot,'enabled'=>$adEnabled,'disabled'=>max(0,$adTot-$adEnabled),'stale'=>$adStale];
            foreach(dash_all($conn,"SELECT COALESCE(NULLIF(os_name,''),'Unknown') k,COUNT(*) c FROM eol_ad GROUP BY k ORDER BY c DESC LIMIT 6") as $row)
                $dash['adOs'][]=['k'=>$row['k'],'c'=>(int)$row['c']];
            $dsTot=(int)(dash_row($conn,"SELECT COUNT(*) c FROM eol_darksight")['c']??0);
            $dsEolHosts=(int)(dash_row($conn,"SELECT COUNT(*) c FROM eol_darksight WHERE eol>0")['c']??0);
            $dsEolTotal=(int)(dash_row($conn,"SELECT SUM(eol) s FROM eol_darksight")['s']??0);
            $dash['darksight']=['total'=>$dsTot,'eolHosts'=>$dsEolHosts,'eolTotal'=>$dsEolTotal];
            $dash['src']=['ManageEngine'=>$stats['total'],'Intune'=>$intuneTot,'Active Directory'=>$adTot,'Darksight'=>$dsTot];
        }
        if($screen==='inventory'){
            $w=[];$p=[];$t='';
            $fDept=trim($_GET['dept']??'');$fScan=trim($_GET['scan']??'');$fEol=trim($_GET['eol']??'');$q=trim($_GET['q']??'');
            if($fDept!==''){$w[]='department=?';$p[]=$fDept;$t.='s';} if($fScan!==''){$w[]='last_scan_status=?';$p[]=$fScan;$t.='s';}
            if($fEol==='EoL'){$w[]='is_eol=1';} if($fEol==='Active'){$w[]='is_eol=0';}
            if($q!==''){$w[]='(asset_name LIKE ? OR asset_user LIKE ? OR last_login_user LIKE ? OR service_tag LIKE ? OR ip_addresses LIKE ?)';
                array_push($p,"%$q%","%$q%","%$q%","%$q%","%$q%");$t.='sssss';}
            $sql="SELECT * FROM eol_assets".($w?' WHERE '.implode(' AND ',$w):'')." ORDER BY is_eol DESC, asset_name ASC LIMIT 500";
            $st=$conn->prepare($sql); if($p)$st->bind_param($t,...$p); $st->execute();
            $rs=$st->get_result(); while($row=$rs->fetch_assoc())$assets[]=$row; $st->close();
            $rs=$conn->query("SELECT DISTINCT department FROM eol_assets WHERE department<>'' AND department IS NOT NULL ORDER BY department");
            while($row=$rs->fetch_assoc())$segments[]=$row['department'];
        }
        if($screen==='admins'){ $rs=$conn->query("SELECT a.*,m.FULL_NAME FROM eol_admins a LEFT JOIN Unified_Employees_MasterList m ON m.PRN=a.prn ORDER BY a.id"); while($row=$rs->fetch_assoc())$admins[]=$row; }
        if($screen==='segments'){ $rs=$conn->query("SELECT h.*,m.FULL_NAME FROM eol_segment_heads h LEFT JOIN Unified_Employees_MasterList m ON m.PRN=h.head_prn ORDER BY h.department"); while($row=$rs->fetch_assoc())$heads[]=$row; }
        if($screen==='sources'){
            $srcTab=$_GET['src']??'darksight';
            $srcRows=[];$srcCount=['darksight'=>0,'intune'=>0,'ad'=>0];
            $srcCount['darksight']=(int)($conn->query("SELECT COUNT(*) c FROM eol_darksight")->fetch_assoc()['c']??0);
            $srcCount['intune']=(int)($conn->query("SELECT COUNT(*) c FROM eol_intune")->fetch_assoc()['c']??0);
            $srcCount['ad']=(int)($conn->query("SELECT COUNT(*) c FROM eol_ad")->fetch_assoc()['c']??0);
            if($srcTab==='darksight') { $rs=$conn->query("SELECT * FROM eol_darksight ORDER BY hostname LIMIT 200"); while($row=$rs->fetch_assoc())$srcRows[]=$row; }
            elseif($srcTab==='intune') { $rs=$conn->query("SELECT * FROM eol_intune ORDER BY device_name LIMIT 200"); while($row=$rs->fetch_assoc())$srcRows[]=$row; }
            elseif($srcTab==='ad') { $rs=$conn->query("SELECT * FROM eol_ad ORDER BY computer_name LIMIT 200"); while($row=$rs->fetch_assoc())$srcRows[]=$row; }
        }
        if($screen==='audit'){
            if($logtab==='audit'){ $rs=$conn->query("SELECT * FROM eol_audit_log ORDER BY id DESC LIMIT 200"); while($row=$rs->fetch_assoc())$auditRows[]=$row; }
            elseif($logtab==='logins'){ $rs=$conn->query("SELECT * FROM eol_login_log ORDER BY id DESC LIMIT 200"); while($row=$rs->fetch_assoc())$loginRows[]=$row; }
            elseif($logtab==='sync'){ $rs=$conn->query("SELECT * FROM eol_sync_runs ORDER BY id DESC LIMIT 100"); while($row=$rs->fetch_assoc())$syncRows[]=$row; }
            elseif($logtab==='app'){ $rs=$conn->query("SELECT * FROM eol_app_log ORDER BY id DESC LIMIT 200"); while($row=$rs->fetch_assoc())$appRows[]=$row; }
        }
        if($screen==='unified'){
            $uq=trim($_GET['uq']??''); $ufilter=$_GET['uf']??'';
            $usort=$_GET['usort']??'sources'; $udir=(($_GET['udir']??'asc')==='desc')?'desc':'asc';
            [$ulist,$ucov]=unified_rows($conn);
            $filtered=unified_apply($ulist,$uq,$ufilter,$usort,$udir);
            $unifiedTotal=count($filtered);
            $upages=max(1,(int)ceil($unifiedTotal/UNIFIED_PAGE));
            $upage=max(1,min($upages,(int)($_GET['upage']??1)));
            $unified=array_slice($filtered,($upage-1)*UNIFIED_PAGE,UNIFIED_PAGE);
        }
    } elseif($role==='head'&&$myDepts){
        $ph=rtrim(str_repeat('?,',count($myDepts)),','); $t=str_repeat('s',count($myDepts));
        $st=$conn->prepare("SELECT * FROM eol_assets WHERE is_eol=1 AND department IN ($ph) ORDER BY decision IS NOT NULL, asset_name");
        $st->bind_param($t,...$myDepts); $st->execute(); $rs=$st->get_result(); while($row=$rs->fetch_assoc())$assets[]=$row; $st->close();
    }
}
$LOGO='https://e-services.catrion.com/EOL/image/catrion-logo-white.png';
$LOGO_EN='image/Catrion-Logo-English.png';   // English logo shown top-left in the content header
$POLICY='<div class="policy"><a href="https://www.catrion.com/terms-and-conditions" target="_blank" rel="noopener noreferrer">Terms &amp; Conditions</a><span>•</span><a href="https://www.catrion.com/privacy-policy" target="_blank" rel="noopener noreferrer">Privacy Policy</a><span>•</span><a href="https://www.catrion.com/cookie-policy" target="_blank" rel="noopener noreferrer">Cookie Policy</a></div>';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>CATRION · Asset Lifecycle</title>
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<link rel="icon" type="image/x-icon" href="favicon.ico">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
@font-face{font-family:'Gotham';font-weight:300;src:url('fonts/Gotham-Light.woff') format('woff');font-display:swap}
@font-face{font-family:'Gotham';font-weight:500;src:url('fonts/Gotham-Medium.woff') format('woff');font-display:swap}
@font-face{font-family:'Gotham';font-weight:700;src:url('fonts/Gotham-Bold.woff') format('woff');font-display:swap}
:root{--primary:#5A92DB;--dark:#003F53;--navy:#062b58;--ink:#0f172a;--muted:#64748b;--light:#ACC8ED;--soft:#D6E4F6;--bg:#f4f8ff;--card:#fff;--border:#d7e5f7;--orange:#D45B25;--gold:#BE8617;--green:#A6D178;--ok:#047857;--teal:#0E9F8E;--lav:#A898AF;--danger:#b91c1c;--shadow:0 24px 70px rgba(0,57,122,.13)}
*{box-sizing:border-box}body{margin:0;font-family:'Gotham',system-ui,'Segoe UI',sans-serif;font-weight:300;background:linear-gradient(135deg,#f8fbff,#edf5ff);color:var(--ink)}
a{text-decoration:none;color:inherit}b,strong,h1,h2,h3{font-weight:700}
.login-page{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:22px}
.login-shell{width:100%;max-width:1040px;min-height:600px;display:grid;grid-template-columns:.9fr 1.1fr;background:#fff;border:1px solid var(--border);border-radius:30px;box-shadow:var(--shadow);overflow:hidden}
.login-hero{background:linear-gradient(140deg,var(--primary),#245f9d 44%,var(--dark));color:#fff;padding:42px;display:flex;flex-direction:column;justify-content:space-between}
.brand{display:flex;align-items:center;gap:13px}.brand img{height:30px}
.login-hero h1{font-size:34px;line-height:1.12;margin:30px 0 14px}.login-hero p{line-height:1.8;color:rgba(255,255,255,.86);margin:0}
.login-panel{padding:48px;display:flex;flex-direction:column;justify-content:center}
.lbl{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--dark);margin-bottom:10px}
.login-panel h2{font-size:28px;margin:0 0 8px}.sub{color:var(--muted);line-height:1.7;margin:0 0 24px}
.notice{padding:13px 14px;border-radius:14px;margin:0 0 16px;border:1px solid #bbf7d0;background:#f0fdf4;color:var(--ok);font-weight:500}.notice.error{border-color:#fecaca;background:#fff7f7;color:var(--danger)}
.field{margin-bottom:16px}label{display:block;margin-bottom:8px;font-weight:700;font-size:13px}
input,select,textarea{width:100%;min-height:50px;border:1.5px solid var(--border);border-radius:13px;padding:0 14px;font-size:15px;font-family:inherit;background:#fff;color:var(--ink)}
textarea{padding:12px 14px;min-height:70px;resize:vertical;font-size:14px}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 4px rgba(90,146,219,.15)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:50px;border:0;border-radius:13px;background:linear-gradient(135deg,var(--dark),var(--primary));color:#fff;font-size:14px;font-weight:700;cursor:pointer;padding:0 18px;font-family:inherit}
.btn:hover{filter:brightness(1.05)}.btn.light{background:#fff;color:var(--dark);border:1.5px solid var(--border)}.btn.sm{min-height:40px;font-size:13px;padding:0 14px}
.btn.light span i{display:block;border-radius:1px}
.sep{display:flex;align-items:center;gap:12px;margin:20px 0;color:var(--muted);font-size:12px;font-weight:500}.sep::before,.sep::after{content:"";flex:1;height:1px;background:var(--border)}
.foot{text-align:center;color:#9fb0c4;font-size:11.5px;margin-top:18px;font-weight:500}
.policy{margin-top:8px;display:flex;gap:9px;justify-content:center;flex-wrap:wrap}.policy a{color:var(--primary)}.policy span{opacity:.5}
.app{display:grid;grid-template-columns:250px 1fr;min-height:100vh}
.sidebar{background:linear-gradient(180deg,var(--dark),var(--navy));color:#fff;padding:24px 18px;display:flex;flex-direction:column;gap:5px}
.sidebar .brand{margin-bottom:20px;padding-left:4px}.sidebar .brand img{height:26px}
.nav a{display:block;padding:12px 13px;border-radius:11px;font-weight:500;color:rgba(255,255,255,.9);font-size:13.5px}
.nav a.on,.nav a:hover{background:rgba(255,255,255,.12)}.nav a.on{background:#fff;color:var(--dark);font-weight:700}
.navsec{font-size:9.5px;letter-spacing:.16em;text-transform:uppercase;color:rgba(255,255,255,.4);margin:16px 0 6px 11px}
.sidebar form{margin-top:auto}
.content{display:flex;flex-direction:column;min-height:100vh}.main{flex:1;padding:26px 30px}
.topbar{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:22px}.topbar h1{margin:0;font-size:26px}.topbar .meta{color:var(--muted);font-size:13px;margin-top:4px}
.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}
.card{background:#fff;border:1px solid var(--border);border-radius:16px;padding:18px 20px;box-shadow:0 12px 32px rgba(0,57,122,.07)}.card span{color:var(--muted);font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}.card b{display:block;font-size:32px;color:var(--dark);margin-top:6px}.card.eol b{color:var(--orange)}.card.warn b{color:var(--gold)}.card.ok b{color:var(--ok)}
.charts{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px}.panel{background:#fff;border:1px solid var(--border);border-radius:16px;padding:18px 20px;box-shadow:0 12px 32px rgba(0,57,122,.07)}.panel h3{margin:0 0 14px;color:var(--dark);font-size:15px;display:flex;align-items:center;gap:8px}.panel h3 svg{width:17px;height:17px;color:var(--primary)}.cbox{height:230px;position:relative}
.filters{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;align-items:center}
.table-wrap{overflow:auto;border:1px solid var(--border);border-radius:14px;background:#fff;box-shadow:0 12px 28px rgba(0,57,122,.06)}
table{width:100%;border-collapse:collapse;font-size:12.5px;min-width:760px}th{background:#eef6ff;color:var(--dark);font-weight:700;text-align:left;padding:11px 13px;border-bottom:1px solid var(--border);white-space:nowrap;font-size:10.5px;letter-spacing:.04em;text-transform:uppercase}td{padding:10px 13px;border-bottom:1px solid var(--border);vertical-align:middle}
tr.eol{background:linear-gradient(90deg,#fdeee7,transparent 55%)}.tag{font-family:ui-monospace,Menlo,monospace;font-size:11px}.dept{background:var(--soft);color:var(--dark);padding:3px 8px;border-radius:6px;font-size:11px;white-space:nowrap}
.pill{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:3px 9px;border-radius:20px}.pill .d{width:6px;height:6px;border-radius:50%}.pill.ok{background:#e2f4ee;color:var(--ok)}.pill.ok .d{background:var(--ok)}.pill.fail{background:#fdecec;color:var(--danger)}.pill.fail .d{background:var(--danger)}
.switch{width:42px;height:24px;border-radius:13px;background:#cdd9e8;position:relative;border:0;cursor:pointer}.switch.on{background:var(--orange)}.switch::after{content:"";position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:.2s}.switch.on::after{transform:translateX(18px)}
.dateedit{font-family:ui-monospace,Menlo,monospace;font-size:11px;width:128px;min-height:34px;border-radius:8px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.form-grid .full{grid-column:1/-1}
.linkbtn{background:none;border:none;color:var(--primary);font-weight:700;font-size:12.5px;cursor:pointer;padding:0}
.queue{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:16px}
.qcard{background:#fff;border:1px solid var(--border);border-radius:16px;padding:18px;box-shadow:0 12px 28px rgba(0,57,122,.06);position:relative;overflow:hidden}.qcard::before{content:"";position:absolute;left:0;top:0;width:4px;height:100%;background:var(--orange)}.qcard.done::before{background:var(--ok)}
.qcard h4{margin:0;font-size:13px;font-family:ui-monospace,Menlo,monospace}.qcard .qs{font-size:11.5px;color:var(--muted);margin-top:3px}.qcard dl{display:grid;grid-template-columns:auto 1fr;gap:5px 12px;font-size:12px;margin:12px 0}.qcard dt{color:var(--muted)}.qcard dd{text-align:right;font-family:ui-monospace,Menlo,monospace;font-size:11.5px}
.qnote{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:10px 12px;font-size:12px;margin-bottom:12px;line-height:1.5}.qnote span{display:block;font-size:9.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:700;margin-bottom:4px}
.decided{background:#e2f4ee;border-radius:10px;padding:11px 13px;font-size:12px}.decided .h{color:var(--ok);font-weight:700;display:flex;gap:7px;align-items:center;margin-bottom:5px}
.dec{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;padding:3px 8px;border-radius:6px}.dec.Replace{background:#fdeee7;color:var(--orange)}.dec.Extend{background:var(--soft);color:var(--dark)}.dec.Return{background:#efe9f1;color:#7d6f86}
.rcards{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:18px}.rcard{flex:1;min-width:210px;background:#fff;border:1px solid var(--border);border-radius:16px;padding:20px;box-shadow:0 12px 28px rgba(0,57,122,.06)}.rcard h4{margin:0 0 5px;font-size:13px;color:var(--dark)}.rcard p{font-size:12px;color:var(--muted);margin-bottom:14px;line-height:1.45}
.tabs{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap}.tabs a{padding:9px 15px;border-radius:11px;font-size:13px;font-weight:700;background:#fff;border:1px solid var(--border);color:var(--muted)}.tabs a.on{background:linear-gradient(135deg,var(--dark),var(--primary));color:#fff;border-color:transparent}
.lvl{font-size:10px;font-weight:700;padding:3px 8px;border-radius:6px}.lvl.INFO{background:#e7eefb;color:var(--primary)}.lvl.WARN{background:#f6ecd6;color:var(--gold)}.lvl.ERROR{background:#fdecec;color:var(--danger)}
.st{font-size:10px;font-weight:700;padding:3px 8px;border-radius:6px;background:#eef2f6;color:var(--muted)}.st.SUCCESS{background:#e2f4ee;color:var(--ok)}.st.OTP_SENT{background:#e7eefb;color:var(--primary)}.st[class*="FAIL"]{background:#fdecec;color:var(--danger)}.st.LOGOUT{background:#f6ecd6;color:var(--gold)}
.empty{text-align:center;padding:60px 20px;color:var(--muted)}
.appfoot{padding:20px 30px;border-top:1px solid var(--border);text-align:center;color:var(--muted);font-size:12px;font-weight:500;background:rgba(255,255,255,.5)}.appfoot .policy{margin-top:8px}
.sync-info{background:var(--bg);border:1px solid var(--border);border-radius:14px;padding:14px 16px;margin-bottom:14px;color:var(--muted);line-height:1.6;font-size:13px}.sync-info b{color:var(--dark)}

/* ===== IT Asset Lifecycle: KPIs + visuals ===== */
.kgrid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px}
.kpi{position:relative;background:#fff;border:1px solid var(--border);border-radius:16px;padding:15px 17px;box-shadow:0 12px 30px rgba(0,57,122,.07);overflow:hidden;transition:transform .15s,box-shadow .15s}
.kpi:hover{transform:translateY(-2px);box-shadow:0 18px 40px rgba(0,57,122,.12)}
.kpi::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--kc,linear-gradient(90deg,var(--light),var(--primary)))}
.kpi .kh{display:flex;align-items:center;justify-content:space-between;gap:8px}
.kpi .kh span{color:var(--muted);font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;line-height:1.3}
.kpi .ic{width:30px;height:30px;border-radius:9px;display:flex;align-items:center;justify-content:center;background:var(--soft);color:var(--primary);flex-shrink:0}.kpi .ic svg{width:16px;height:16px}
.kpi b{display:block;font-size:27px;color:var(--dark);margin-top:9px;line-height:1;font-variant-numeric:tabular-nums}
.kpi em{font-style:normal;font-size:11.5px;color:var(--muted);font-weight:500}
.kpi.ok b{color:var(--ok)}.kpi.ok .ic{background:#e2f4ee;color:var(--ok)}.kpi.ok::before{background:linear-gradient(90deg,#bfe6cf,var(--ok))}
.kpi.eol b{color:var(--orange)}.kpi.eol .ic{background:#fdeae0;color:var(--orange)}.kpi.eol::before{background:linear-gradient(90deg,#f5b79a,var(--orange))}
.kpi.warn b{color:var(--gold)}.kpi.warn .ic{background:#f7eccf;color:var(--gold)}.kpi.warn::before{background:linear-gradient(90deg,#ecd49a,var(--gold))}
.kpi.teal b{color:var(--teal)}.kpi.teal .ic{background:#d6f1ec;color:var(--teal)}.kpi.teal::before{background:linear-gradient(90deg,#a7e0d6,var(--teal))}
.bento{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}
.bento .panel{margin:0}.span-2{grid-column:span 2}.span-4{grid-column:1/-1}
.pipe{display:flex;gap:0;flex-wrap:nowrap;overflow-x:auto;padding-bottom:2px}
.pstage{flex:1;min-width:118px;padding:4px 6px;position:relative}
.pstage .pb{background:var(--bg);border:1px solid var(--border);border-radius:12px;padding:13px 15px;position:relative;overflow:hidden}
.pstage .pb i{position:absolute;left:0;top:0;bottom:0;width:var(--w,0);background:var(--pc,rgba(90,146,219,.22));transition:width 1.1s cubic-bezier(.16,1,.3,1)}
.pstage .pv{position:relative;font-size:23px;font-weight:700;color:var(--dark);font-variant-numeric:tabular-nums}
.pstage .pn{position:relative;font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-top:2px}
.pstage .pa{position:absolute;right:-6px;top:50%;transform:translateY(-50%);color:var(--light);z-index:2}.pstage:last-child .pa{display:none}.pstage .pa svg{width:16px;height:16px}
.barlist{display:flex;flex-direction:column;gap:11px;padding-top:2px}
.brow{display:flex;align-items:center;gap:10px;font-size:12px}
.brow .bl{width:128px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex-shrink:0}
.brow .bt{flex:1;height:11px;background:#eef4fb;border-radius:6px;overflow:hidden}.brow .bt i{display:block;height:100%;border-radius:6px;background:linear-gradient(90deg,var(--primary),var(--light))}
.brow b{min-width:36px;text-align:right;white-space:nowrap;font-family:ui-monospace,Menlo,monospace;color:var(--dark)}
.dwrap{display:flex;align-items:center;gap:22px;justify-content:center;flex-wrap:wrap;height:100%}
.dring{width:148px;height:148px;border-radius:50%;position:relative;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.dring::after{content:'';position:absolute;inset:23px;border-radius:50%;background:#fff;border:1px solid var(--border)}
.dring span{position:relative;z-index:1;font-size:25px;font-weight:700;color:var(--dark);display:flex;flex-direction:column;align-items:center}.dring small{font-size:8.5px;letter-spacing:.12em;color:var(--muted);margin-top:3px;font-weight:700}
.dleg{display:flex;flex-direction:column;gap:9px;font-size:12px}.dleg div{display:flex;align-items:center;gap:8px;color:var(--muted)}.dleg .dot{display:inline-block;width:10px;height:10px;border-radius:3px}.dleg b{margin-left:auto;color:var(--dark);font-family:ui-monospace,Menlo,monospace}
.stack{display:flex;height:20px;border-radius:8px;overflow:hidden;border:1px solid var(--border);margin:6px 0 14px}.stack i{height:100%;transition:width 1s cubic-bezier(.16,1,.3,1)}
.stkleg{display:grid;grid-template-columns:1fr 1fr;gap:8px 14px;font-size:11.5px}.stkleg div{display:flex;align-items:center;gap:7px;color:var(--muted)}.stkleg .dot{width:10px;height:10px;border-radius:3px;flex-shrink:0}.stkleg b{margin-left:auto;color:var(--dark);font-family:ui-monospace,Menlo,monospace}
.spark-svg{width:100%;height:100%;display:block;overflow:visible}.spark-empty{display:flex;align-items:center;justify-content:center;height:100%;color:var(--muted);font-size:12.5px}
@media(max-width:1000px){.app{grid-template-columns:1fr}.login-shell{grid-template-columns:1fr}.login-hero{display:none}.cards,.charts,.grid2,.form-grid,.kgrid,.bento{grid-template-columns:1fr 1fr}.span-2,.span-4{grid-column:auto}}
@media(max-width:620px){.cards,.kgrid,.bento{grid-template-columns:1fr}}

/* ============================================================
   MODERN THEME v2 — refreshed palette, glass, gradients
   (declared last so it takes precedence over the base rules)
   ============================================================ */
:root{
  --primary:#4f7cff;--primary2:#7c5cff;--dark:#0b2a4a;--navy:#0a1f3c;--ink:#0e1726;
  --muted:#6b7a90;--light:#c7d6f5;--soft:#eaf1ff;--bg:#eef3fb;--card:#fff;--border:#e4ebf7;
  --orange:#ff6a3d;--gold:#f6a821;--green:#22c55e;--ok:#0ea371;--teal:#10b3a3;--violet:#7c5cff;--lav:#A898AF;--danger:#e23d5b;
  --grad:linear-gradient(135deg,#4f7cff,#7c5cff);--grad2:linear-gradient(135deg,#0b2a4a,#4f7cff);
  --shadow:0 18px 50px -24px rgba(31,52,120,.45);--ring:0 0 0 4px rgba(79,124,255,.18);
}
body{background:
  radial-gradient(800px 540px at 8% -6%,rgba(124,92,255,.10),transparent 60%),
  radial-gradient(760px 520px at 100% 0%,rgba(16,179,163,.10),transparent 55%),
  radial-gradient(900px 700px at 90% 110%,rgba(79,124,255,.10),transparent 55%),
  linear-gradient(135deg,#f3f7ff,#eaf1fb)}
a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible{outline:none;box-shadow:var(--ring);border-radius:12px}
input:focus,select:focus,textarea:focus{border-color:var(--primary);box-shadow:var(--ring)}

/* buttons */
.btn{background:var(--grad);border-radius:13px;box-shadow:0 10px 24px -10px rgba(79,124,255,.7);transition:transform .15s,box-shadow .15s,filter .15s}
.btn:hover{transform:translateY(-1px);filter:brightness(1.05);box-shadow:0 14px 30px -10px rgba(79,124,255,.8)}
.btn.light{background:#fff;color:var(--dark);border:1.5px solid var(--border);box-shadow:0 8px 20px -12px rgba(31,52,120,.35)}
.btn.light:hover{border-color:var(--primary)}

/* sidebar — glassy gradient with glow active pill */
.sidebar{background:linear-gradient(170deg,#0b2a4a 0%,#11224d 55%,#0a1838 100%);position:relative;box-shadow:0 0 60px rgba(8,18,40,.4)}
.sidebar::after{content:'';position:absolute;inset:0;pointer-events:none;background:radial-gradient(420px 200px at 20% 0%,rgba(124,92,255,.22),transparent 60%),radial-gradient(360px 220px at 90% 100%,rgba(16,179,163,.18),transparent 60%)}
.nav{position:relative;z-index:1}
.nav a{position:relative;border-radius:12px;color:rgba(255,255,255,.82)}
.nav a:hover{background:rgba(255,255,255,.08);color:#fff}
.nav a.on{background:#fff;color:var(--dark);font-weight:700;box-shadow:0 10px 24px -12px rgba(0,0,0,.6)}
.nav a.on::before{content:'';position:absolute;left:-7px;top:9px;bottom:9px;width:4px;border-radius:4px;background:var(--grad);box-shadow:0 0 12px var(--primary)}
.navsec{color:rgba(255,255,255,.45)}
.sidebar .brand,.nav{position:relative;z-index:1}

/* topbar gradient title */
.topbar h1{background:var(--grad2);-webkit-background-clip:text;background-clip:text;color:transparent}
.tb-left{display:flex;align-items:center;gap:16px}
.tb-logo{height:46px;width:auto;max-width:200px;flex-shrink:0}
@media(max-width:620px){.tb-left{gap:10px}.tb-logo{height:34px}}

/* cards / panels — bigger radius, gradient hairline, lift */
.card,.panel,.kpi,.qcard,.rcard,.table-wrap,.sync-info{border-radius:18px;border:1px solid var(--border);box-shadow:var(--shadow)}
.panel:hover{box-shadow:0 22px 54px -26px rgba(31,52,120,.5)}
.panel h3{font-size:14px}.panel h3 svg{color:var(--primary)}

/* KPI accents refreshed */
.kpi::before{height:4px}
.kpi .ic{background:var(--soft);color:var(--primary);border-radius:11px}
.kpi.ok .ic{background:#dcfce9;color:var(--ok)}.kpi.ok::before{background:linear-gradient(90deg,#86efac,var(--ok))}.kpi.ok b{color:var(--ok)}
.kpi.eol .ic{background:#ffe4dc;color:var(--orange)}.kpi.eol::before{background:linear-gradient(90deg,#ffb39c,var(--orange))}.kpi.eol b{color:var(--orange)}
.kpi.warn .ic{background:#fdeecb;color:var(--gold)}.kpi.warn::before{background:linear-gradient(90deg,#fbd884,var(--gold))}.kpi.warn b{color:#c9881a}
.kpi.teal .ic{background:#d2f4ef;color:var(--teal)}.kpi.teal::before{background:linear-gradient(90deg,#7fe3d8,var(--teal))}.kpi.teal b{color:var(--teal)}
.kpi:not(.ok):not(.eol):not(.warn):not(.teal)::before{background:var(--grad)}

/* tables */
thead th{background:linear-gradient(180deg,#f3f7ff,#eaf1fb);color:var(--dark)}
tbody tr:hover{background:#f5f8ff}
tr.eol{background:linear-gradient(90deg,#fff1ec,transparent 55%)}
.dept{background:#eaf1ff;color:var(--primary);border:1px solid #dbe6ff;font-weight:700}
.pill.ok{background:#dcfce9;color:var(--ok)}.pill.fail{background:#fde7eb;color:var(--danger)}
.tabs a.on,.dec.Extend{background:var(--grad);color:#fff}

/* unified screen */
.screen-intro{color:var(--muted);font-size:13.5px;margin:0 0 16px;max-width:780px;line-height:1.65}
.srcdots{display:inline-flex;gap:5px}
.srcdots i{width:9px;height:9px;border-radius:50%;background:#dde5f1;display:inline-block}
.srcdots i.on.me{background:var(--dark)}.srcdots i.on.ad{background:var(--primary)}.srcdots i.on.it{background:var(--teal)}.srcdots i.on.dk{background:var(--violet)}
.miss{font-size:11px;color:#aebccf;font-style:italic}
.srcset{display:inline-flex;align-items:center;gap:5px;flex-wrap:wrap}
.schip{font-size:9.5px;font-weight:800;letter-spacing:.03em;padding:3px 6px;border-radius:6px;background:#eef2f8;color:#aab6c8;border:1px solid #e2e8f2;line-height:1}
.schip.on{background:var(--sc);color:#fff;border-color:transparent;box-shadow:0 4px 10px -4px var(--sc)}
.srccount{font-size:10px;font-weight:800;font-family:ui-monospace,Menlo,monospace;color:var(--muted);background:#eef2f8;border-radius:20px;padding:2px 8px;margin-left:2px}
.srccount.good{background:#dcfce9;color:var(--ok)}.srccount.low{background:#fde7eb;color:var(--danger)}
.srclegend{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:0 0 12px;font-size:12px;color:var(--muted)}
.srclegend .lg-title{font-weight:700;color:var(--dark)}
.srclegend span{display:inline-flex;align-items:center;gap:6px}
.srclegend i{width:11px;height:11px;border-radius:50%;display:inline-block}
.srclegend i.me{background:var(--dark)}.srclegend i.ad{background:var(--primary)}.srclegend i.it{background:var(--teal)}.srclegend i.dk{background:var(--violet)}
.srclegend .muted{color:#9aa8bd}
.covbar{display:inline-block;width:96px;height:9px;border-radius:5px;background:#eef4fb;overflow:hidden;vertical-align:middle}
.covbar i{display:block;height:100%;border-radius:5px}
.sorth{display:inline-flex;align-items:center;gap:3px;color:var(--dark);cursor:pointer;white-space:nowrap}
.sorth:hover{color:var(--primary)}
.pager{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:14px}
.pgbtn{min-width:34px;height:34px;padding:0 10px;display:inline-flex;align-items:center;justify-content:center;border:1px solid var(--border);border-radius:9px;background:#fff;color:var(--dark);font-size:13px;font-weight:700;box-shadow:0 6px 16px -12px rgba(31,52,120,.4)}
.pgbtn:hover{border-color:var(--primary);color:var(--primary)}
.pgbtn.on{background:var(--grad);color:#fff;border-color:transparent;box-shadow:0 8px 18px -8px rgba(79,124,255,.7)}
.pgbtn.disabled{opacity:.45;pointer-events:none}
.pgdots{color:var(--muted);padding:0 2px}
.pginfo{margin-inline-start:auto;color:var(--muted);font-size:12px}

/* login modern */
.login-shell{border-radius:26px}
.login-hero{background:linear-gradient(150deg,#4f7cff 0%,#3a55c7 38%,#0b2a4a 100%);position:relative;overflow:hidden}
.login-hero::after{content:'';position:absolute;width:420px;height:420px;border-radius:50%;top:-150px;right:-120px;background:radial-gradient(circle,rgba(124,92,255,.55),transparent 60%)}
.login-hero::before{content:'';position:absolute;width:320px;height:320px;border-radius:50%;bottom:-120px;left:-80px;background:radial-gradient(circle,rgba(16,179,163,.45),transparent 60%)}
.login-hero>*{position:relative;z-index:1}

/* ===== responsive: off-canvas sidebar ===== */
.appbar{display:none}.scrim{display:none}
@media(max-width:1000px){
  .app{grid-template-columns:1fr}
  .appbar{display:flex;align-items:center;gap:12px;position:sticky;top:0;z-index:45;background:linear-gradient(120deg,#0b2a4a,#11224d);padding:11px 16px;box-shadow:var(--shadow)}
  .appbar img{height:24px}
  .hamburger{background:rgba(255,255,255,.12);border:0;color:#fff;width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;cursor:pointer}.hamburger svg{width:20px;height:20px}
  .sidebar{position:fixed;inset:0 auto 0 0;width:256px;z-index:60;transform:translateX(-100%);transition:transform .26s ease}
  .sidebar.open{transform:none}
  .scrim{display:block;position:fixed;inset:0;background:rgba(8,16,32,.5);z-index:50;opacity:0;visibility:hidden;transition:opacity .26s}.scrim.show{opacity:1;visibility:visible}
  .main{padding:18px 16px}
}
@media(max-width:620px){.main{padding:14px 12px}.topbar h1{font-size:22px}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.001ms!important;transition-duration:.001ms!important}}
</style></head><body>

<?php if(!$loggedIn): ?>
<div class="login-page"><div class="login-shell">
  <section class="login-hero">
    <div class="brand"><img src="<?=$LOGO?>" alt="CATRION" onerror="this.style.display='none'"></div>
    <div><h1>Every device, tracked to end-of-life.</h1>
      <p>Synced live from ManageEngine and linked to the Unified Employees Master List. IT flags End-of-Life; the owning segment head decides — Replace, Extend, or Return.</p></div>
    <div class="foot" style="color:rgba(255,255,255,.7);text-align:left">© 2026 CATRION • IT Digital &amp; Transformation</div>
  </section>
  <section class="login-panel">
    <div class="lbl">Secure Access</div>
    <h2><?=$showOtp?'Enter verification code':'Sign in'?></h2>
    <p class="sub"><?=$showOtp?'A 4-digit code was sent to your registered mobile.':'Enter your employee PRN or continue with Microsoft SSO.'?></p>
    <?php if($error):?><div class="notice error">⚠️ <?=e($error)?></div><?php endif;?>
    <?php if($success):?><div class="notice">✅ <?=e($success)?></div><?php endif;?>
    <form method="post" novalidate>
      <input type="hidden" name="eol_login" value="1">
      <div class="field"><label>Employee PRN</label>
        <input name="login_prn" inputmode="numeric" pattern="^140\d{5}$" maxlength="8" placeholder="140xxxxx" value="<?=e($_POST['login_prn']??$_SESSION['eol_pending_prn']??'')?>" required></div>
      <?php if($showOtp||!empty($_SESSION['eol_pending_prn'])):?>
        <div class="field"><label>Verification Code</label><input name="otp" inputmode="numeric" pattern="\d{4}" maxlength="4" placeholder="4-digit code" required></div>
        <button class="btn" type="submit" style="width:100%">Verify &amp; sign in</button>
      <?php else:?>
        <button class="btn" type="submit" style="width:100%">Send verification code</button>
      <?php endif;?>
    </form>
    <?php if(sso_available()):?>
    <div class="sep">or</div>
    <a class="btn light" href="<?=sso_url()?>" style="width:100%">
      <span style="display:grid;grid-template-columns:1fr 1fr;gap:2px;width:16px;height:16px"><i style="background:#f25022"></i><i style="background:#7fba00"></i><i style="background:#00a4ef"></i><i style="background:#ffb900"></i></span>
      Continue with Microsoft SSO</a>
    <?php endif;?>
    <div class="foot">Created by CATRION IT Team<?=$POLICY?></div>
  </section>
</div></div>

<?php else:?>
<header class="appbar">
  <button class="hamburger" id="navToggle" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg></button>
  <img src="<?=$LOGO?>" alt="CATRION" onerror="this.style.display='none'">
</header>
<div class="scrim" id="navScrim" hidden></div>
<div class="app">
  <aside class="sidebar" id="sidebar">
    <div class="brand"><img src="<?=$LOGO?>" alt="CATRION" onerror="this.style.display='none'"></div>
    <nav class="nav">
      <?php if($role==='admin'):?>
        <div class="navsec">Administration</div>
        <a class="<?=$screen==='dashboard'?'on':''?>" href="?screen=dashboard">IT Asset Lifecycle</a>
        <a class="<?=$screen==='inventory'?'on':''?>" href="?screen=inventory">Asset Inventory</a>
        <a class="<?=$screen==='admins'?'on':''?>" href="?screen=admins">Admin Users</a>
        <a class="<?=$screen==='segments'?'on':''?>" href="?screen=segments">Segment Heads</a>
        <a class="<?=$screen==='reports'?'on':''?>" href="?screen=reports">Reports</a>
        <a class="<?=$screen==='sources'?'on':''?>" href="?screen=sources">Data Sources</a>
        <a class="<?=$screen==='unified'?'on':''?>" href="?screen=unified">Unified View</a>
        <a class="<?=$screen==='audit'?'on':''?>" href="?screen=audit">Audit Log</a>
      <?php else:?>
        <div class="navsec">My Area</div>
        <a class="on" href="?screen=queue">Replacement Queue</a>
      <?php endif;?>
    </nav>
    <form method="post"><button class="btn light" name="logout" style="width:100%">Logout</button></form>
  </aside>
  <div class="content"><main class="main">
    <div class="topbar"><div class="tb-left">
      <img class="tb-logo" src="<?=$LOGO_EN?>" alt="CATRION" onerror="this.style.display='none'">
      <div>
      <h1><?=['dashboard'=>'IT Asset Lifecycle','inventory'=>'Asset Inventory','admins'=>'Admin Users','segments'=>'Segment Heads','reports'=>'Reports','sources'=>'Data Sources','unified'=>'Unified Asset View','audit'=>'Audit Log','queue'=>'Replacement Queue'][$screen]??'Asset Lifecycle'?></h1>
      <div class="meta">Signed in as <?=e($fullName)?> • <?=e($prn)?> • <?=$role==='admin'?'IT Administrator':'Segment Head'?></div>
      </div>
    </div>
    <?php if($role==='admin'&&$screen==='inventory'):?>
      <form method="post" style="margin:0"><button class="btn sm" name="sync_amer">⟳ Sync from ManageEngine</button></form>
    <?php endif;?>
    </div>
    <?php if($error):?><div class="notice error">⚠️ <?=e($error)?></div><?php endif;?>
    <?php if($success):?><div class="notice">✅ <?=e($success)?></div><?php endif;?>

    <?php if($role==='admin'&&$screen==='dashboard'):
        $k=$dash['kpi']; $it=$dash['intune']; $ad=$dash['ad']??[]; $ds=$dash['darksight']??[];
    ?>
      <!-- KPI grid (12 measures) -->
      <section class="kgrid">
        <div class="kpi"><div class="kh"><span>Total assets</span><span class="ic"><?=icon('box')?></span></div><b class="count" data-to="<?=(int)$stats['total']?>">0</b></div>
        <div class="kpi ok"><div class="kh"><span>Active</span><span class="ic"><?=icon('check-circle')?></span></div><b class="count" data-to="<?=(int)$stats['active']?>">0</b></div>
        <div class="kpi eol"><div class="kh"><span>Flagged EoL</span><span class="ic"><?=icon('alert')?></span></div><b class="count" data-to="<?=(int)$stats['eol']?>">0</b></div>
        <div class="kpi warn"><div class="kh"><span>Over 4 years</span><span class="ic"><?=icon('clock')?></span></div><b class="count" data-to="<?=(int)$stats['over4']?>">0</b></div>
        <div class="kpi warn"><div class="kh"><span>Pending decisions</span><span class="ic"><?=icon('queue')?></span></div><b class="count" data-to="<?=(int)($k['pend']??0)?>">0</b></div>
        <div class="kpi ok"><div class="kh"><span>Decisions made</span><span class="ic"><?=icon('check')?></span></div><b class="count" data-to="<?=(int)($k['decided']??0)?>">0</b></div>
        <div class="kpi eol"><div class="kh"><span>Warranty expired</span><span class="ic"><?=icon('shield')?></span></div><b class="count" data-to="<?=(int)($k['warrExp']??0)?>">0</b></div>
        <div class="kpi eol"><div class="kh"><span>Scan failures</span><span class="ic"><?=icon('activity')?></span></div><b class="count" data-to="<?=(int)($k['scanFail']??0)?>">0</b></div>
        <div class="kpi teal"><div class="kh"><span>Intune managed</span><span class="ic"><?=icon('cpu')?></span></div><b class="count" data-to="<?=(int)($it['total']??0)?>">0</b></div>
        <div class="kpi teal"><div class="kh"><span>Intune compliant</span><span class="ic"><?=icon('check-circle')?></span></div><b><span class="count" data-to="<?=(int)($it['pct']??0)?>">0</span>%</b><em><?=(int)($it['compliant']??0)?> of <?=(int)($it['total']??0)?></em></div>
        <div class="kpi warn"><div class="kh"><span>AD stale (90d+)</span><span class="ic"><?=icon('users')?></span></div><b class="count" data-to="<?=(int)($ad['stale']??0)?>">0</b><em><?=(int)($ad['total']??0)?> AD objects</em></div>
        <div class="kpi eol"><div class="kh"><span>Darksight EoL SW</span><span class="ic"><?=icon('alert')?></span></div><b class="count" data-to="<?=(int)($ds['eolHosts']??0)?>">0</b><em><?=(int)($ds['eolTotal']??0)?> detections</em></div>
      </section>

      <!-- Visualizations -->
      <section class="bento">
        <div class="panel span-4"><h3><?=icon('layers')?>Asset Lifecycle Pipeline</h3><div class="pipe" id="fxPipe"></div></div>
        <div class="panel span-4"><h3><?=icon('activity')?>Decision Throughput · recent days</h3><div class="cbox" id="fxTime" style="height:160px"></div></div>
        <div class="panel span-2"><h3><?=icon('alert')?>EoL Risk by Segment</h3><div class="cbox"><canvas id="cSeg"></canvas></div></div>
        <div class="panel span-2"><h3><?=icon('check-circle')?>Decision Core</h3><div class="cbox"><canvas id="cDec"></canvas></div></div>
        <div class="panel span-2"><h3><?=icon('shield')?>Warranty Status</h3><div class="cbox" id="fxWarr"></div></div>
        <div class="panel span-2"><h3><?=icon('activity')?>Scan Health</h3><div class="cbox" id="fxScan"></div></div>
        <div class="panel span-2"><h3><?=icon('cpu')?>Intune Compliance</h3><div class="cbox" id="fxIntune"></div></div>
        <div class="panel span-2"><h3><?=icon('users')?>AD · OS Distribution</h3><div class="cbox" id="fxAdOs"></div></div>
        <div class="panel span-4"><h3><?=icon('layers')?>Inventory Coverage by Source</h3><div class="cbox" id="fxSrc" style="height:auto"></div></div>
      </section>

    <?php elseif($role==='admin'&&$screen==='inventory'):?>
      <div class="sync-info">Assets sync <b>directly from ManageEngine</b> (AMER / <?=e($_ENV['AMER_DB_Name']??'SDPnew')?>). Click "Sync from ManageEngine" to pull the latest. EoL flags and head decisions are preserved across syncs.</div>
      <form method="get" class="filters"><input type="hidden" name="screen" value="inventory">
        <input name="q" placeholder="Search asset, user, login, tag, IP…" value="<?=e($_GET['q']??'')?>" style="max-width:280px">
        <select name="dept"><option value="">All segments</option><?php foreach($segments as $s):?><option <?=($_GET['dept']??'')===$s?'selected':''?>><?=e($s)?></option><?php endforeach;?></select>
        <select name="scan"><option value="">Any scan</option><option <?=($_GET['scan']??'')==='SUCCESS'?'selected':''?>>SUCCESS</option><option <?=($_GET['scan']??'')==='FAILED'?'selected':''?>>FAILED</option></select>
        <select name="eol"><option value="">Any state</option><option <?=($_GET['eol']??'')==='EoL'?'selected':''?>>EoL</option><option <?=($_GET['eol']??'')==='Active'?'selected':''?>>Active</option></select>
        <button class="btn sm" type="submit">Filter</button></form>
      <div class="table-wrap"><table>
        <thead><tr><th>Asset / Model</th><th>User</th><th>Last Login</th><th>Segment</th><th>Scan</th><th>Service Tag</th><th>IP</th><th>Warranty</th><th>4+ yrs</th><th>EoL</th></tr></thead>
        <tbody><?php if(!$assets):?><tr><td colspan="10" style="text-align:center;padding:30px;color:var(--muted)">No assets yet. Click "Sync from ManageEngine" to pull.</td></tr><?php endif; foreach($assets as $a):?>
          <tr class="<?=(int)$a['is_eol']?'eol':''?>">
            <td><div class="tag" style="font-weight:700"><?=e($a['asset_name'])?></div><div style="font-size:11px;color:var(--muted)"><?=e(trim($a['manufacturer'].' '.$a['model']))?></div></td>
            <td><?=e($a['asset_user'])?></td><td class="tag"><?=e($a['last_login_user'])?></td>
            <td><span class="dept"><?=e($a['department'])?></span></td>
            <td><span class="pill <?=$a['last_scan_status']==='SUCCESS'?'ok':'fail'?>"><span class="d"></span><?=e($a['last_scan_status'])?></span></td>
            <td class="tag"><?=e($a['service_tag'])?></td><td class="tag"><?=e(strtok($a['ip_addresses']??'',','))?></td>
            <td><form method="post" style="margin:0"><input type="hidden" name="save_warranty" value="1"><input type="hidden" name="asset_id" value="<?=(int)$a['id']?>"><input type="date" name="warranty_expiry" class="dateedit" value="<?=e($a['warranty_expiry'])?>" onchange="this.form.submit()"></form></td>
            <td><span class="dec <?=(int)$a['over_four_years']?'Replace':''?>" style="<?=(int)$a['over_four_years']?'':'color:var(--muted)'?>"><?=(int)$a['over_four_years']?'YES':'no'?></span></td>
            <td><form method="post" style="margin:0"><input type="hidden" name="toggle_eol" value="1"><input type="hidden" name="asset_id" value="<?=(int)$a['id']?>"><button type="submit" class="switch <?=(int)$a['is_eol']?'on':''?>" title="Toggle EoL"></button></form></td>
          </tr><?php endforeach;?></tbody>
      </table></div>

    <?php elseif($role==='admin'&&$screen==='admins'):?>
      <section class="grid2">
        <div class="panel"><h3>Add / update admin</h3>
          <form method="post" class="form-grid"><input type="hidden" name="save_admin" value="1">
            <div class="full"><label>PRN (master list)</label><input name="admin_prn" inputmode="numeric" maxlength="8" placeholder="140xxxxx" required></div>
            <div class="full"><label>Role · scope</label><select name="admin_role"><option>IT Admin · All segments</option><option>IT Admin · JED sites only</option><option>Super Admin · All segments</option><option>Read-only · Reports</option></select></div>
            <div class="full"><button class="btn" type="submit">Save admin</button></div></form></div>
        <div class="panel"><h3>Administrators</h3>
          <div class="table-wrap"><table style="min-width:auto">
            <thead><tr><th>PRN</th><th>Name</th><th>Role</th><th>Scope</th><th>Last Login</th><th>Status</th><th></th></tr></thead>
            <tbody><?php foreach($admins as $a):?><tr>
              <td class="tag"><?=e($a['prn'])?></td><td><?=e($a['FULL_NAME']??'—')?></td><td><?=e($a['role'])?></td><td><span class="dept"><?=e($a['scope'])?></span></td>
              <td class="tag"><?=e($a['last_login']??'—')?></td><td><span class="pill <?=(int)$a['is_active']?'ok':'fail'?>"><span class="d"></span><?=(int)$a['is_active']?'Active':'Suspended'?></span></td>
              <td style="white-space:nowrap"><form method="post" style="display:inline;margin:0"><input type="hidden" name="admin_prn" value="<?=e($a['prn'])?>"><button class="linkbtn" name="admin_action" value="<?=(int)$a['is_active']?'suspend':'activate'?>"><?=(int)$a['is_active']?'Suspend':'Activate'?></button></form>
                <form method="post" style="display:inline;margin:0"><input type="hidden" name="admin_prn" value="<?=e($a['prn'])?>"><button class="linkbtn" style="color:var(--danger)" name="admin_action" value="remove" onclick="return confirm('Remove?')">Remove</button></form></td>
            </tr><?php endforeach;?></tbody></table></div></div>
      </section>

    <?php elseif($role==='admin'&&$screen==='segments'):?>
      <section class="grid2">
        <div class="panel"><h3>Map segment to head</h3>
          <form method="post" class="form-grid"><input type="hidden" name="save_head" value="1">
            <div class="full"><label>Department / segment</label><input name="department" placeholder="e.g. Airports Lounges - JED" required></div>
            <div class="full"><label>Head PRN</label><input name="head_prn" inputmode="numeric" maxlength="8" placeholder="140xxxxx" required></div>
            <div class="full"><button class="btn" type="submit">Save mapping</button></div></form></div>
        <div class="panel"><h3>Segment heads</h3>
          <div class="table-wrap"><table style="min-width:auto"><thead><tr><th>Segment</th><th>Head PRN</th><th>Name</th><th>Status</th></tr></thead>
            <tbody><?php foreach($heads as $h):?><tr><td><span class="dept"><?=e($h['department'])?></span></td><td class="tag"><?=e($h['head_prn'])?></td><td><?=e($h['FULL_NAME']??'—')?></td><td><span class="pill <?=(int)$h['is_active']?'ok':'fail'?>"><span class="d"></span><?=(int)$h['is_active']?'Active':'Inactive'?></span></td></tr><?php endforeach; if(!$heads):?><tr><td colspan="4" style="color:var(--muted)">No mappings yet.</td></tr><?php endif;?></tbody></table></div></div>
      </section>

    <?php elseif($role==='admin'&&$screen==='reports'):?>
      <div class="filters"><a class="btn sm" href="?export=assets&kind=all">⬇ Full inventory CSV</a>
        <a class="btn sm" href="?export=assets&kind=eol">⬇ End-of-Life CSV</a>
        <a class="btn sm light" href="?export=assets&kind=over4">⬇ Over-4-years CSV</a></div>
      <div class="panel"><h3>EoL decisions by segment</h3>
        <div class="table-wrap"><table style="min-width:auto"><thead><tr><th>Segment</th><th>Total</th><th>EoL</th><th>Replace</th><th>Extend</th><th>Return</th><th>Pending</th></tr></thead>
          <tbody><?php $rs=$conn->query("SELECT department,COUNT(*) total,SUM(is_eol) eol,SUM(decision='Replace') rep,SUM(decision='Extend') ext,SUM(decision='Return') ret,SUM(is_eol=1 AND decision IS NULL) pend FROM eol_assets GROUP BY department ORDER BY department"); while($r=$rs->fetch_assoc()):?>
            <tr><td><span class="dept"><?=e($r['department'])?></span></td><td class="tag"><?=(int)$r['total']?></td><td class="tag" style="color:var(--orange);font-weight:700"><?=(int)$r['eol']?></td><td class="tag"><?=(int)$r['rep']?></td><td class="tag"><?=(int)$r['ext']?></td><td class="tag"><?=(int)$r['ret']?></td><td class="tag"><?=(int)$r['pend']?></td></tr>
          <?php endwhile;?></tbody></table></div></div>

    <?php elseif($role==='admin'&&$screen==='audit'):?>
      <div class="tabs">
        <a class="<?=$logtab==='audit'?'on':''?>" href="?screen=audit&logtab=audit">Audit events</a>
        <a class="<?=$logtab==='logins'?'on':''?>" href="?screen=audit&logtab=logins">Login log</a>
        <a class="<?=$logtab==='sync'?'on':''?>" href="?screen=audit&logtab=sync">Sync runs</a>
        <a class="<?=$logtab==='app'?'on':''?>" href="?screen=audit&logtab=app">System log</a></div>
      <div class="table-wrap">
      <?php if($logtab==='audit'):?>
        <table><thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Asset</th><th>Detail</th><th>IP</th></tr></thead><tbody>
        <?php foreach($auditRows as $r):?><tr><td class="tag"><?=e($r['created_at'])?></td><td><?=e($r['actor_name']?:$r['actor_prn'])?></td><td><span class="dec"><?=e($r['action'])?></span></td><td class="tag"><?=e($r['asset_tag'])?></td><td><?=e($r['detail'])?></td><td class="tag"><?=e($r['ip_address'])?></td></tr><?php endforeach; if(!$auditRows):?><tr><td colspan="6" style="color:var(--muted)">No events yet.</td></tr><?php endif;?></tbody></table>
      <?php elseif($logtab==='logins'):?>
        <table><thead><tr><th>When</th><th>PRN</th><th>Name</th><th>Status</th><th>IP</th></tr></thead><tbody>
        <?php foreach($loginRows as $r):?><tr><td class="tag"><?=e($r['created_at'])?></td><td class="tag"><?=e($r['prn'])?></td><td><?=e($r['full_name'])?></td><td><span class="st <?=e($r['status'])?>"><?=e($r['status'])?></span></td><td class="tag"><?=e($r['ip_address'])?></td></tr><?php endforeach; if(!$loginRows):?><tr><td colspan="5" style="color:var(--muted)">No login events.</td></tr><?php endif;?></tbody></table>
      <?php elseif($logtab==='sync'):?>
        <table><thead><tr><th>When</th><th>By</th><th>Source</th><th>Processed</th><th>New</th><th>Updated</th><th>Skipped</th><th>Status</th></tr></thead><tbody>
        <?php foreach($syncRows as $r):?><tr><td class="tag"><?=e($r['created_at'])?></td><td><?=e($r['run_by_name']?:$r['run_by_prn'])?></td><td><?=e($r['source'])?></td><td class="tag"><?=(int)$r['processed']?></td><td class="tag"><?=(int)$r['inserted']?></td><td class="tag"><?=(int)$r['updated']?></td><td class="tag"><?=(int)$r['skipped']?></td><td><span class="st <?=$r['status']==='OK'?'SUCCESS':'FAIL'?>"><?=e($r['status'])?></span></td></tr><?php endforeach; if(!$syncRows):?><tr><td colspan="8" style="color:var(--muted)">No sync runs.</td></tr><?php endif;?></tbody></table>
      <?php else:?>
        <table><thead><tr><th>When</th><th>Level</th><th>Context</th><th>Message</th><th>IP</th></tr></thead><tbody>
        <?php foreach($appRows as $r):?><tr><td class="tag"><?=e($r['created_at'])?></td><td><span class="lvl <?=e($r['level'])?>"><?=e($r['level'])?></span></td><td><?=e($r['context'])?></td><td style="max-width:420px"><?=e($r['message'])?></td><td class="tag"><?=e($r['ip_address'])?></td></tr><?php endforeach; if(!$appRows):?><tr><td colspan="5" style="color:var(--muted)">No system logs.</td></tr><?php endif;?></tbody></table>
      <?php endif;?>
      </div>

    <?php elseif($role==='admin'&&$screen==='sources'):?>
      <?php $srcTab=$_GET['src']??'darksight'; ?>
      <div class="tabs">
        <a class="<?=$srcTab==='darksight'?'on':''?>" href="?screen=sources&src=darksight">Darksight <span class="tag">(<?=$srcCount['darksight']?>)</span></a>
        <a class="<?=$srcTab==='intune'?'on':''?>" href="?screen=sources&src=intune">Intune <span class="tag">(<?=$srcCount['intune']?>)</span></a>
        <a class="<?=$srcTab==='ad'?'on':''?>" href="?screen=sources&src=ad">Active Directory <span class="tag">(<?=$srcCount['ad']?>)</span></a>
      </div>

      <div class="panel" style="margin-bottom:16px">
        <?php $isAd=$srcTab==='ad'; ?>
        <h3>Upload <?=['darksight'=>'Darksight','intune'=>'Intune','ad'=>'Active Directory'][$srcTab]?> <?=$isAd?'(Excel .xlsx or CSV)':'(CSV or Excel .xlsx)'?></h3>
        <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
          <input type="hidden" name="upload_source" value="<?=e($srcTab)?>">
          <?php if($isAd):?>
          <div class="field" style="margin:0;min-width:170px"><label>Site / Company</label>
            <select name="ad_site" required style="min-height:44px">
              <option value="">Select company…</option>
              <option>CATRION</option>
              <option>SaudiaCatering</option>
            </select></div>
          <?php endif;?>
          <div class="field" style="margin:0;flex:1;min-width:200px"><label>File (.xlsx or .csv)</label>
            <input type="file" name="csv_file" accept=".xlsx,.xlsm,.csv,.txt" required style="min-height:44px;padding:10px 14px"></div>
          <button class="btn sm" type="submit">Upload &amp; import</button>
        </form>
        <div style="margin-top:10px;font-size:11.5px;color:var(--muted)">
          <?php if($srcTab==='darksight'):?>Expected columns: Hostname, Owner, Domain, Patch Level, INS, EOL, DSC, PAT, Total, Scan Type, Operating System, IP Addresses, IP Subnets, Last Scan<?php elseif($srcTab==='intune'):?>Expected columns: Device ID, Device name, Managed by, Ownership, Compliance, OS, OS version, Primary user UPN, Last check-in, Intune registered, Model, Serial number, Primary user display name<?php else:?>Expected columns: Computer Name, Account Status, Last Logon Timestamp, OS Name, OS Version, Machine Type, DNS Host Name, Description, OU, Managed By, SID, SamAccountName, Last Logon, Modified Date, Distinguished Name, Location. <b>Site</b> is applied from the dropdown above.<?php endif;?>
        </div>
      </div>

      <div class="table-wrap">
      <?php if($srcTab==='darksight'):?>
        <table><thead><tr><th>Hostname</th><th>Owner</th><th>Domain</th><th>Patch Level</th><th>INS</th><th>EOL</th><th>DSC</th><th>PAT</th><th>Total</th><th>Scan Type</th><th>OS</th><th>IP</th><th>Last Scan</th></tr></thead><tbody>
        <?php foreach($srcRows as $r):?><tr>
          <td class="tag" style="font-weight:700"><?=e($r['hostname'])?></td><td><?=e($r['owner'])?></td><td><?=e($r['domain'])?></td><td><?=e($r['patch_level'])?></td>
          <td class="tag"><?=e($r['ins'])?></td><td class="tag" style="<?=((int)($r['eol']??0)>0)?'color:var(--orange);font-weight:700':''?>"><?=e($r['eol'])?></td>
          <td class="tag"><?=e($r['dsc'])?></td><td class="tag"><?=e($r['pat'])?></td><td class="tag"><?=e($r['total'])?></td>
          <td><?=e($r['scan_type'])?></td><td><?=e($r['operating_system'])?></td><td class="tag"><?=e($r['ip_addresses'])?></td><td class="tag"><?=e($r['last_scan'])?></td>
        </tr><?php endforeach; if(!$srcRows):?><tr><td colspan="13" class="empty">No Darksight data. Upload a CSV above.</td></tr><?php endif;?></tbody></table>
      <?php elseif($srcTab==='intune'):?>
        <table><thead><tr><th>Device Name</th><th>Device ID</th><th>Managed By</th><th>Ownership</th><th>Compliance</th><th>OS</th><th>OS Ver</th><th>Primary User</th><th>Model</th><th>Serial</th><th>Last Check-in</th></tr></thead><tbody>
        <?php foreach($srcRows as $r):?><tr>
          <td class="tag" style="font-weight:700"><?=e($r['device_name'])?></td><td class="tag" style="font-size:10px"><?=e($r['device_id'])?></td>
          <td><?=e($r['managed_by'])?></td><td><span class="dept"><?=e($r['ownership'])?></span></td>
          <td><span class="pill <?=($r['compliance']??'')==='Compliant'?'ok':'fail'?>"><span class="d"></span><?=e($r['compliance'])?></span></td>
          <td><?=e($r['os'])?></td><td class="tag"><?=e($r['os_version'])?></td>
          <td><?=e($r['primary_user_display_name'])?></td><td><?=e($r['model'])?></td><td class="tag"><?=e($r['serial_number'])?></td>
          <td class="tag"><?=e($r['last_checkin'])?></td>
        </tr><?php endforeach; if(!$srcRows):?><tr><td colspan="11" class="empty">No Intune data. Upload a CSV above.</td></tr><?php endif;?></tbody></table>
      <?php else:?>
        <table><thead><tr><th>Computer Name</th><th>Site</th><th>Status</th><th>OS</th><th>Machine Type</th><th>DNS Name</th><th>OU</th><th>Managed By</th><th>Last Logon</th><th>Modified</th></tr></thead><tbody>
        <?php foreach($srcRows as $r):?><tr>
          <td class="tag" style="font-weight:700"><?=e($r['computer_name'])?></td>
          <td><span class="dept"><?=e($r['site'])?></span></td>
          <td><span class="pill <?=ad_enabled($r['account_status']??'')?'ok':'fail'?>"><span class="d"></span><?=e($r['account_status'])?></span></td>
          <td><?=e($r['os_name'])?></td><td><?=e($r['machine_type'])?></td><td class="tag"><?=e($r['dns_host_name'])?></td>
          <td style="max-width:200px;font-size:11px;word-break:break-all"><?=e($r['ou'])?></td><td><?=e($r['managed_by'])?></td>
          <td class="tag"><?=e($r['last_logon_timestamp'])?></td><td class="tag"><?=e($r['modified_date'])?></td>
        </tr><?php endforeach; if(!$srcRows):?><tr><td colspan="10" class="empty">No AD data. Upload a CSV above.</td></tr><?php endif;?></tbody></table>
      <?php endif;?>
      </div>

    <?php elseif($role==='admin'&&$screen==='unified'):
        $pc=fn($n)=>number_format((int)$n);
        $uqv=e($_GET['uq']??''); $ufv=$_GET['uf']??'';
        // Sortable-header + pager link builders (preserve search/filter/sort)
        $qbase=['screen'=>'unified']; if($uq!=='')$qbase['uq']=$uq; if($ufilter!=='')$qbase['uf']=$ufilter;
        $sortLink=function($key,$label)use($qbase,$usort,$udir){
            $dir=($usort===$key&&$udir==='asc')?'desc':'asc';
            $arrow=$usort===$key?($udir==='asc'?' ▲':' ▼'):'';
            $qs=http_build_query($qbase+['usort'=>$key,'udir'=>$dir]);
            return '<a class="sorth" href="?'.e($qs).'">'.$label.$arrow.'</a>';
        };
        $pageLink=function($n)use($qbase,$usort,$udir){ return '?'.e(http_build_query($qbase+['usort'=>$usort,'udir'=>$udir,'upage'=>$n])); };
        $exportQS=http_build_query($qbase+['export'=>'unified','usort'=>$usort,'udir'=>$udir]);
    ?>
      <p class="screen-intro">One row per device, correlated across <b>ManageEngine</b>, <b>Active Directory</b>, <b>Intune</b> and <b>Darksight</b> by hostname — so coverage gaps and blind spots surface immediately.</p>
      <section class="kgrid">
        <div class="kpi"><div class="kh"><span>Unique devices</span><span class="ic"><?=icon('layers')?></span></div><b class="count" data-to="<?=(int)$ucov['unique']?>">0</b></div>
        <div class="kpi ok"><div class="kh"><span>Core-3 covered</span><span class="ic"><?=icon('check-circle')?></span></div><b class="count" data-to="<?=(int)$ucov['core3']?>">0</b><em>ME + AD + Intune</em></div>
        <div class="kpi warn"><div class="kh"><span>Unmanaged (no Intune)</span><span class="ic"><?=icon('cpu')?></span></div><b class="count" data-to="<?=(int)$ucov['unmanaged']?>">0</b></div>
        <div class="kpi eol"><div class="kh"><span>Missing from AD</span><span class="ic"><?=icon('users')?></span></div><b class="count" data-to="<?=(int)$ucov['missing_ad']?>">0</b></div>
      </section>
      <section class="bento" style="margin-bottom:16px">
        <div class="panel span-2"><h3><?=icon('layers')?>Source Coverage</h3><div class="cbox" id="uxCov" style="height:auto"></div></div>
        <div class="panel span-2"><h3><?=icon('shield')?>Correlation Overview</h3><div class="cbox" id="uxMix" style="height:auto"></div></div>
        <?php
          $U4=max(1,(int)$ucov['unique']);
          $srcMeta=[
            ['me','ManageEngine','var(--dark)',$ucov['me'],$ucov['me_only']],
            ['ad','Active Directory','var(--primary)',$ucov['ad'],$ucov['ad_only']],
            ['it','Intune','var(--teal)',$ucov['intune'],$ucov['intune_only']],
            ['dk','Darksight','var(--violet)',$ucov['dark'],$ucov['dark_only']],
          ];
        ?>
        <div class="panel span-4"><h3><?=icon('layers')?>Source Presence Detail</h3>
          <div class="table-wrap" style="box-shadow:none;border-radius:12px">
            <table style="min-width:auto">
              <thead><tr><th>Source</th><th>Devices</th><th>Coverage</th><th>Exclusive (only here)</th><th>Gap vs fleet</th></tr></thead>
              <tbody>
              <?php foreach($srcMeta as [$k,$label,$col,$cnt,$excl]): $p=round($cnt/$U4*100); ?>
                <tr>
                  <td><span class="schip <?=$k?> on" style="--sc:<?=$col?>"><?=strtoupper($k)?></span> <b><?=e($label)?></b></td>
                  <td class="tag"><?=number_format($cnt)?></td>
                  <td style="min-width:160px"><span class="covbar"><i style="width:<?=$p?>%;background:<?=$col?>"></i></span> <span class="tag"><?=$p?>%</span></td>
                  <td class="tag"><?=number_format($excl)?></td>
                  <td class="tag" style="color:var(--orange);font-weight:700"><?=number_format($U4-$cnt)?></td>
                </tr>
              <?php endforeach; ?>
                <tr style="background:#f7faff">
                  <td><b>Discovered outside ManageEngine</b></td>
                  <td class="tag" colspan="4">In AD / Intune / Darksight but <b>not</b> in ManageEngine: <b style="color:var(--orange)"><?=number_format((int)$ucov['not_in_me'])?></b> devices to onboard</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <div class="srclegend">
        <span class="lg-title">Sources in each row:</span>
        <span><i class="me"></i>ManageEngine</span><span><i class="ad"></i>Active Directory</span><span><i class="it"></i>Intune</span><span><i class="dk"></i>Darksight</span>
        <span class="muted">— a chip is solid when the device exists in that system, faded when absent.</span>
      </div>
      <form method="get" class="filters"><input type="hidden" name="screen" value="unified">
        <input type="hidden" name="usort" value="<?=e($usort)?>"><input type="hidden" name="udir" value="<?=e($udir)?>">
        <div class="search-wrap"><?=icon('search')?><label class="sr-only" for="uqf">Search</label>
          <input id="uqf" name="uq" placeholder="Search device, segment, site…" value="<?=$uqv?>"></div>
        <select name="uf" onchange="this.form.submit()">
          <option value="">All devices</option>
          <option value="gaps" <?=$ufv==='gaps'?'selected':''?>>Coverage gaps (&lt;3 sources)</option>
          <option value="unmanaged" <?=$ufv==='unmanaged'?'selected':''?>>Unmanaged (no Intune)</option>
          <option value="missing_ad" <?=$ufv==='missing_ad'?'selected':''?>>Missing from AD</option>
          <option value="not_in_me" <?=$ufv==='not_in_me'?'selected':''?>>Not in ManageEngine</option>
          <option value="eol" <?=$ufv==='eol'?'selected':''?>>Flagged EoL</option>
        </select>
        <button class="btn sm" type="submit"><?=icon('search')?>Apply</button>
        <a class="btn sm light" href="?<?=e($exportQS)?>"><?=icon('download')?>Export XLSX</a>
        <span style="color:var(--muted);font-size:12px;margin-inline-start:auto"><?=$pc($unifiedTotal)?> devices · page <?=$upage?>/<?=$upages?></span>
      </form>
      <div class="table-wrap"><table>
        <thead><tr><th><?=$sortLink('device','Device')?></th><th><?=$sortLink('segment','Segment')?></th><th><?=$sortLink('sources','Sources')?></th><th><?=$sortLink('me','ManageEngine')?></th><th><?=$sortLink('ad','Active Directory')?></th><th><?=$sortLink('intune','Intune')?></th><th><?=$sortLink('dark','Darksight')?></th></tr></thead>
        <tbody>
        <?php foreach($unified as $u): $cnt=$u['me']+$u['ad']+$u['intune']+$u['dark']; ?>
          <tr class="<?=$u['eol']?'eol':''?>">
            <td class="tag" style="font-weight:700;color:var(--ink)"><?=e($u['name'])?></td>
            <td><?=$u['segment']?'<span class="dept">'.e($u['segment']).'</span>':'<span style="color:var(--muted)">—</span>'?></td>
            <td><div class="srcset">
              <span class="schip me <?=$u['me']?'on':''?>" style="--sc:var(--dark)" title="ManageEngine: <?=$u['me']?'present':'absent'?>">ME</span>
              <span class="schip ad <?=$u['ad']?'on':''?>" style="--sc:var(--primary)" title="Active Directory: <?=$u['ad']?'present':'absent'?>">AD</span>
              <span class="schip it <?=$u['intune']?'on':''?>" style="--sc:var(--teal)" title="Intune: <?=$u['intune']?'present':'absent'?>">IN</span>
              <span class="schip dk <?=$u['dark']?'on':''?>" style="--sc:var(--violet)" title="Darksight: <?=$u['dark']?'present':'absent'?>">DK</span>
              <span class="srccount <?=$cnt>=3?'good':($cnt==1?'low':'')?>"><?=$cnt?>/4</span>
            </div></td>
            <td><?php if($u['me']):?><span class="pill <?=$u['eol']?'fail':'ok'?>"><span class="d"></span><?=$u['eol']?'EoL':'Active'?></span><?php else:?><span class="miss">absent</span><?php endif;?></td>
            <td><?php if($u['ad']):?><span class="pill <?=ad_enabled($u['ad_status'])?'ok':'fail'?>"><span class="d"></span><?=e($u['ad_status']?:'AD')?></span> <?=$u['ad_site']?'<span class="tag">'.e($u['ad_site']).'</span>':''?><?php else:?><span class="miss">absent</span><?php endif;?></td>
            <td><?php if($u['intune']):?><span class="pill <?=($u['compliance']==='Compliant')?'ok':'fail'?>"><span class="d"></span><?=e($u['compliance']?:'Managed')?></span><?php else:?><span class="miss">absent</span><?php endif;?></td>
            <td><?php if($u['dark']):?><?=$u['dark_eol']>0?'<span class="pill fail"><span class="d"></span>'.(int)$u['dark_eol'].' EoL SW</span>':'<span class="pill ok"><span class="d"></span>Clean</span>'?><?php else:?><span class="miss">absent</span><?php endif;?></td>
          </tr>
        <?php endforeach; if(!$unified):?><tr><td colspan="7" class="empty">No correlated devices yet. Upload sources under <b>Data Sources</b>.</td></tr><?php endif;?>
        </tbody>
      </table></div>
      <?php if($upages>1): ?>
      <nav class="pager" aria-label="Pagination">
        <a class="pgbtn <?=$upage<=1?'disabled':''?>" href="<?=$upage>1?$pageLink($upage-1):'#'?>">‹ Prev</a>
        <?php
          $win=2; $start=max(1,$upage-$win); $end=min($upages,$upage+$win);
          if($start>1){ echo '<a class="pgbtn" href="'.$pageLink(1).'">1</a>'; if($start>2) echo '<span class="pgdots">…</span>'; }
          for($i=$start;$i<=$end;$i++) echo '<a class="pgbtn '.($i===$upage?'on':'').'" href="'.$pageLink($i).'">'.$i.'</a>';
          if($end<$upages){ if($end<$upages-1) echo '<span class="pgdots">…</span>'; echo '<a class="pgbtn" href="'.$pageLink($upages).'">'.$upages.'</a>'; }
        ?>
        <a class="pgbtn <?=$upage>=$upages?'disabled':''?>" href="<?=$upage<$upages?$pageLink($upage+1):'#'?>">Next ›</a>
        <span class="pginfo"><?=UNIFIED_PAGE?>/page · <?=$pc($unifiedTotal)?> total</span>
      </nav>
      <?php endif; ?>

    <?php elseif($role==='head'&&$screen==='queue'):?>
      <?php if(!$assets):?><div class="panel empty"><h3 style="color:var(--ink)">Nothing to action</h3><p>No devices in your segment(s) are flagged End-of-Life.</p></div>
      <?php else:?><div class="queue"><?php foreach($assets as $a): $done=!empty($a['decision']);?>
        <div class="qcard <?=$done?'done':''?>">
          <div><h4><?=e($a['asset_name'])?></h4><div class="qs"><?=e(trim($a['manufacturer'].' '.$a['model']))?> · <?=e($a['asset_user'])?></div></div>
          <dl><dt>Service tag</dt><dd><?=e($a['service_tag'])?></dd><dt>Last login</dt><dd><?=e($a['last_login_user'])?></dd><dt>Warranty</dt><dd><?=e($a['warranty_expiry']?:'—')?></dd><dt>Over 4 yrs</dt><dd><?=(int)$a['over_four_years']?'Yes':'No'?></dd></dl>
          <div class="qnote"><span>Note from IT</span><?=e($a['eol_note'])?></div>
          <?php if($done):?><div class="decided"><div class="h">✓ Decision recorded <span class="dec <?=e($a['decision'])?>"><?=e($a['decision'])?></span></div><?=$a['decision_note']?e($a['decision_note']).'<br>':''?><span style="color:var(--muted);font-size:11px">by <?=e($a['decided_by_name'])?> · <?=e($a['decided_at'])?></span></div>
          <?php else:?><form method="post"><input type="hidden" name="submit_decision" value="1"><input type="hidden" name="asset_id" value="<?=(int)$a['id']?>">
            <div class="field" style="margin-bottom:10px"><select name="decision" required><option value="">Select action…</option><option>Replace</option><option>Extend</option><option>Return</option></select></div>
            <textarea name="decision_note" placeholder="Notes (optional)…"></textarea>
            <button class="btn" type="submit" style="width:100%;margin-top:10px">Submit decision</button></form><?php endif;?>
        </div><?php endforeach;?></div><?php endif;?>
    <?php endif;?>
  </main>
  <footer class="appfoot"><div>Created by CATRION IT Team</div><?=$POLICY?></footer>
  </div>
</div>

<script>
/* Mobile off-canvas navigation (all screens) */
(function(){
  var t=document.getElementById('navToggle'),s=document.getElementById('sidebar'),c=document.getElementById('navScrim');
  function open(o){ if(!s||!c)return; s.classList.toggle('open',o); c.hidden=!o; requestAnimationFrame(function(){c.classList.toggle('show',o);}); if(t)t.setAttribute('aria-expanded',o?'true':'false'); }
  if(t)t.addEventListener('click',function(){open(!s.classList.contains('open'));});
  if(c)c.addEventListener('click',function(){open(false);});
  document.addEventListener('keydown',function(e){if(e.key==='Escape')open(false);});
})();
</script>

<?php if($role==='admin'&&$screen==='dashboard'):?>
<script>
const seg=<?php $l=[];$v=[];$rs=$conn->query("SELECT department,SUM(is_eol) e FROM eol_assets GROUP BY department ORDER BY e DESC");if($rs)while($r=$rs->fetch_assoc()){$l[]=$r['department'];$v[]=(int)$r['e'];}echo json_encode(['labels'=>$l,'data'=>$v]);?>;
const dec=<?php $rs=$conn->query("SELECT SUM(decision='Replace') r,SUM(decision='Extend') e,SUM(decision='Return') t,SUM(is_eol=1 AND decision IS NULL) p FROM eol_assets");$r=$rs?$rs->fetch_assoc():[];echo json_encode([(int)($r['r']??0),(int)($r['e']??0),(int)($r['t']??0),(int)($r['p']??0)]);?>;
const stats=<?php echo json_encode(['total'=>(int)$stats['total'],'active'=>(int)$stats['active'],'eol'=>(int)$stats['eol'],'over4'=>(int)$stats['over4']]);?>;
const dash=<?php echo json_encode($dash);?>;
(function(){
  const reduce=matchMedia('(prefers-reduced-motion: reduce)').matches;
  const easeOut=t=>1-Math.pow(1-t,3);
  function fmt(n){return (n||0).toLocaleString();}

  // count-ups
  document.querySelectorAll('.count').forEach(function(el){
    var to=+el.dataset.to||0; if(reduce||to===0){el.textContent=fmt(to);return;}
    var t0=performance.now();(function s(now){var p=Math.min(1,(now-t0)/1100);el.textContent=fmt(Math.round(easeOut(p)*to));if(p<1)requestAnimationFrame(s);})(t0);
  });

  // pipeline
  (function(){var el=document.getElementById('fxPipe');if(!el)return;
    var arrow='<span class="pa"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>';
    var decided=dec[0]+dec[1]+dec[2];
    var stg=[['Total fleet',stats.total,'rgba(90,146,219,.22)'],['Active',stats.active,'rgba(166,209,120,.30)'],['Aging 4+ yrs',stats.over4,'rgba(190,134,23,.26)'],['Flagged EoL',stats.eol,'rgba(212,91,37,.26)'],['Decided',decided,'rgba(14,159,142,.26)']];
    var mx=Math.max(1,stats.total);
    el.innerHTML=stg.map(function(s){return '<div class="pstage"><div class="pb" style="--pc:'+s[2]+'"><span class="pv">'+fmt(s[1])+'</span><span class="pn">'+s[0]+'</span><i></i></div>'+arrow+'</div>';}).join('');
    requestAnimationFrame(function(){el.querySelectorAll('.pstage').forEach(function(p,i){p.querySelector('.pb i').style.width=Math.max(6,Math.round((stg[i][1]||0)/mx*100))+'%';});});
  })();

  function barlist(id,items){var el=document.getElementById(id);if(!el)return;
    if(!items.length){el.innerHTML='<div class="spark-empty">No data.</div>';return;}
    var mx=items.reduce(function(m,x){return Math.max(m,x.v);},1);
    el.innerHTML='<div class="barlist">'+items.map(function(x){return '<div class="brow"><span class="bl">'+x.l+'</span><span class="bt"><i style="width:'+Math.max(3,Math.round(x.v/mx*100))+'%'+(x.c?';background:'+x.c:'')+'"></i></span><b>'+fmt(x.v)+'</b></div>';}).join('')+'</div>';}
  function donut(id,segs,center){var el=document.getElementById(id);if(!el)return;
    var tot=segs.reduce(function(a,s){return a+s.v;},0)||1,acc=0,stops=[];
    segs.forEach(function(s){stops.push(s.c+' '+(acc/tot*360)+'deg '+((acc+s.v)/tot*360)+'deg');acc+=s.v;});
    el.innerHTML='<div class="dwrap"><div class="dring" style="background:conic-gradient('+stops.join(',')+')"><span>'+fmt(tot)+'<small>'+center+'</small></span></div><div class="dleg">'+segs.map(function(s){return '<div><span class="dot" style="background:'+s.c+'"></span>'+s.l+' <b>'+fmt(s.v)+'</b></div>';}).join('')+'</div></div>';}
  function stacked(id,segs){var el=document.getElementById(id);if(!el)return;
    var tot=segs.reduce(function(a,s){return a+s.v;},0)||1;
    el.innerHTML='<div class="stack">'+segs.map(function(s){return '<i style="width:'+(s.v/tot*100)+'%;background:'+s.c+'"></i>';}).join('')+'</div><div class="stkleg">'+segs.map(function(s){return '<div><span class="dot" style="background:'+s.c+'"></span>'+s.l+'<b>'+fmt(s.v)+'</b></div>';}).join('')+'</div>';}
  function sparkline(id,pts){var el=document.getElementById(id);if(!el)return;
    if(!pts.length){el.innerHTML='<div class="spark-empty">No decisions recorded yet.</div>';return;}
    var W=600,H=120,pad=12,n=pts.length,max=Math.max.apply(null,pts.map(function(p){return p.c;}).concat(1));
    var x=function(i){return pad+i*((W-2*pad)/Math.max(1,n-1));},y=function(v){return H-pad-v/max*(H-2*pad);};
    var line=pts.map(function(p,i){return (i?'L':'M')+x(i).toFixed(1)+' '+y(p.c).toFixed(1);}).join(' ');
    var area=line+' L'+x(n-1).toFixed(1)+' '+(H-pad)+' L'+x(0).toFixed(1)+' '+(H-pad)+' Z';
    el.innerHTML='<svg class="spark-svg" viewBox="0 0 '+W+' '+H+'" preserveAspectRatio="none"><defs><linearGradient id="sg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#5A92DB" stop-opacity=".40"/><stop offset="1" stop-color="#5A92DB" stop-opacity="0"/></linearGradient></defs><path d="'+area+'" fill="url(#sg)"/><path d="'+line+'" fill="none" stroke="#5A92DB" stroke-width="2.5" vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round"/></svg>';}

  sparkline('fxTime',(dash.decByDay||[]).map(function(d){return {c:d.c};}));
  stacked('fxWarr',[{l:'Expired',v:(dash.warranty.expired||0),c:'#D45B25'},{l:'≤ 90 days',v:(dash.warranty.soon||0),c:'#BE8617'},{l:'Active',v:(dash.warranty.active||0),c:'#0E9F8E'},{l:'Unknown',v:(dash.warranty.unknown||0),c:'#94a3b8'}]);
  donut('fxScan',[{l:'Success',v:(dash.scan.SUCCESS||0),c:'#0E9F8E'},{l:'Failed',v:(dash.scan.FAILED||0),c:'#D45B25'},{l:'Other',v:(dash.scan.OTHER||0),c:'#94a3b8'}],'SCANS');
  donut('fxIntune',[{l:'Compliant',v:((dash.intune||{}).compliant||0),c:'#0E9F8E'},{l:'Non-compliant',v:((dash.intune||{}).noncompliant||0),c:'#D45B25'},{l:'Unknown',v:((dash.intune||{}).unknown||0),c:'#94a3b8'}],'DEVICES');
  barlist('fxAdOs',(dash.adOs||[]).map(function(o){return {l:o.k,v:o.c};}));
  barlist('fxSrc',Object.keys(dash.src||{}).map(function(k){return {l:k,v:dash.src[k],c:'linear-gradient(90deg,#003F53,#5A92DB)'};}));

  if(window.Chart){
    Chart.defaults.font.family="'Gotham',system-ui,'Segoe UI',sans-serif";Chart.defaults.color='#64748b';
    var tip={backgroundColor:'#062b58',padding:10,cornerRadius:8};
    if(document.getElementById('cSeg'))
      new Chart(cSeg,{type:'bar',data:{labels:seg.labels,datasets:[{data:seg.data,backgroundColor:'#D45B25',borderRadius:7,maxBarThickness:30}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:tip},scales:{x:{beginAtZero:true,ticks:{precision:0},grid:{color:'#eef4fb'}},y:{ticks:{font:{size:10}},grid:{display:false}}}}});
    if(document.getElementById('cDec'))
      new Chart(cDec,{type:'doughnut',data:{labels:['Replace','Extend','Return','Pending'],datasets:[{data:dec,backgroundColor:['#D45B25','#5A92DB','#A898AF','#ACC8ED'],borderColor:'#fff',borderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,cutout:'62%',plugins:{legend:{position:'bottom'},tooltip:tip}}});
  } else {
    // Fallback for the two Chart.js panels if the library is unavailable
    var sc=document.getElementById('cSeg'); if(sc){barlist(sc.parentNode.id||(sc.parentNode.id='fxSegFb'),seg.labels.map(function(l,i){return {l:l,v:seg.data[i]||0,c:'linear-gradient(90deg,#D45B25,#5A92DB)'};}));}
    var dcp=document.getElementById('cDec'); if(dcp){donut(dcp.parentNode.id||(dcp.parentNode.id='fxDecFb'),[{l:'Replace',v:dec[0],c:'#D45B25'},{l:'Extend',v:dec[1],c:'#5A92DB'},{l:'Return',v:dec[2],c:'#A898AF'},{l:'Pending',v:dec[3],c:'#ACC8ED'}],'EoL');}
  }
})();
</script>
<?php endif;?>

<?php if($role==='admin'&&$screen==='unified'):?>
<script>
const ucov=<?php echo json_encode($ucov);?>;
(function(){
  var reduce=matchMedia('(prefers-reduced-motion: reduce)').matches, easeOut=function(t){return 1-Math.pow(1-t,3);};
  function fmt(n){return (n||0).toLocaleString();}
  document.querySelectorAll('.count').forEach(function(el){var to=+el.dataset.to||0;if(reduce||!to){el.textContent=fmt(to);return;}var t0=performance.now();(function s(now){var p=Math.min(1,(now-t0)/1000);el.textContent=fmt(Math.round(easeOut(p)*to));if(p<1)requestAnimationFrame(s);})(t0);});
  // source coverage bars
  (function(){var el=document.getElementById('uxCov');if(!el)return;
    var items=[['ManageEngine',ucov.me,'#003F53'],['Active Directory',ucov.ad,'#5A92DB'],['Intune',ucov.intune,'#0E9F8E'],['Darksight',ucov.dark,'#7c5cff']];
    var mx=Math.max(1,ucov.unique);
    el.innerHTML='<div class="barlist">'+items.map(function(x){var p=Math.round(x[1]/mx*100);return '<div class="brow"><span class="bl">'+x[0]+'</span><span class="bt"><i style="width:'+Math.max(3,p)+'%;background:'+x[2]+'"></i></span><b>'+fmt(x[1])+' · '+p+'%</b></div>';}).join('')+'</div>';
  })();
  // correlation overview donut (core-3 vs unmanaged vs me-only)
  (function(){var el=document.getElementById('uxMix');if(!el)return;
    var segs=[['Core-3 covered',ucov.core3,'#0E9F8E'],['Unmanaged',ucov.unmanaged,'#D45B25'],['ME-only',ucov.me_only,'#BE8617']];
    var tot=segs.reduce(function(a,s){return a+s[1];},0)||1,acc=0,stops=[];
    segs.forEach(function(s){stops.push(s[2]+' '+(acc/tot*360)+'deg '+((acc+s[1])/tot*360)+'deg');acc+=s[1];});
    el.innerHTML='<div class="dwrap"><div class="dring" style="background:conic-gradient('+stops.join(',')+')"><span>'+fmt(ucov.unique)+'<small>DEVICES</small></span></div><div class="dleg">'+segs.map(function(s){return '<div><span class="dot" style="background:'+s[2]+'"></span>'+s[0]+' <b>'+fmt(s[1])+'</b></div>';}).join('')+'</div></div>';
  })();
})();
</script>
<?php endif;?>
<?php endif;?>
</body></html>
