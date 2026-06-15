<?php
/**
 * _session.php — shared session bootstrap for the WC2026 app.
 * Starts the session with a 48-hour lifetime so logins persist for two days:
 *   - cookie lifetime 48h (and refreshed on each visit = sliding window)
 *   - server-side gc_maxlifetime raised to 48h so the session data isn't reaped
 * Safe to include from anywhere (guards against an already-started session).
 */

if (session_status() === PHP_SESSION_NONE) {
    if (!defined('WC_SESSION_TTL')) define('WC_SESSION_TTL', 48 * 60 * 60); // 48 hours

    // Keep the server-side session data alive for the full window.
    @ini_set('session.gc_maxlifetime', (string) WC_SESSION_TTL);

    session_name('WC2026SESSID');

    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params([
            'lifetime' => WC_SESSION_TTL,
            'path'     => '/',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } else {
        session_set_cookie_params(WC_SESSION_TTL, '/', '', true, true);
    }

    session_start();

    // Sliding expiry: refresh the cookie on activity so the 48h is measured from
    // the last visit, not only from login.
    if (!headers_sent() && isset($_COOKIE[session_name()])) {
        if (PHP_VERSION_ID >= 70300) {
            setcookie(session_name(), session_id(), [
                'expires'  => time() + WC_SESSION_TTL,
                'path'     => '/',
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            setcookie(session_name(), session_id(), time() + WC_SESSION_TTL, '/', '', true, true);
        }
    }
}
