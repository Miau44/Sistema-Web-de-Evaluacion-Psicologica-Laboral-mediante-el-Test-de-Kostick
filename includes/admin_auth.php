<?php
declare(strict_types=1);

function is_admin_authenticated(): bool
{
    return !empty($_SESSION['admin_authenticated']) && !empty($_SESSION['admin_user_id']);
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

