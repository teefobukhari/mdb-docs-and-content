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
 * NOTE: This revision improves UI/UX only. All authentication, database,
 *       sync and business logic is unchanged from the original.
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

/* ---------- inline SVG icon set (consistent, accessible — replaces emoji/unicode) ---------- */
function icon($n,$cls='ic'){
    $p=[
        'dashboard'=>'<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'inventory'=>'<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/>',
        'admins'=>'<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6M16 11h6"/>',
        'segments'=>'<circle cx="6" cy="6" r="2.6"/><circle cx="6" cy="18" r="2.6"/><path d="M20 4v6a4 4 0 0 1-4 4H6"/>',
        'reports'=>'<path d="M3 3v18h18"/><rect x="7" y="10" width="3" height="7" rx="1"/><rect x="12" y="6" width="3" height="11" rx="1"/><rect x="17" y="13" width="3" height="4" rx="1"/>',
        'audit'=>'<path d="M8 6h12M8 12h12M8 18h12"/><circle cx="3.6" cy="6" r="1.3"/><circle cx="3.6" cy="12" r="1.3"/><circle cx="3.6" cy="18" r="1.3"/>',
        'queue'=>'<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'logout'=>'<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
        'sync'=>'<path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 4v5h-5"/>',
        'download'=>'<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5M12 15V3"/>',
        'check'=>'<path d="M20 6 9 17l-5-5"/>',
        'check-circle'=>'<circle cx="12" cy="12" r="10"/><path d="m8.5 12 2.5 2.5 5-5"/>',
        'alert'=>'<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
        'info'=>'<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
        'menu'=>'<path d="M3 6h18M3 12h18M3 18h18"/>',
        'x'=>'<path d="M18 6 6 18M6 6l12 12"/>',
        'search'=>'<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'shield'=>'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'clock'=>'<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'arrow'=>'<path d="M5 12h14M13 6l6 6-6 6"/>',
        'box'=>'<path d="m21 8-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8"/>',
        'inbox-empty'=>'<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
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
    // Test mode: skip SMS entirely
    if(($_ENV['TEST_OTP_MODE']??'0')==='1') return true;
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
    // In test mode, use the fixed test value
    if(($_ENV['TEST_OTP_MODE']??'0')==='1') return $_ENV['TEST_OTP_VALUE']??'1234';
    return (string)rand(1000,9999);
}
function otp_matches($entered,$stored,$expiry){
    if(($_ENV['TEST_OTP_MODE']??'0')==='1') return $entered===($_ENV['TEST_OTP_VALUE']??'1234');
    return $entered===(string)$stored && strtotime($expiry)>time();
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

/* SSO callback: bridge stores code/state in cookies, redirects here with ?sso=1 */
if(!$loggedIn && isset($_GET['sso'])){
    $sso_code=$_COOKIE['sso_code']??''; $sso_state=$_COOKIE['sso_state']??'';
    error_log('EOL SSO callback: sso='.($_GET['sso']??'').' cookie_code='.($sso_code?'yes('.strlen($sso_code).')':'EMPTY').' cookie_state='.($sso_state?'yes('.strlen($sso_state).')':'EMPTY'));
    if($sso_code!=='' && $sso_state!==''){
        $sso_ok=false; $sso_err='';
        try {
            $sso_autoload=__DIR__.'/../SSO/vendor/autoload.php';
            if(!file_exists($sso_autoload)) throw new \RuntimeException('SSO vendor not found.');
            require_once __DIR__.'/../SSO/connections/sso_config.php';
            require_once $sso_autoload;

            // Validate CSRF state
            $statePayload=@json_decode(@base64_decode($sso_state),true);
            if(!is_array($statePayload)||empty($statePayload['csrf'])) throw new \RuntimeException('Bad SSO state.');
            $sessCsrf=$_SESSION['sso_csrf']??''; $cookieCsrf=$_COOKIE['sso_csrf']??'';
            if($sessCsrf===''&&$cookieCsrf!==''){$_SESSION['sso_csrf']=$cookieCsrf;$sessCsrf=$cookieCsrf;}
            if(!hash_equals($sessCsrf,(string)$statePayload['csrf'])) throw new \RuntimeException('CSRF mismatch. Try again.');

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
    } else {
        $error='SSO callback received but code/state cookies are empty. Please try again.';
        error_log('EOL SSO: cookies empty — sso_code and sso_state not found');
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
                $success='OTP sent.'.(($_ENV['TEST_OTP_MODE']??'0')==='1'?' (Test mode — use '.$_ENV['TEST_OTP_VALUE'].')':'');
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

/* ============ DATA ============ */
$screen=$_GET['screen']??($role==='head'?'queue':'dashboard');
$assets=[]; $stats=['total'=>0,'active'=>0,'eol'=>0,'over4'=>0]; $admins=[]; $heads=[]; $segments=[];
$auditRows=[]; $loginRows=[]; $appRows=[]; $syncRows=[]; $logtab=$_GET['logtab']??'audit';
if($loggedIn&&$conn){
    if($role==='admin'){
        if($r=$conn->query("SELECT COUNT(*) t,SUM(is_eol) ee,SUM(over_four_years) o FROM eol_assets")->fetch_assoc()){
            $stats['total']=(int)$r['t'];$stats['eol']=(int)$r['ee'];$stats['over4']=(int)$r['o'];$stats['active']=$stats['total']-$stats['eol']; }
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
        if($screen==='audit'){
            if($logtab==='audit'){ $rs=$conn->query("SELECT * FROM eol_audit_log ORDER BY id DESC LIMIT 200"); while($row=$rs->fetch_assoc())$auditRows[]=$row; }
            elseif($logtab==='logins'){ $rs=$conn->query("SELECT * FROM eol_login_log ORDER BY id DESC LIMIT 200"); while($row=$rs->fetch_assoc())$loginRows[]=$row; }
            elseif($logtab==='sync'){ $rs=$conn->query("SELECT * FROM eol_sync_runs ORDER BY id DESC LIMIT 100"); while($row=$rs->fetch_assoc())$syncRows[]=$row; }
            elseif($logtab==='app'){ $rs=$conn->query("SELECT * FROM eol_app_log ORDER BY id DESC LIMIT 200"); while($row=$rs->fetch_assoc())$appRows[]=$row; }
        }
    } elseif($role==='head'&&$myDepts){
        $ph=rtrim(str_repeat('?,',count($myDepts)),','); $t=str_repeat('s',count($myDepts));
        $st=$conn->prepare("SELECT * FROM eol_assets WHERE is_eol=1 AND department IN ($ph) ORDER BY decision IS NOT NULL, asset_name");
        $st->bind_param($t,...$myDepts); $st->execute(); $rs=$st->get_result(); while($row=$rs->fetch_assoc())$assets[]=$row; $st->close();
    }
}
$LOGO='https://e-services.catrion.com/EOL/image/catrion-logo-white.png';
$POLICY='<div class="policy"><a href="https://www.catrion.com/terms-and-conditions" target="_blank" rel="noopener noreferrer">Terms &amp; Conditions</a><span aria-hidden="true">•</span><a href="https://www.catrion.com/privacy-policy" target="_blank" rel="noopener noreferrer">Privacy Policy</a><span aria-hidden="true">•</span><a href="https://www.catrion.com/cookie-policy" target="_blank" rel="noopener noreferrer">Cookie Policy</a></div>';
$SCREEN_TITLES=['dashboard'=>'Dashboard','inventory'=>'Asset Inventory','admins'=>'Admin Users','segments'=>'Segment Heads','reports'=>'Reports','audit'=>'Audit Log','queue'=>'Replacement Queue'];
$pageTitle=$SCREEN_TITLES[$screen]??'Asset Lifecycle';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>CATRION · <?=e($loggedIn?$pageTitle:'Asset Lifecycle')?></title>
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="color-scheme" content="light">
<link rel="icon" type="image/x-icon" href="favicon.ico">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
@font-face{font-family:'Gotham';font-weight:300;src:url('fonts/Gotham-Light.woff') format('woff');font-display:swap}
@font-face{font-family:'Gotham';font-weight:500;src:url('fonts/Gotham-Medium.woff') format('woff');font-display:swap}
@font-face{font-family:'Gotham';font-weight:700;src:url('fonts/Gotham-Bold.woff') format('woff');font-display:swap}
:root{--primary:#4f86d5;--primary-d:#1a5fa8;--dark:#003F53;--navy:#062b58;--ink:#0f172a;--muted:#586b82;--light:#ACC8ED;--soft:#D6E4F6;--bg:#f0f7ff;--card:#fff;--border:#dae5f5;--orange:#D45B25;--gold:#BE8617;--lav:#A898AF;--ok:#047857;--danger:#b91c1c;--shadow:0 4px 20px rgba(0,40,100,.08);--shadow-md:0 8px 36px rgba(0,40,100,.12);--shadow-lg:0 24px 70px rgba(0,57,122,.15);--ring:0 0 0 4px rgba(79,134,213,.30);--t:.18s ease}
*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{margin:0;font-family:'Gotham',system-ui,'Segoe UI',sans-serif;font-weight:300;background:var(--bg);color:var(--ink);-webkit-font-smoothing:antialiased;line-height:1.5}
a{text-decoration:none;color:inherit}b,strong,h1,h2,h3{font-weight:700}
svg.ic{width:18px;height:18px;flex-shrink:0;display:block}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
::-webkit-scrollbar{width:7px;height:7px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:var(--light);border-radius:4px}::-webkit-scrollbar-thumb:hover{background:var(--primary)}

/* Visible keyboard focus everywhere */
a:focus-visible,button:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible,.switch:focus-visible{outline:none;box-shadow:var(--ring);border-radius:10px}
.skip-link{position:absolute;left:12px;top:-60px;z-index:200;background:var(--primary-d);color:#fff;padding:10px 16px;border-radius:10px;font-weight:700;font-size:13px;transition:top var(--t)}
.skip-link:focus{top:12px}

/* LOGIN */
.login-page{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;background:linear-gradient(145deg,#e8f2ff 0%,#f2f8ff 55%,#ddeeff 100%)}
.login-shell{width:100%;max-width:1060px;min-height:620px;display:grid;grid-template-columns:.92fr 1.08fr;background:#fff;border:1px solid var(--border);border-radius:26px;box-shadow:var(--shadow-lg);overflow:hidden}
.login-hero{background:linear-gradient(150deg,#1a5fa8 0%,#003F53 50%,#011e30 100%);color:#fff;padding:48px;display:flex;flex-direction:column;justify-content:space-between;position:relative;overflow:hidden}
.login-hero::before{content:'';position:absolute;top:-100px;right:-80px;width:380px;height:380px;border-radius:50%;background:rgba(255,255,255,.05);pointer-events:none}
.login-hero::after{content:'';position:absolute;bottom:-120px;left:-60px;width:300px;height:300px;border-radius:50%;background:rgba(79,134,213,.1);pointer-events:none}
.brand{display:flex;align-items:center;gap:13px;position:relative;z-index:1}.brand img{height:30px}
.login-hero h1{font-size:30px;line-height:1.18;margin:34px 0 12px;position:relative;z-index:1}
.login-hero>p{line-height:1.75;color:rgba(255,255,255,.85);margin:0 0 28px;position:relative;z-index:1;font-size:13.5px}
.hero-features{display:flex;flex-direction:column;gap:13px;position:relative;z-index:1}
.hero-feat{display:flex;align-items:center;gap:11px;font-size:12.5px;color:rgba(255,255,255,.9)}
.hero-feat svg{width:18px;height:18px;color:var(--light);flex-shrink:0}
.login-panel{padding:52px;display:flex;flex-direction:column;justify-content:center}
.lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.16em;color:var(--primary);margin-bottom:12px;display:flex;align-items:center;gap:10px}
.lbl::before{content:'';width:22px;height:2px;background:var(--primary);border-radius:1px}
.login-panel h2{font-size:27px;margin:0 0 6px;letter-spacing:-.01em}.sub{color:var(--muted);line-height:1.7;margin:0 0 26px;font-size:13.5px}

/* NOTICES / TOASTS */
.notices{display:flex;flex-direction:column;gap:10px;margin:0 0 18px}
.notice{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border-radius:12px;border-left:3px solid #a7f3d0;background:#f0fdf4;color:var(--ok);font-weight:500;font-size:13.5px;animation:slideIn .25s ease}
.notice svg{width:18px;height:18px;flex-shrink:0;margin-top:1px}
.notice span{flex:1}
.notice.error{border-left-color:#fca5a5;background:#fff8f8;color:var(--danger)}
.notice-x{background:none;border:0;color:inherit;cursor:pointer;opacity:.55;padding:2px;border-radius:6px;flex-shrink:0;line-height:0}
.notice-x:hover{opacity:1;background:rgba(0,0,0,.05)}
@keyframes slideIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}

.field{margin-bottom:18px}label{display:block;margin-bottom:8px;font-weight:700;font-size:12.5px;color:var(--dark);letter-spacing:.01em}
input,select,textarea{width:100%;min-height:50px;border:1.5px solid var(--border);border-radius:11px;padding:0 16px;font-size:14.5px;font-family:inherit;background:#fff;color:var(--ink);transition:border-color var(--t),box-shadow var(--t)}
textarea{padding:12px 16px;min-height:72px;resize:vertical;font-size:13.5px;line-height:1.5}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--primary);box-shadow:var(--ring)}
input::placeholder{color:#9fbbdb}
.otp-input{letter-spacing:.5em;font-size:20px;text-align:center;font-weight:700;font-family:ui-monospace,Menlo,monospace}
.hint{font-size:11.5px;color:var(--muted);margin-top:7px}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:50px;border:0;border-radius:11px;background:linear-gradient(140deg,#1a5fa8,var(--primary));color:#fff;font-size:14px;font-weight:700;cursor:pointer;padding:0 22px;font-family:inherit;transition:filter var(--t),transform var(--t),box-shadow var(--t);box-shadow:0 4px 14px rgba(79,134,213,.35);letter-spacing:.02em}
.btn svg{width:17px;height:17px}
.btn:hover{filter:brightness(1.08);transform:translateY(-1px);box-shadow:0 7px 22px rgba(79,134,213,.45)}
.btn:active{transform:translateY(0);filter:brightness(.97)}
.btn[disabled],.btn.loading{opacity:.75;cursor:progress;pointer-events:none;transform:none}
.btn.light{background:#fff;color:var(--ink);border:1.5px solid var(--border);box-shadow:var(--shadow);letter-spacing:normal}
.btn.light:hover{background:var(--soft);border-color:var(--light);transform:translateY(-1px);box-shadow:var(--shadow-md)}
.btn.sm{min-height:40px;font-size:12.5px;padding:0 15px;box-shadow:0 3px 10px rgba(79,134,213,.25)}
.btn.sm svg{width:15px;height:15px}
.btn.sm.light{box-shadow:var(--shadow)}
.btn.light .ms-logo i{display:block;border-radius:1px}
.spinner{width:16px;height:16px;border:2.5px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite}
.btn.light .spinner{border-color:rgba(79,134,213,.3);border-top-color:var(--primary)}
@keyframes spin{to{transform:rotate(360deg)}}

.sep{display:flex;align-items:center;gap:12px;margin:22px 0;color:var(--muted);font-size:11.5px;font-weight:500;letter-spacing:.04em}.sep::before,.sep::after{content:"";flex:1;height:1px;background:var(--border)}
.foot{text-align:center;color:#7d93a8;font-size:11.5px;margin-top:20px;font-weight:500}
.policy{margin-top:8px;display:flex;gap:9px;justify-content:center;flex-wrap:wrap}.policy a{color:var(--primary);font-weight:500;transition:color var(--t)}.policy a:hover{color:var(--dark);text-decoration:underline}.policy span{opacity:.4}

/* APP SHELL */
.app{display:grid;grid-template-columns:256px 1fr;min-height:100vh}
.appbar{display:none}
.sidebar{background:linear-gradient(180deg,#003d52 0%,#062554 58%,#04193e 100%);color:#fff;padding:24px 14px;display:flex;flex-direction:column;gap:3px;position:sticky;top:0;height:100vh;overflow-y:auto}
.sidebar .brand{margin-bottom:18px;padding-left:6px}.sidebar .brand img{height:27px}
.user-chip{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.10);border-radius:12px;padding:11px 13px;margin-bottom:16px;display:flex;align-items:center;gap:10px}
.user-avatar{width:36px;height:36px;border-radius:10px;flex-shrink:0;background:linear-gradient(135deg,var(--primary),#89b4e8);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px}
.user-info .un{font-size:12.5px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:158px}
.user-info .ur{font-size:10px;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.07em;margin-top:2px}
.nav a{display:flex;align-items:center;gap:11px;padding:11px 13px;border-radius:10px;font-weight:500;color:rgba(255,255,255,.78);font-size:13px;transition:background var(--t),color var(--t);margin-bottom:2px}
.nav a:hover{background:rgba(255,255,255,.10);color:#fff}
.nav a.on{background:#fff;color:var(--dark);font-weight:700;box-shadow:0 4px 14px rgba(0,0,0,.18)}
.nav a svg{width:17px;height:17px;opacity:.8}
.nav a.on svg{opacity:1}
.nav a:focus-visible{box-shadow:0 0 0 3px rgba(255,255,255,.55)}
.navsec{font-size:9px;letter-spacing:.2em;text-transform:uppercase;color:rgba(255,255,255,.4);margin:16px 0 7px 13px;font-weight:700}
.sidebar form.signout{margin-top:auto;padding-top:14px}
.sidebar .btn.light{border-color:rgba(255,255,255,.14);color:rgba(255,255,255,.9);background:rgba(255,255,255,.08);box-shadow:none}
.sidebar .btn.light:hover{background:rgba(255,255,255,.16);border-color:rgba(255,255,255,.25);transform:none}
.scrim{display:none}

.content{display:flex;flex-direction:column;min-height:100vh;background:var(--bg)}
.main{flex:1;padding:28px 32px}
.topbar{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:26px}
.topbar h1{margin:0;font-size:26px;letter-spacing:-.02em}.topbar .meta{color:var(--muted);font-size:13px;margin-top:5px}
.topbar .meta b{color:var(--dark);font-weight:700}

/* STAT CARDS */
.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
.card{background:#fff;border:1px solid var(--border);border-radius:18px;padding:22px;box-shadow:var(--shadow);position:relative;overflow:hidden;transition:transform var(--t),box-shadow var(--t)}
.card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}
.card::after{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--light),var(--primary))}
.card.eol::after{background:linear-gradient(90deg,#f5a87a,var(--orange))}.card.warn::after{background:linear-gradient(90deg,#f0d08a,var(--gold))}.card.ok::after{background:linear-gradient(90deg,#6ee7b7,var(--ok))}
.card-top{display:flex;align-items:center;justify-content:space-between;gap:10px}
.card span{color:var(--muted);font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.07em}
.card-ic{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:var(--soft);color:var(--primary)}
.card-ic svg{width:18px;height:18px}
.card.eol .card-ic{background:#fdeae0;color:var(--orange)}.card.warn .card-ic{background:#fbf0d5;color:var(--gold)}.card.ok .card-ic{background:#dcfce7;color:var(--ok)}
.card b{display:block;font-size:38px;color:var(--dark);margin:10px 0 0;letter-spacing:-.03em;line-height:1.05}
.card.eol b{color:var(--orange)}.card.warn b{color:var(--gold)}.card.ok b{color:var(--ok)}

/* PANELS & CHARTS */
.charts{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:22px}
.panel{background:#fff;border:1px solid var(--border);border-radius:18px;padding:22px 24px;box-shadow:var(--shadow)}
.panel h3{margin:0 0 16px;color:var(--dark);font-size:14px;letter-spacing:.01em;display:flex;align-items:center;gap:9px}
.panel h3::before{content:'';width:3px;height:15px;border-radius:2px;background:linear-gradient(to bottom,var(--primary),var(--light));flex-shrink:0}
.cbox{height:240px}

/* FILTERS */
.filters{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:center}
.search-wrap{position:relative;flex:1;min-width:220px;max-width:300px}
.search-wrap svg{position:absolute;left:13px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--muted);pointer-events:none}
.search-wrap input{padding-left:38px;min-height:42px;border-radius:10px;font-size:13px;width:100%}
.filters select{min-height:42px;min-width:140px;border-radius:10px;font-size:13px}

/* TABLE */
.table-wrap{overflow:auto;border:1px solid var(--border);border-radius:16px;background:#fff;box-shadow:var(--shadow)}
table{width:100%;border-collapse:collapse;font-size:12.5px;min-width:760px}
thead th{background:linear-gradient(180deg,#f4f9ff,#edf4ff);color:var(--dark);font-weight:700;text-align:left;padding:12px 14px;border-bottom:1.5px solid var(--border);white-space:nowrap;font-size:10px;letter-spacing:.07em;text-transform:uppercase;position:sticky;top:0;z-index:1}
td{padding:11px 14px;border-bottom:1px solid #f0f7ff;vertical-align:middle}
tbody tr{transition:background var(--t)}
tbody tr:hover{background:#f5faff}
tbody tr:last-child td{border-bottom:none}
tr.eol{background:linear-gradient(90deg,#fef2ec,transparent 60%)}
tr.eol:hover{background:linear-gradient(90deg,#fde5d9,#fdf6ff 60%)}
.tag{font-family:ui-monospace,Menlo,monospace;font-size:11.5px;color:var(--muted)}
.dept{background:var(--soft);color:var(--dark);padding:3px 9px;border-radius:7px;font-size:11px;white-space:nowrap;font-weight:700;display:inline-block}

/* PILLS */
.pill{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;letter-spacing:.02em}
.pill .d{width:6px;height:6px;border-radius:50%;flex-shrink:0}
.pill.ok{background:#dcfce7;color:var(--ok)}.pill.ok .d{background:var(--ok)}.pill.fail{background:#fee2e2;color:var(--danger)}.pill.fail .d{background:var(--danger)}

/* SWITCH */
.switch{width:46px;height:26px;border-radius:13px;background:#cbd5e1;position:relative;border:0;cursor:pointer;transition:background var(--t),box-shadow var(--t);flex-shrink:0}
.switch:hover{box-shadow:0 0 0 3px rgba(212,91,37,.18)}.switch.on{background:var(--orange)}
.switch::after{content:"";position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.15);transition:transform .2s cubic-bezier(.4,0,.2,1)}
.switch.on::after{transform:translateX(20px)}

.dateedit{font-family:ui-monospace,Menlo,monospace;font-size:11px;width:140px;min-height:38px;border-radius:9px;padding:0 10px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:18px}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.form-grid .full{grid-column:1/-1}
.linkbtn{background:none;border:none;color:var(--primary);font-weight:700;font-size:12.5px;cursor:pointer;padding:4px 6px;border-radius:7px;transition:color var(--t),background var(--t)}.linkbtn:hover{color:var(--dark);background:var(--soft)}

/* QUEUE */
.queue{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:18px}
.qcard{background:#fff;border:1px solid var(--border);border-radius:18px;padding:20px;box-shadow:var(--shadow);position:relative;overflow:hidden;transition:transform var(--t),box-shadow var(--t)}
.qcard:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}
.qcard::before{content:"";position:absolute;left:0;top:0;width:4px;height:100%;background:linear-gradient(to bottom,var(--orange),#f5a87a)}
.qcard.done::before{background:linear-gradient(to bottom,var(--ok),#4ade80)}
.qhead{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.qcard h4{margin:0;font-size:13px;font-family:ui-monospace,Menlo,monospace}.qcard .qs{font-size:11.5px;color:var(--muted);margin-top:3px}
.qbadge{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:4px 9px;border-radius:20px;background:#fdeae0;color:var(--orange);white-space:nowrap}
.qcard.done .qbadge{background:#dcfce7;color:var(--ok)}
.qcard dl{display:grid;grid-template-columns:auto 1fr;gap:6px 14px;font-size:12.5px;margin:14px 0}
.qcard dt{color:var(--muted)}.qcard dd{text-align:right;font-family:ui-monospace,Menlo,monospace;font-size:11.5px;font-weight:500;margin:0}
.qnote{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:11px 13px;font-size:12.5px;margin-bottom:12px;line-height:1.55}
.qnote span{display:block;font-size:9.5px;text-transform:uppercase;letter-spacing:.07em;color:var(--muted);font-weight:700;margin-bottom:4px}
.decided{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:11px 13px;font-size:12.5px}
.decided .h{color:var(--ok);font-weight:700;display:flex;gap:7px;align-items:center;margin-bottom:5px}
.decided .h svg{width:16px;height:16px}
.dec{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:3px 9px;border-radius:7px;display:inline-block}
.dec.Replace{background:#fdeee7;color:var(--orange)}.dec.Extend{background:var(--soft);color:var(--dark)}.dec.Return{background:#f0eaf2;color:#7d6f86}

/* REPORTS */
.rcards{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px}.rcard{flex:1;min-width:210px;background:#fff;border:1px solid var(--border);border-radius:18px;padding:22px;box-shadow:var(--shadow)}
.rcard h4{margin:0 0 6px;font-size:13.5px;color:var(--dark)}.rcard p{font-size:12.5px;color:var(--muted);margin-bottom:16px;line-height:1.5}

/* TABS */
.tabs{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
.tabs a{padding:9px 16px;border-radius:10px;font-size:13px;font-weight:700;background:#fff;border:1.5px solid var(--border);color:var(--muted);transition:all var(--t)}
.tabs a:hover{border-color:var(--light);color:var(--dark)}
.tabs a.on{background:linear-gradient(135deg,var(--dark),var(--primary));color:#fff;border-color:transparent;box-shadow:0 4px 12px rgba(79,134,213,.3)}

/* LOG BADGES */
.lvl{font-size:10px;font-weight:700;padding:3px 9px;border-radius:7px;letter-spacing:.03em}
.lvl.INFO{background:#dbeafe;color:#1d4ed8}.lvl.WARN{background:#fef3c7;color:#92400e}.lvl.ERROR{background:#fee2e2;color:var(--danger)}
.st{font-size:10px;font-weight:700;padding:3px 9px;border-radius:7px;background:#f1f5f9;color:var(--muted)}
.st.SUCCESS{background:#dcfce7;color:var(--ok)}.st.OTP_SENT{background:#dbeafe;color:#1d4ed8}
.st[class*="FAIL"]{background:#fee2e2;color:var(--danger)}.st.LOGOUT{background:#fef3c7;color:#92400e}

.empty{text-align:center;padding:60px 20px;color:var(--muted)}
.empty svg{width:46px;height:46px;color:var(--light);margin-bottom:14px}
.empty h3{color:var(--ink);margin:0 0 6px}
.appfoot{padding:20px 32px;border-top:1px solid var(--border);text-align:center;color:var(--muted);font-size:12px;font-weight:500;background:#fff}.appfoot .policy{margin-top:8px}
.sync-info{display:flex;align-items:flex-start;gap:11px;background:#fff;border:1px solid var(--border);border-radius:12px;padding:14px 18px;margin-bottom:16px;color:var(--muted);line-height:1.7;font-size:13px}.sync-info svg{width:20px;height:20px;color:var(--primary);flex-shrink:0;margin-top:1px}.sync-info b{color:var(--dark)}

@media(max-width:1100px){.cards{grid-template-columns:repeat(2,1fr)}}
@media(max-width:900px){
  .app{grid-template-columns:1fr}
  .appbar{display:flex;align-items:center;gap:12px;position:sticky;top:0;z-index:45;background:linear-gradient(120deg,#003d52,#062554);color:#fff;padding:12px 16px;box-shadow:var(--shadow)}
  .appbar img{height:24px}.appbar .ab-title{font-weight:700;font-size:15px;margin-inline-start:2px}
  .hamburger{background:rgba(255,255,255,.12);border:0;color:#fff;width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;cursor:pointer}
  .hamburger svg{width:20px;height:20px}
  .sidebar{position:fixed;inset:0 auto 0 0;width:264px;z-index:60;transform:translateX(-100%);transition:transform .26s ease;height:100vh}
  .sidebar.open{transform:none;box-shadow:var(--shadow-lg)}
  .scrim{display:block;position:fixed;inset:0;background:rgba(4,16,32,.5);z-index:50;opacity:0;visibility:hidden;transition:opacity .26s ease}
  .scrim.show{opacity:1;visibility:visible}
  .login-shell{grid-template-columns:1fr}.login-hero{display:none}
  .charts,.grid2,.form-grid{grid-template-columns:1fr}.main{padding:20px 18px}
  .login-panel{padding:38px 28px}
}
@media(max-width:600px){.cards{grid-template-columns:1fr}.main{padding:16px 14px}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.001ms!important;animation-iteration-count:1!important;transition-duration:.001ms!important}html{scroll-behavior:auto}}
</style></head><body>

<a class="skip-link" href="#main">Skip to main content</a>

<?php
/* Reusable notice/toast block (aria-live region) */
function render_notices($error,$success){
    if($error===''&&$success==='') { echo '<div class="notices" aria-live="polite"></div>'; return; }
    echo '<div class="notices" aria-live="polite">';
    if($error!==''){ echo '<div class="notice error" role="alert">'.icon('alert').'<span>'.e($error).'</span><button type="button" class="notice-x" aria-label="Dismiss message" data-dismiss>'.icon('x').'</button></div>'; }
    if($success!==''){ echo '<div class="notice" role="status" data-autodismiss>'.icon('check-circle').'<span>'.e($success).'</span><button type="button" class="notice-x" aria-label="Dismiss message" data-dismiss>'.icon('x').'</button></div>'; }
    echo '</div>';
}
?>

<?php if(!$loggedIn): ?>
<div class="login-page"><div class="login-shell">
  <section class="login-hero">
    <div class="brand"><img src="<?=$LOGO?>" alt="CATRION" onerror="this.style.display='none'"></div>
    <div>
      <h1>Every device,<br>tracked to end-of-life.</h1>
      <p>Synced live from ManageEngine and linked to the Unified Employees Master List.</p>
      <div class="hero-features">
        <div class="hero-feat"><?=icon('sync')?>Live sync from ManageEngine AMER</div>
        <div class="hero-feat"><?=icon('shield')?>IT flags EoL · Segment heads decide</div>
        <div class="hero-feat"><?=icon('audit')?>Full audit trail &amp; CSV export</div>
      </div>
    </div>
    <div class="foot" style="color:rgba(255,255,255,.65);text-align:left">© 2026 CATRION • IT Digital &amp; Transformation</div>
  </section>
  <section class="login-panel" id="main">
    <div class="lbl"><span>Secure Access</span></div>
    <h2><?=$showOtp?'Enter verification code':'Sign in'?></h2>
    <p class="sub"><?=$showOtp?'A 4-digit code was sent to your registered mobile.':'Enter your employee PRN or continue with Microsoft SSO.'?></p>
    <?php render_notices($error,$success); ?>
    <form method="post" novalidate>
      <input type="hidden" name="eol_login" value="1">
      <div class="field"><label for="login_prn">Employee PRN</label>
        <input id="login_prn" name="login_prn" inputmode="numeric" autocomplete="username" pattern="^140\d{5}$" maxlength="8" placeholder="140xxxxx" value="<?=e($_POST['login_prn']??$_SESSION['eol_pending_prn']??'')?>" <?=$showOtp?'':'autofocus'?> required>
        <?php if(!$showOtp):?><div class="hint">Your 8-digit employee number, e.g. 140xxxxx.</div><?php endif;?></div>
      <?php if($showOtp||!empty($_SESSION['eol_pending_prn'])):?>
        <div class="field"><label for="otp">Verification Code</label><input id="otp" class="otp-input" name="otp" inputmode="numeric" autocomplete="one-time-code" pattern="\d{4}" maxlength="4" placeholder="••••" autofocus required></div>
        <button class="btn" type="submit" style="width:100%"><?=icon('check')?>Verify &amp; sign in</button>
      <?php else:?>
        <button class="btn" type="submit" style="width:100%"><?=icon('arrow')?>Send verification code</button>
      <?php endif;?>
    </form>
    <?php if(sso_available()):?>
    <div class="sep">or</div>
    <a class="btn light" href="<?=sso_url()?>" style="width:100%">
      <span class="ms-logo" aria-hidden="true" style="display:grid;grid-template-columns:1fr 1fr;gap:2px;width:16px;height:16px"><i style="background:#f25022"></i><i style="background:#7fba00"></i><i style="background:#00a4ef"></i><i style="background:#ffb900"></i></span>
      Continue with Microsoft SSO</a>
    <?php endif;?>
    <div class="foot">Created by CATRION IT Team<?=$POLICY?></div>
  </section>
</div></div>

<?php else:?>
<?php $initials=implode('',array_filter(array_map(fn($w)=>$w?strtoupper($w[0]):'',explode(' ',$fullName))));$initials=substr($initials,0,2)?:'??'; ?>
<header class="appbar">
  <button class="hamburger" id="navToggle" aria-label="Open navigation menu" aria-controls="sidebar" aria-expanded="false"><?=icon('menu')?></button>
  <img src="<?=$LOGO?>" alt="CATRION" onerror="this.style.display='none'">
  <span class="ab-title"><?=e($pageTitle)?></span>
</header>
<div class="scrim" id="navScrim" hidden></div>
<div class="app">
  <aside class="sidebar" id="sidebar">
    <div class="brand"><img src="<?=$LOGO?>" alt="CATRION" onerror="this.style.display='none'"></div>
    <div class="user-chip">
      <div class="user-avatar" aria-hidden="true"><?=e($initials)?></div>
      <div class="user-info">
        <div class="un"><?=e($fullName)?></div>
        <div class="ur"><?=$role==='admin'?'IT Admin':'Segment Head'?></div>
      </div>
    </div>
    <nav class="nav" aria-label="Primary">
      <?php if($role==='admin'):?>
        <div class="navsec">Administration</div>
        <a class="<?=$screen==='dashboard'?'on':''?>" href="?screen=dashboard" <?=$screen==='dashboard'?'aria-current="page"':''?>><?=icon('dashboard')?>Dashboard</a>
        <a class="<?=$screen==='inventory'?'on':''?>" href="?screen=inventory" <?=$screen==='inventory'?'aria-current="page"':''?>><?=icon('inventory')?>Asset Inventory</a>
        <a class="<?=$screen==='admins'?'on':''?>" href="?screen=admins" <?=$screen==='admins'?'aria-current="page"':''?>><?=icon('admins')?>Admin Users</a>
        <a class="<?=$screen==='segments'?'on':''?>" href="?screen=segments" <?=$screen==='segments'?'aria-current="page"':''?>><?=icon('segments')?>Segment Heads</a>
        <a class="<?=$screen==='reports'?'on':''?>" href="?screen=reports" <?=$screen==='reports'?'aria-current="page"':''?>><?=icon('reports')?>Reports</a>
        <a class="<?=$screen==='audit'?'on':''?>" href="?screen=audit" <?=$screen==='audit'?'aria-current="page"':''?>><?=icon('audit')?>Audit Log</a>
      <?php else:?>
        <div class="navsec">My Area</div>
        <a class="on" href="?screen=queue" aria-current="page"><?=icon('queue')?>Replacement Queue</a>
      <?php endif;?>
    </nav>
    <form method="post" class="signout"><button class="btn light" name="logout" style="width:100%"><?=icon('logout')?>Sign out</button></form>
  </aside>
  <div class="content"><main class="main" id="main">
    <div class="topbar"><div>
      <h1><?=e($pageTitle)?></h1>
      <div class="meta">Signed in as <b><?=e($fullName)?></b> • <?=e($prn)?> • <?=$role==='admin'?'IT Administrator':'Segment Head'?></div>
    </div>
    <?php if($role==='admin'&&$screen==='inventory'):?>
      <form method="post" style="margin:0" data-sync><button class="btn sm" name="sync_amer"><?=icon('sync')?><span>Sync from ManageEngine</span></button></form>
    <?php endif;?>
    </div>
    <?php render_notices($error,$success); ?>

    <?php if($role==='admin'&&$screen==='dashboard'):?>
      <section class="cards">
        <div class="card"><div class="card-top"><span>Total assets</span><span class="card-ic"><?=icon('box')?></span></div><b><?=e($stats['total'])?></b></div>
        <div class="card ok"><div class="card-top"><span>Active</span><span class="card-ic"><?=icon('check-circle')?></span></div><b><?=e($stats['active'])?></b></div>
        <div class="card eol"><div class="card-top"><span>Flagged EoL</span><span class="card-ic"><?=icon('alert')?></span></div><b><?=e($stats['eol'])?></b></div>
        <div class="card warn"><div class="card-top"><span>Over 4 years old</span><span class="card-ic"><?=icon('clock')?></span></div><b><?=e($stats['over4'])?></b></div>
      </section>
      <section class="charts">
        <div class="panel"><h3>EoL flags by segment</h3><div class="cbox"><canvas id="cSeg" role="img" aria-label="Bar chart of End-of-Life flags by segment"></canvas></div></div>
        <div class="panel"><h3>Head decisions on EoL devices</h3><div class="cbox"><canvas id="cDec" role="img" aria-label="Doughnut chart of segment-head decisions on End-of-Life devices"></canvas></div></div>
      </section>

    <?php elseif($role==='admin'&&$screen==='inventory'):?>
      <div class="sync-info"><?=icon('info')?><div>Assets sync <b>directly from ManageEngine</b> (AMER / <?=e($_ENV['AMER_DB_Name']??'SDPnew')?>). Click “Sync from ManageEngine” to pull the latest. EoL flags and head decisions are preserved across syncs.</div></div>
      <form method="get" class="filters"><input type="hidden" name="screen" value="inventory">
        <div class="search-wrap"><?=icon('search')?><label class="sr-only" for="fq">Search assets</label>
          <input id="fq" name="q" placeholder="Search asset, user, login, tag, IP…" value="<?=e($_GET['q']??'')?>"></div>
        <label class="sr-only" for="fdept">Segment</label>
        <select id="fdept" name="dept"><option value="">All segments</option><?php foreach($segments as $s):?><option <?=($_GET['dept']??'')===$s?'selected':''?>><?=e($s)?></option><?php endforeach;?></select>
        <label class="sr-only" for="fscan">Scan status</label>
        <select id="fscan" name="scan"><option value="">Any scan</option><option <?=($_GET['scan']??'')==='SUCCESS'?'selected':''?>>SUCCESS</option><option <?=($_GET['scan']??'')==='FAILED'?'selected':''?>>FAILED</option></select>
        <label class="sr-only" for="feol">Lifecycle state</label>
        <select id="feol" name="eol"><option value="">Any state</option><option <?=($_GET['eol']??'')==='EoL'?'selected':''?>>EoL</option><option <?=($_GET['eol']??'')==='Active'?'selected':''?>>Active</option></select>
        <button class="btn sm" type="submit"><?=icon('search')?>Filter</button></form>
      <div class="table-wrap"><table>
        <caption class="sr-only">Asset inventory synced from ManageEngine</caption>
        <thead><tr><th scope="col">Asset / Model</th><th scope="col">User</th><th scope="col">Last Login</th><th scope="col">Segment</th><th scope="col">Scan</th><th scope="col">Service Tag</th><th scope="col">IP</th><th scope="col">Warranty</th><th scope="col">4+ yrs</th><th scope="col">EoL</th></tr></thead>
        <tbody><?php if(!$assets):?><tr><td colspan="10"><div class="empty"><?=icon('inventory')?><h3>No assets yet</h3><p>Click “Sync from ManageEngine” to pull the latest inventory.</p></div></td></tr><?php endif; foreach($assets as $a):?>
          <tr class="<?=(int)$a['is_eol']?'eol':''?>">
            <td><div class="tag" style="font-weight:700"><?=e($a['asset_name'])?></div><div style="font-size:11px;color:var(--muted)"><?=e(trim($a['manufacturer'].' '.$a['model']))?></div></td>
            <td><?=e($a['asset_user'])?></td><td class="tag"><?=e($a['last_login_user'])?></td>
            <td><span class="dept"><?=e($a['department'])?></span></td>
            <td><span class="pill <?=$a['last_scan_status']==='SUCCESS'?'ok':'fail'?>"><span class="d"></span><?=e($a['last_scan_status'])?></span></td>
            <td class="tag"><?=e($a['service_tag'])?></td><td class="tag"><?=e(strtok($a['ip_addresses']??'',','))?></td>
            <td><form method="post" style="margin:0"><input type="hidden" name="save_warranty" value="1"><input type="hidden" name="asset_id" value="<?=(int)$a['id']?>"><label class="sr-only" for="warr<?=(int)$a['id']?>">Warranty expiry for <?=e($a['asset_name'])?></label><input id="warr<?=(int)$a['id']?>" type="date" name="warranty_expiry" class="dateedit" value="<?=e($a['warranty_expiry'])?>" onchange="this.form.submit()"></form></td>
            <td><span class="dec <?=(int)$a['over_four_years']?'Replace':''?>" style="<?=(int)$a['over_four_years']?'':'color:var(--muted)'?>"><?=(int)$a['over_four_years']?'YES':'no'?></span></td>
            <td><form method="post" style="margin:0"><input type="hidden" name="toggle_eol" value="1"><input type="hidden" name="asset_id" value="<?=(int)$a['id']?>"><button type="submit" role="switch" aria-checked="<?=(int)$a['is_eol']?'true':'false'?>" aria-label="<?=(int)$a['is_eol']?'Remove End-of-Life flag from':'Flag End-of-Life:'?> <?=e($a['asset_name'])?>" class="switch <?=(int)$a['is_eol']?'on':''?>"></button></form></td>
          </tr><?php endforeach;?></tbody>
      </table></div>

    <?php elseif($role==='admin'&&$screen==='admins'):?>
      <section class="grid2">
        <div class="panel"><h3>Add / update admin</h3>
          <form method="post" class="form-grid"><input type="hidden" name="save_admin" value="1">
            <div class="full"><label for="admin_prn">PRN (master list)</label><input id="admin_prn" name="admin_prn" inputmode="numeric" maxlength="8" placeholder="140xxxxx" required></div>
            <div class="full"><label for="admin_role">Role · scope</label><select id="admin_role" name="admin_role"><option>IT Admin · All segments</option><option>IT Admin · JED sites only</option><option>Super Admin · All segments</option><option>Read-only · Reports</option></select></div>
            <div class="full"><button class="btn" type="submit"><?=icon('check')?>Save admin</button></div></form></div>
        <div class="panel"><h3>Administrators</h3>
          <div class="table-wrap"><table style="min-width:auto">
            <thead><tr><th scope="col">PRN</th><th scope="col">Name</th><th scope="col">Role</th><th scope="col">Scope</th><th scope="col">Last Login</th><th scope="col">Status</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody><?php foreach($admins as $a):?><tr>
              <td class="tag"><?=e($a['prn'])?></td><td><?=e($a['FULL_NAME']??'—')?></td><td><?=e($a['role'])?></td><td><span class="dept"><?=e($a['scope'])?></span></td>
              <td class="tag"><?=e($a['last_login']??'—')?></td><td><span class="pill <?=(int)$a['is_active']?'ok':'fail'?>"><span class="d"></span><?=(int)$a['is_active']?'Active':'Suspended'?></span></td>
              <td style="white-space:nowrap"><form method="post" style="display:inline;margin:0"><input type="hidden" name="admin_prn" value="<?=e($a['prn'])?>"><button class="linkbtn" name="admin_action" value="<?=(int)$a['is_active']?'suspend':'activate'?>"><?=(int)$a['is_active']?'Suspend':'Activate'?></button></form>
                <form method="post" style="display:inline;margin:0"><input type="hidden" name="admin_prn" value="<?=e($a['prn'])?>"><button class="linkbtn" style="color:var(--danger)" name="admin_action" value="remove" onclick="return confirm('Remove this admin?')">Remove</button></form></td>
            </tr><?php endforeach; if(!$admins):?><tr><td colspan="7" style="color:var(--muted)">No administrators yet.</td></tr><?php endif;?></tbody></table></div></div>
      </section>

    <?php elseif($role==='admin'&&$screen==='segments'):?>
      <section class="grid2">
        <div class="panel"><h3>Map segment to head</h3>
          <form method="post" class="form-grid"><input type="hidden" name="save_head" value="1">
            <div class="full"><label for="department">Department / segment</label><input id="department" name="department" placeholder="e.g. Airports Lounges - JED" required></div>
            <div class="full"><label for="head_prn">Head PRN</label><input id="head_prn" name="head_prn" inputmode="numeric" maxlength="8" placeholder="140xxxxx" required></div>
            <div class="full"><button class="btn" type="submit"><?=icon('check')?>Save mapping</button></div></form></div>
        <div class="panel"><h3>Segment heads</h3>
          <div class="table-wrap"><table style="min-width:auto"><thead><tr><th scope="col">Segment</th><th scope="col">Head PRN</th><th scope="col">Name</th><th scope="col">Status</th></tr></thead>
            <tbody><?php foreach($heads as $h):?><tr><td><span class="dept"><?=e($h['department'])?></span></td><td class="tag"><?=e($h['head_prn'])?></td><td><?=e($h['FULL_NAME']??'—')?></td><td><span class="pill <?=(int)$h['is_active']?'ok':'fail'?>"><span class="d"></span><?=(int)$h['is_active']?'Active':'Inactive'?></span></td></tr><?php endforeach; if(!$heads):?><tr><td colspan="4" style="color:var(--muted)">No mappings yet.</td></tr><?php endif;?></tbody></table></div></div>
      </section>

    <?php elseif($role==='admin'&&$screen==='reports'):?>
      <div class="filters"><a class="btn sm" href="?export=assets&kind=all"><?=icon('download')?>Full inventory CSV</a>
        <a class="btn sm" href="?export=assets&kind=eol"><?=icon('download')?>End-of-Life CSV</a>
        <a class="btn sm light" href="?export=assets&kind=over4"><?=icon('download')?>Over-4-years CSV</a></div>
      <div class="panel"><h3>EoL decisions by segment</h3>
        <div class="table-wrap"><table style="min-width:auto"><thead><tr><th scope="col">Segment</th><th scope="col">Total</th><th scope="col">EoL</th><th scope="col">Replace</th><th scope="col">Extend</th><th scope="col">Return</th><th scope="col">Pending</th></tr></thead>
          <tbody><?php $rs=$conn->query("SELECT department,COUNT(*) total,SUM(is_eol) eol,SUM(decision='Replace') rep,SUM(decision='Extend') ext,SUM(decision='Return') ret,SUM(is_eol=1 AND decision IS NULL) pend FROM eol_assets GROUP BY department ORDER BY department"); while($r=$rs->fetch_assoc()):?>
            <tr><td><span class="dept"><?=e($r['department'])?></span></td><td class="tag"><?=(int)$r['total']?></td><td class="tag" style="color:var(--orange);font-weight:700"><?=(int)$r['eol']?></td><td class="tag"><?=(int)$r['rep']?></td><td class="tag"><?=(int)$r['ext']?></td><td class="tag"><?=(int)$r['ret']?></td><td class="tag"><?=(int)$r['pend']?></td></tr>
          <?php endwhile;?></tbody></table></div></div>

    <?php elseif($role==='admin'&&$screen==='audit'):?>
      <div class="tabs" role="tablist">
        <a class="<?=$logtab==='audit'?'on':''?>" href="?screen=audit&logtab=audit">Audit events</a>
        <a class="<?=$logtab==='logins'?'on':''?>" href="?screen=audit&logtab=logins">Login log</a>
        <a class="<?=$logtab==='sync'?'on':''?>" href="?screen=audit&logtab=sync">Sync runs</a>
        <a class="<?=$logtab==='app'?'on':''?>" href="?screen=audit&logtab=app">System log</a></div>
      <div class="table-wrap">
      <?php if($logtab==='audit'):?>
        <table><thead><tr><th scope="col">When</th><th scope="col">Actor</th><th scope="col">Action</th><th scope="col">Asset</th><th scope="col">Detail</th><th scope="col">IP</th></tr></thead><tbody>
        <?php foreach($auditRows as $r):?><tr><td class="tag"><?=e($r['created_at'])?></td><td><?=e($r['actor_name']?:$r['actor_prn'])?></td><td><span class="dec"><?=e($r['action'])?></span></td><td class="tag"><?=e($r['asset_tag'])?></td><td><?=e($r['detail'])?></td><td class="tag"><?=e($r['ip_address'])?></td></tr><?php endforeach; if(!$auditRows):?><tr><td colspan="6" style="color:var(--muted)">No events yet.</td></tr><?php endif;?></tbody></table>
      <?php elseif($logtab==='logins'):?>
        <table><thead><tr><th scope="col">When</th><th scope="col">PRN</th><th scope="col">Name</th><th scope="col">Status</th><th scope="col">IP</th></tr></thead><tbody>
        <?php foreach($loginRows as $r):?><tr><td class="tag"><?=e($r['created_at'])?></td><td class="tag"><?=e($r['prn'])?></td><td><?=e($r['full_name'])?></td><td><span class="st <?=e($r['status'])?>"><?=e($r['status'])?></span></td><td class="tag"><?=e($r['ip_address'])?></td></tr><?php endforeach; if(!$loginRows):?><tr><td colspan="5" style="color:var(--muted)">No login events.</td></tr><?php endif;?></tbody></table>
      <?php elseif($logtab==='sync'):?>
        <table><thead><tr><th scope="col">When</th><th scope="col">By</th><th scope="col">Source</th><th scope="col">Processed</th><th scope="col">New</th><th scope="col">Updated</th><th scope="col">Skipped</th><th scope="col">Status</th></tr></thead><tbody>
        <?php foreach($syncRows as $r):?><tr><td class="tag"><?=e($r['created_at'])?></td><td><?=e($r['run_by_name']?:$r['run_by_prn'])?></td><td><?=e($r['source'])?></td><td class="tag"><?=(int)$r['processed']?></td><td class="tag"><?=(int)$r['inserted']?></td><td class="tag"><?=(int)$r['updated']?></td><td class="tag"><?=(int)$r['skipped']?></td><td><span class="st <?=$r['status']==='OK'?'SUCCESS':'FAIL'?>"><?=e($r['status'])?></span></td></tr><?php endforeach; if(!$syncRows):?><tr><td colspan="8" style="color:var(--muted)">No sync runs.</td></tr><?php endif;?></tbody></table>
      <?php else:?>
        <table><thead><tr><th scope="col">When</th><th scope="col">Level</th><th scope="col">Context</th><th scope="col">Message</th><th scope="col">IP</th></tr></thead><tbody>
        <?php foreach($appRows as $r):?><tr><td class="tag"><?=e($r['created_at'])?></td><td><span class="lvl <?=e($r['level'])?>"><?=e($r['level'])?></span></td><td><?=e($r['context'])?></td><td style="max-width:420px"><?=e($r['message'])?></td><td class="tag"><?=e($r['ip_address'])?></td></tr><?php endforeach; if(!$appRows):?><tr><td colspan="5" style="color:var(--muted)">No system logs.</td></tr><?php endif;?></tbody></table>
      <?php endif;?>
      </div>

    <?php elseif($role==='head'&&$screen==='queue'):?>
      <?php if(!$assets):?><div class="panel empty"><?=icon('inbox-empty')?><h3>Nothing to action</h3><p>No devices in your segment(s) are flagged End-of-Life.</p></div>
      <?php else:?><div class="queue"><?php foreach($assets as $a): $done=!empty($a['decision']);?>
        <div class="qcard <?=$done?'done':''?>">
          <div class="qhead"><div><h4><?=e($a['asset_name'])?></h4><div class="qs"><?=e(trim($a['manufacturer'].' '.$a['model']))?> · <?=e($a['asset_user'])?></div></div>
            <span class="qbadge"><?=$done?'Decided':'Action needed'?></span></div>
          <dl><dt>Service tag</dt><dd><?=e($a['service_tag'])?></dd><dt>Last login</dt><dd><?=e($a['last_login_user'])?></dd><dt>Warranty</dt><dd><?=e($a['warranty_expiry']?:'—')?></dd><dt>Over 4 yrs</dt><dd><?=(int)$a['over_four_years']?'Yes':'No'?></dd></dl>
          <div class="qnote"><span>Note from IT</span><?=e($a['eol_note'])?></div>
          <?php if($done):?><div class="decided"><div class="h"><?=icon('check-circle')?>Decision recorded <span class="dec <?=e($a['decision'])?>"><?=e($a['decision'])?></span></div><?=$a['decision_note']?e($a['decision_note']).'<br>':''?><span style="color:var(--muted);font-size:11px">by <?=e($a['decided_by_name'])?> · <?=e($a['decided_at'])?></span></div>
          <?php else:?><form method="post"><input type="hidden" name="submit_decision" value="1"><input type="hidden" name="asset_id" value="<?=(int)$a['id']?>">
            <div class="field" style="margin-bottom:10px"><label class="sr-only" for="dec<?=(int)$a['id']?>">Decision for <?=e($a['asset_name'])?></label><select id="dec<?=(int)$a['id']?>" name="decision" required><option value="">Select action…</option><option>Replace</option><option>Extend</option><option>Return</option></select></div>
            <label class="sr-only" for="note<?=(int)$a['id']?>">Decision notes</label><textarea id="note<?=(int)$a['id']?>" name="decision_note" placeholder="Notes (optional)…"></textarea>
            <button class="btn" type="submit" style="width:100%;margin-top:10px"><?=icon('check')?>Submit decision</button></form><?php endif;?>
        </div><?php endforeach;?></div><?php endif;?>
    <?php endif;?>
  </main>
  <footer class="appfoot"><div>Created by CATRION IT Team</div><?=$POLICY?></footer>
  </div>
</div>

<?php if($role==='admin'&&$screen==='dashboard'):?>
<script>
const seg=<?php $l=[];$v=[];$rs=$conn->query("SELECT department,SUM(is_eol) e FROM eol_assets GROUP BY department ORDER BY e DESC");if($rs)while($r=$rs->fetch_assoc()){$l[]=$r['department'];$v[]=(int)$r['e'];}echo json_encode(['labels'=>$l,'data'=>$v]);?>;
const dec=<?php $rs=$conn->query("SELECT SUM(decision='Replace') r,SUM(decision='Extend') e,SUM(decision='Return') t,SUM(is_eol=1 AND decision IS NULL) p FROM eol_assets");$r=$rs?$rs->fetch_assoc():[];echo json_encode([(int)($r['r']??0),(int)($r['e']??0),(int)($r['t']??0),(int)($r['p']??0)]);?>;
if(window.Chart){
 Chart.defaults.font.family="'Gotham',system-ui,'Segoe UI',sans-serif";Chart.defaults.color='#586b82';
 new Chart(cSeg,{type:'bar',data:{labels:seg.labels,datasets:[{data:seg.data,backgroundColor:'#D45B25',borderRadius:7,maxBarThickness:46}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false},tooltip:{backgroundColor:'#04193e',padding:10,cornerRadius:8}},scales:{x:{ticks:{font:{size:9},maxRotation:42,minRotation:42},grid:{display:false}},y:{beginAtZero:true,ticks:{precision:0},grid:{color:'#eef4fb'}}}}});
 new Chart(cDec,{type:'doughnut',data:{labels:['Replace','Extend','Return','Pending'],datasets:[{data:dec,backgroundColor:['#D45B25','#5A92DB','#A898AF','#ACC8ED'],borderColor:'#fff',borderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,cutout:'62%',plugins:{legend:{position:'bottom',labels:{usePointStyle:true,padding:16,font:{size:11}}},tooltip:{backgroundColor:'#04193e',padding:10,cornerRadius:8}}}});
}
</script>
<?php endif;?>

<script>
/* ---------- UI behaviours (presentation only) ---------- */
(function(){
  // Mobile off-canvas navigation
  var tgl=document.getElementById('navToggle'),sb=document.getElementById('sidebar'),sc=document.getElementById('navScrim');
  function openNav(o){ if(!sb||!sc)return; sb.classList.toggle('open',o); sc.hidden=!o; requestAnimationFrame(function(){sc.classList.toggle('show',o);}); if(tgl)tgl.setAttribute('aria-expanded',o?'true':'false'); }
  if(tgl)tgl.addEventListener('click',function(){openNav(!sb.classList.contains('open'));});
  if(sc)sc.addEventListener('click',function(){openNav(false);});
  document.addEventListener('keydown',function(e){if(e.key==='Escape')openNav(false);});

  // Dismiss + auto-dismiss notices
  document.querySelectorAll('[data-dismiss]').forEach(function(b){b.addEventListener('click',function(){var n=b.closest('.notice'); if(n){n.style.transition='opacity .2s,transform .2s';n.style.opacity='0';n.style.transform='translateY(-6px)';setTimeout(function(){n.remove();},200);}});});
  document.querySelectorAll('[data-autodismiss]').forEach(function(n){setTimeout(function(){n.style.transition='opacity .4s';n.style.opacity='0';setTimeout(function(){n.remove();},400);},6000);});

  // Long-running sync: show spinner + disable so users don't double-submit
  document.querySelectorAll('form[data-sync]').forEach(function(f){f.addEventListener('submit',function(){var b=f.querySelector('button');if(!b)return;b.classList.add('loading');var t=b.querySelector('span');if(t)t.textContent='Syncing ManageEngine…';var ic=b.querySelector('svg');if(ic){var s=document.createElement('span');s.className='spinner';ic.replaceWith(s);}});});
})();
</script>
<?php endif;?>
</body></html>
