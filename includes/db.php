<?php
declare(strict_types=1);

/*
 * MySQL/MariaDB connection (Laragon local environment).
 * You can override these values using environment variables.
 */
$dbHost = getenv('DB_HOST') ?: '127.0.0.1';
$dbPort = getenv('DB_PORT') ?: '3306';
$dbName = getenv('DB_NAME') ?: 'kostick_db';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

if (!defined('APP_TIMEZONE')) {
    define('APP_TIMEZONE', getenv('APP_TIMEZONE') ?: 'America/La_Paz');
}

date_default_timezone_set(APP_TIMEZONE);

/*
 * Cloudflare Turnstile (free CAPTCHA).
 * Set your real keys from https://dash.cloudflare.com/
 */
if (!defined('TURNSTILE_SITE_KEY')) {
    define('TURNSTILE_SITE_KEY', getenv('TURNSTILE_SITE_KEY') ?: '');
}
if (!defined('TURNSTILE_SECRET_KEY')) {
    define('TURNSTILE_SECRET_KEY', getenv('TURNSTILE_SECRET_KEY') ?: '');
}

try {
    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
    $conn = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $conn->exec("SET time_zone = '+00:00'");
} catch (PDOException $e) {
    error_log('[KOSTICK] Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('No se pudo conectar con la base de datos. Inténtelo más tarde.');
}
?>
