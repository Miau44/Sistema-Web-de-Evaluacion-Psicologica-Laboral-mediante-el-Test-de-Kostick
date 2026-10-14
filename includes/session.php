<?php
declare(strict_types=1);

function app_start_session(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cache-Control: no-store, private');

    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    $cookie = session_get_cookie_params();
    $secureCookieOverride = filter_var(
        getenv('SESSION_COOKIE_SECURE') ?: '0',
        FILTER_VALIDATE_BOOLEAN
    );
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookie['path'],
        'domain' => $cookie['domain'],
        'secure' => $secureCookieOverride
            || (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}
