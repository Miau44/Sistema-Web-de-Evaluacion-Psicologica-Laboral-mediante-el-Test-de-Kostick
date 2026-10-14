<?php
require_once __DIR__ . '/../includes/session.php';
app_start_session();

require __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método no permitido.');
}

if (!csrf_request_is_valid()) {
    http_response_code(400);
    exit('La solicitud expiró o no es válida.');
}

admin_logout_session();

header('Location: admin_login.php?logout=1');
exit;

