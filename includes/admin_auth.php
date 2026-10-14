<?php
declare(strict_types=1);

function is_admin_authenticated(): bool
{
    if (empty($_SESSION['admin_authenticated']) || empty($_SESSION['admin_user_id'])) {
        return false;
    }

    $loggedAt = filter_var($_SESSION['admin_logged_at'] ?? null, FILTER_VALIDATE_INT);
    $sessionLifetime = 8 * 60 * 60;
    if ($loggedAt === false || $loggedAt > time() || time() - $loggedAt >= $sessionLifetime) {
        admin_logout_session();
        return false;
    }

    return true;
}

function require_admin_auth(): void
{
    if (!is_admin_authenticated()) {
        header('Location: admin_login.php');
        exit;
    }
}

function admin_logout_session(): void
{
    unset(
        $_SESSION['admin_authenticated'],
        $_SESSION['admin_user_id'],
        $_SESSION['admin_username'],
        $_SESSION['admin_logged_at']
    );

    session_regenerate_id(true);
}

