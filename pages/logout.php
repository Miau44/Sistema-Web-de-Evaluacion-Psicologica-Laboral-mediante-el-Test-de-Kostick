<?php
require_once __DIR__ . '/../includes/session.php';
app_start_session();

require __DIR__ . '/../includes/admin_auth.php';

admin_logout_session();

header('Location: admin_login.php?logout=1');
exit;

