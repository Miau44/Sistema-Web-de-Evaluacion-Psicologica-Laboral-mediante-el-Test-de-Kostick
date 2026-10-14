<?php
session_start();

require __DIR__ . '/../includes/admin_auth.php';

admin_logout_session();

header('Location: admin_login.php?logout=1');
exit;

