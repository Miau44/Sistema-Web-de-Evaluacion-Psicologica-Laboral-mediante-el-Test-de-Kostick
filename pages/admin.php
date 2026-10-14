<?php
require_once __DIR__ . '/../includes/session.php';
app_start_session();
header('Content-Type: text/html; charset=UTF-8');

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/access_codes.php';
require __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require __DIR__ . '/../includes/positions.php';
require __DIR__ . '/../includes/admin_users.php';
require __DIR__ . '/../includes/xlsx_export.php';
require_once __DIR__ . '/../includes/user_identity.php';

require_admin_auth();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_request_is_valid()) {
    http_response_code(400);
    exit('La solicitud expiró o no es válida. Recargue la página e inténtelo nuevamente.');
}

ensure_access_codes_schema($conn);
ensure_positions_schema($conn);
ensure_user_identity_schema($conn);

$currentAdminId = (int)($_SESSION['admin_user_id'] ?? 0);
$adminUsername = (string)($_SESSION['admin_username'] ?? 'admin');

$successMessages = [];
$errorMessages = [];
$positionFormMode = 'create';
$positionFormAction = 'create_position';
$positionFormTitle = 'Crear puesto';
$positionFormButton = 'Guardar puesto';
$positionFormId = 0;
$positionFormName = '';
$positionFormEnabled = true;
$defaultCodeStart = new DateTimeImmutable('now', access_code_timezone());
$defaultCodeEnd = $defaultCodeStart->modify('+1 hour');
$codeStartDateInput = $defaultCodeStart->format('Y-m-d');
$codeStartTimeInput = $defaultCodeStart->format('H:i');
$codeEndDateInput = $defaultCodeEnd->format('Y-m-d');
$codeEndTimeInput = $defaultCodeEnd->format('H:i');
$accessCodeTimezoneLabel = access_code_timezone_name();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'generate_code') {
        $codeStartDateInput = trim((string)($_POST['fecha_inicio_date'] ?? ''));
        $codeStartTimeInput = trim((string)($_POST['fecha_inicio_time'] ?? ''));
        $codeEndDateInput = trim((string)($_POST['fecha_fin_date'] ?? ''));
        $codeEndTimeInput = trim((string)($_POST['fecha_fin_time'] ?? ''));

        try {
            $validatedWindow = validate_access_code_window_inputs(
                $codeStartDateInput,
                $codeStartTimeInput,
                $codeEndDateInput,
                $codeEndTimeInput
            );
            $generated = create_access_code(
                $conn,
                $validatedWindow['fecha_inicio'],
                $validatedWindow['fecha_fin']
            );
            $successMessages[] = sprintf(
                'Codigo generado: %s (vigente desde %s hasta %s)',
                $generated['code'],
                format_access_code_datetime($generated['fecha_inicio']),
                format_access_code_datetime($generated['fecha_fin'])
            );

            $defaultCodeStart = new DateTimeImmutable('now', access_code_timezone());
            $defaultCodeEnd = $defaultCodeStart->modify('+1 hour');
            $codeStartDateInput = $defaultCodeStart->format('Y-m-d');
            $codeStartTimeInput = $defaultCodeStart->format('H:i');
            $codeEndDateInput = $defaultCodeEnd->format('Y-m-d');
            $codeEndTimeInput = $defaultCodeEnd->format('H:i');
        } catch (Throwable $e) {
            $errorMessages[] = $e instanceof InvalidArgumentException
                ? $e->getMessage()
                : 'No se pudo generar el codigo de acceso.';
            error_log('[KOSTICK] Error al generar codigo de acceso: ' . $e->getMessage());
        }
    } elseif ($action === 'create_admin') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        $usernameSanitized = preg_replace('/[^a-zA-Z0-9._-]/', '', $username) ?? '';

        if ($username === '' || $password === '' || $confirmPassword === '') {
            $errorMessages[] = 'Todos los campos de nuevo administrador son obligatorios.';
        } elseif ($usernameSanitized !== $username) {
            $errorMessages[] = 'El username contiene caracteres no permitidos.';
        } elseif (strlen($password) < 8) {
            $errorMessages[] = 'La contrasena debe tener al menos 8 caracteres.';
        } elseif ($password !== $confirmPassword) {
            $errorMessages[] = 'Password y confirm_password no coinciden.';
        } else {
            try {
                $existsStmt = $conn->prepare(
                    "SELECT id FROM admin_users WHERE LOWER(username) = LOWER(:username) LIMIT 1"
                );
                $existsStmt->execute([':username' => $username]);
                $exists = $existsStmt->fetch();

                if ($exists) {
                    $errorMessages[] = 'El username ya existe.';
                } else {
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                    $insertStmt = $conn->prepare(
                        "INSERT INTO admin_users (username, password_hash, is_active)
                         VALUES (:username, :password_hash, TRUE)"
                    );
                    $insertStmt->execute([
                        ':username' => $username,
                        ':password_hash' => $passwordHash,
                    ]);

                    $successMessages[] = "Administrador '{$username}' creado correctamente.";
                }
            } catch (Throwable $e) {
                $errorMessages[] = 'No se pudo crear el administrador.';
                error_log('[KOSTICK] Error al crear admin: ' . $e->getMessage());
            }
        }
    } elseif ($action === 'delete_admin') {
        $adminIdToDelete = filter_input(
            INPUT_POST,
            'admin_id',
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if (!is_int($adminIdToDelete) || $adminIdToDelete < 1) {
            $errorMessages[] = 'ID de administrador invalido.';
        } elseif ($adminIdToDelete === $currentAdminId) {
            $errorMessages[] = 'No puedes eliminar el administrador que tiene la sesion activa.';
        } else {
            try {
                $findStmt = $conn->prepare("SELECT id, username FROM admin_users WHERE id = :id LIMIT 1");
                $findStmt->execute([':id' => $adminIdToDelete]);
                $adminRow = $findStmt->fetch();

                if (!$adminRow) {
                    $errorMessages[] = 'El administrador no existe.';
                } else {
                    $deleteStmt = $conn->prepare("DELETE FROM admin_users WHERE id = :id");
                    $deleteStmt->execute([':id' => $adminIdToDelete]);
                    $successMessages[] = "Administrador '{$adminRow['username']}' eliminado.";
                }
            } catch (Throwable $e) {
                $errorMessages[] = 'No se pudo eliminar el administrador.';
                error_log('[KOSTICK] Error al eliminar admin: ' . $e->getMessage());
            }
        }
    } elseif ($action === 'create_position') {
        $positionNameInput = (string)($_POST['position_name'] ?? '');
        $positionEnabledInput = (string)($_POST['position_enabled'] ?? '1');

        $positionFormMode = 'create';
        $positionFormAction = 'create_position';
        $positionFormTitle = 'Crear puesto';
        $positionFormButton = 'Guardar puesto';
        $positionFormName = $positionNameInput;
        $positionFormEnabled = $positionEnabledInput !== '0';

        try {
            $createdPosition = create_position($conn, $positionNameInput, $positionFormEnabled);
            $successMessages[] = "Puesto '{$createdPosition['name']}' creado correctamente.";
            $positionFormName = '';
            $positionFormEnabled = true;
        } catch (Throwable $e) {
            $errorMessages[] = $e instanceof InvalidArgumentException
                ? $e->getMessage()
                : 'No se pudo crear el puesto.';
            error_log('[KOSTICK] Error al crear puesto: ' . $e->getMessage());
        }
    } elseif ($action === 'update_position') {
        $positionId = filter_input(
            INPUT_POST,
            'position_id',
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $positionNameInput = (string)($_POST['position_name'] ?? '');
        $positionEnabledInput = (string)($_POST['position_enabled'] ?? '1');

        $positionFormMode = 'edit';
        $positionFormAction = 'update_position';
        $positionFormTitle = 'Editar puesto';
        $positionFormButton = 'Actualizar puesto';
        $positionFormId = is_int($positionId) ? $positionId : 0;
        $positionFormName = $positionNameInput;
        $positionFormEnabled = $positionEnabledInput !== '0';

        if (!is_int($positionId) || $positionId < 1) {
            $errorMessages[] = 'ID de puesto invalido.';
        } else {
            try {
                $updatedPosition = update_position($conn, $positionId, $positionNameInput, $positionFormEnabled);
                $successMessages[] = "Puesto '{$updatedPosition['name']}' actualizado correctamente.";
                $positionFormMode = 'create';
                $positionFormAction = 'create_position';
                $positionFormTitle = 'Crear puesto';
                $positionFormButton = 'Guardar puesto';
                $positionFormId = 0;
                $positionFormName = '';
                $positionFormEnabled = true;
            } catch (Throwable $e) {
                $errorMessages[] = $e instanceof InvalidArgumentException || $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'No se pudo actualizar el puesto.';
                error_log('[KOSTICK] Error al actualizar puesto: ' . $e->getMessage());
            }
        }
    } elseif ($action === 'toggle_position') {
        $positionId = filter_input(
            INPUT_POST,
            'position_id',
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $nextState = (string)($_POST['next_state'] ?? '');

        if (!is_int($positionId) || $positionId < 1 || !in_array($nextState, ['enable', 'disable'], true)) {
            $errorMessages[] = 'Solicitud de cambio de estado invalida.';
        } else {
            try {
                $enabled = $nextState === 'enable';
                $updatedPosition = set_position_enabled($conn, $positionId, $enabled);
                $successMessages[] = $enabled
                    ? "Puesto '{$updatedPosition['name']}' habilitado."
                    : "Puesto '{$updatedPosition['name']}' deshabilitado.";
            } catch (Throwable $e) {
                $errorMessages[] = $e instanceof InvalidArgumentException || $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'No se pudo actualizar el estado del puesto.';
                error_log('[KOSTICK] Error al cambiar estado del puesto: ' . $e->getMessage());
            }
        }
    }
}

$activeCode = get_active_access_code($conn);

$adminsStmt = $conn->query(
    "SELECT id, username, created_at
     FROM admin_users
     ORDER BY id DESC"
);
$admins = $adminsStmt->fetchAll();

$editPositionId = filter_input(
    INPUT_GET,
    'edit_position',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);

if (is_int($editPositionId) && $editPositionId > 0 && $positionFormMode === 'create') {
    try {
        $editingPosition = get_position_by_id($conn, $editPositionId);

        if ($editingPosition) {
            $positionFormMode = 'edit';
            $positionFormAction = 'update_position';
            $positionFormTitle = 'Editar puesto';
            $positionFormButton = 'Actualizar puesto';
            $positionFormId = (int)$editingPosition['id'];
            $positionFormName = (string)$editingPosition['name'];
            $positionFormEnabled = (bool)$editingPosition['is_enabled'];
        } else {
            $errorMessages[] = 'El puesto solicitado no existe.';
        }
    } catch (Throwable $e) {
        $errorMessages[] = 'No se pudo cargar el puesto solicitado.';
        error_log('[KOSTICK] Error al cargar puesto para edicion: ' . $e->getMessage());
    }
}

$positions = get_all_positions($conn);
$enabledPositionsCount = 0;
foreach ($positions as $positionRow) {
    if (!empty($positionRow['is_enabled'])) {
        $enabledPositionsCount++;
    }
}

$filters = get_admin_user_listing_filters($_GET);
$search = $filters['search'];
$filter_date = $filters['filter_date'];
$filter_result = $filters['filter_result'];
$filter_status = $filters['filter_status'];
$sort_by = $filters['sort_by'];
$sort_order = $filters['sort_order'];
$page = $filters['page'];
$per_page = $filters['per_page'];
$start_date = $filters['start_date'];
$end_date = $filters['end_date'];

$usersPageData = get_admin_user_listing_page($conn, $filters);
$users = $usersPageData['users'];
$allUsersForExport = $usersPageData['all_users'];
$total = $usersPageData['total'];
$total_pages = $usersPageData['total_pages'];
$page = $usersPageData['page'];

if ($filters['export'] === 'users_excel') {
    try {
        $exportHeaders = get_admin_user_export_headers();
        $exportRows = build_admin_user_export_rows($allUsersForExport);
        $fileName = 'KOSTICK_USUARIOS_' . date('Ymd_His') . '.xlsx';

        output_xlsx_download($fileName, 'USUARIOS REGISTRADOS', $exportHeaders, $exportRows);
    } catch (Throwable $e) {
        $errorMessages[] = 'No se pudo exportar el archivo Excel.';
        error_log('[KOSTICK] Error al exportar usuarios a Excel: ' . $e->getMessage());
    }
}

$baseQueryParams = $_GET;
unset($baseQueryParams['export']);
$exportUsersQuery = http_build_query(array_merge($baseQueryParams, ['export' => 'users_excel']));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PANEL DE ADMINISTRACION</title>
    <style>
        :root {
            --bg: #f3f7ff;
            --card: #ffffff;
            --ink: #10213d;
            --muted: #5f6b7c;
            --line: #dbe4f1;
            --primary: #1e4fd0;
            --primary-strong: #163ca3;
            --danger: #b42318;
            --danger-bg: #ffe8e6;
            --ok: #155724;
            --ok-bg: #e9f8ee;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
            background:
                radial-gradient(circle at top right, #dce9ff 0, transparent 35%),
                radial-gradient(circle at bottom left, #e8f3ff 0, transparent 40%),
                var(--bg);
            color: var(--ink);
        }

        body.admin-uppercase,
        body.admin-uppercase * {
            text-transform: uppercase;
        }

        body.admin-uppercase input[type="password"] {
            text-transform: none;
        }

        body.admin-uppercase input::placeholder,
        body.admin-uppercase textarea::placeholder {
            text-transform: uppercase;
        }

        .container {
            width: min(1180px, 95vw);
            margin: 26px auto 34px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: #f8fbff;
        }

        .topbar .title {
            font-size: 18px;
            font-weight: 700;
        }

        .topbar .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .chip {
            padding: 8px 12px;
            border-radius: 999px;
            background: #eef4ff;
            border: 1px solid #d9e5ff;
            color: #1a3d97;
            font-size: 13px;
        }

        .link-btn {
            text-decoration: none;
            border: 1px solid var(--line);
            background: #fff;
            color: var(--ink);
            border-radius: 10px;
            padding: 8px 12px;
            font-size: 14px;
            display: inline-block;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 14px;
            box-shadow: 0 8px 24px rgba(28, 50, 95, 0.08);
            padding: 18px;
        }

        .card h2 {
            margin-top: 0;
            margin-bottom: 8px;
            font-size: 18px;
        }

        .card p {
            margin-top: 0;
            color: var(--muted);
            font-size: 14px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        label {
            font-size: 13px;
            font-weight: 600;
            color: #2f3c56;
        }

        input {
            width: 100%;
            border: 1px solid #cdd8ea;
            border-radius: 10px;
            padding: 10px 11px;
            font-size: 14px;
            background: #fff;
        }

        input:focus {
            outline: none;
            border-color: #7ca0ff;
            box-shadow: 0 0 0 3px rgba(66, 104, 214, 0.15);
        }

        select {
            width: 100%;
            border: 1px solid #cdd8ea;
            border-radius: 10px;
            padding: 10px 11px;
            font-size: 14px;
            background: #fff;
        }

        select:focus {
            outline: none;
            border-color: #7ca0ff;
            box-shadow: 0 0 0 3px rgba(66, 104, 214, 0.15);
        }

        .actions-row {
            margin-top: 12px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        button {
            border: none;
            background: var(--primary);
            color: #fff;
            border-radius: 10px;
            padding: 10px 14px;
            font-weight: 600;
            cursor: pointer;
        }

        button:hover {
            background: var(--primary-strong);
        }

        .status {
            margin-bottom: 12px;
            padding: 10px 12px;
            border-radius: 10px;
            font-size: 14px;
        }

        .ok {
            background: var(--ok-bg);
            color: var(--ok);
            border: 1px solid #bce8c8;
        }

        .error {
            background: var(--danger-bg);
            color: var(--danger);
            border: 1px solid #ffc5c0;
        }

        .active-code {
            margin-top: 12px;
            padding: 12px;
            border-radius: 10px;
            border: 1px dashed #c4d4f5;
            background: #f7faff;
            font-size: 14px;
        }

        .table-wrap {
            overflow-x: auto;
            margin-top: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 620px;
        }

        th, td {
            border-bottom: 1px solid var(--line);
            padding: 10px;
            text-align: left;
            font-size: 14px;
            vertical-align: middle;
        }

        th {
            background: #f5f8ff;
            color: #1f3a74;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .inline-form {
            display: inline;
        }

        .btn-danger {
            background: #c81e1e;
            padding: 8px 11px;
            font-size: 13px;
        }

        .btn-danger:hover {
            background: #9e1919;
        }

        .btn-view {
            display: inline-block;
            text-decoration: none;
            color: #fff;
            background: #2257d6;
            border-radius: 8px;
            padding: 8px 11px;
            font-size: 13px;
            font-weight: 600;
        }

        .btn-secondary {
            display: inline-block;
            text-decoration: none;
            color: var(--ink);
            background: #eef4ff;
            border: 1px solid #d9e5ff;
            border-radius: 8px;
            padding: 8px 11px;
            font-size: 13px;
            font-weight: 600;
        }

        .state-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 100px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .state-enabled {
            background: #e9f8ee;
            color: #155724;
            border: 1px solid #bce8c8;
        }

        .state-disabled {
            background: #fff2e8;
            color: #9a3412;
            border: 1px solid #fed7aa;
        }

        .table-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .section-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .section-head p {
            margin-bottom: 0;
        }

        .section-tools {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .disabled-note {
            color: #7c8798;
            font-size: 13px;
        }

        .search-filters {
            margin-bottom: 16px;
            padding: 16px;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: var(--card);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .search-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: end;
        }

        .search-row .field {
            flex: 1;
            min-width: 200px;
        }

        .search-row .field label {
            display: block;
            margin-bottom: 4px;
            font-weight: 600;
            color: var(--muted);
        }

        .search-row button {
            padding: 10px 16px;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }

        .search-row button:hover {
            background: var(--primary-strong);
        }

        .search-row .link-btn {
            padding: 10px 16px;
            min-height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .pagination {
            margin-top: 16px;
            display: flex;
            justify-content: center;
            gap: 8px;
        }

        .pagination a, .pagination span {
            padding: 8px 12px;
            border: 1px solid var(--line);
            border-radius: 6px;
            text-decoration: none;
            color: var(--ink);
            background: #fff;
        }

        .pagination a:hover {
            background: #f5f8ff;
        }

        .pagination .active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        @media (max-width: 980px) {
            .grid { grid-template-columns: 1fr; }
            .form-grid { grid-template-columns: 1fr; }
            .topbar { flex-direction: column; align-items: flex-start; }
            .search-row { flex-direction: column; align-items: stretch; }
        }

        @media (max-width: 768px) {
            .container {
                width: min(1180px, calc(100% - 16px));
                margin: 16px auto 24px;
            }

            .topbar,
            .card,
            .search-filters {
                padding: 14px;
                border-radius: 12px;
            }

            .topbar .actions {
                width: 100%;
                flex-direction: column;
                align-items: stretch;
            }

            .topbar .actions .link-btn {
                width: 100%;
                text-align: center;
            }

            .section-tools {
                width: 100%;
            }

            .table-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .table-actions .btn-secondary,
            .table-actions .inline-form,
            .table-actions .inline-form button,
            .btn-view {
                width: 100%;
                text-align: center;
            }

            .pagination {
                flex-wrap: wrap;
            }

            table {
                min-width: 560px;
            }
        }

        @media (max-width: 480px) {
            .container {
                width: min(1180px, calc(100% - 12px));
                margin: 12px auto 20px;
            }

            .topbar,
            .card,
            .search-filters {
                padding: 12px;
            }

            table {
                min-width: 480px;
            }
        }
    </style>
</head>
<body class="admin-uppercase">
    <div class="container">
        <div class="topbar">
            <div class="title">Panel de Administracion</div>
            <div class="actions">
                <span class="chip">Sesion: <strong><?= htmlspecialchars($adminUsername, ENT_QUOTES, 'UTF-8') ?></strong></span>
                <form method="post" action="logout.php" class="inline-form">
                    <?= csrf_field() ?>
                    <button class="link-btn" type="submit">Cerrar sesion</button>
                </form>
                <a class="link-btn" href="../index.php">Volver al inicio</a>
            </div>
        </div>

        <?php foreach ($successMessages as $msg): ?>
            <div class="status ok"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($errorMessages as $msg): ?>
            <div class="status error"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>

        <div class="grid">
            <section class="card">
                <h2>Codigo de Acceso</h2>
                <p>Genera un codigo para habilitar el acceso al test de los usuarios dentro de un rango exacto de fecha y hora.</p>
                <p>Zona horaria aplicada: <strong><?= htmlspecialchars($accessCodeTimezoneLabel, ENT_QUOTES, 'UTF-8') ?></strong></p>

                <form id="access-code-form" method="post" action="admin.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="generate_code">
                    <div class="form-grid">
                        <div class="field">
                            <label for="fecha_inicio_date">Fecha inicio</label>
                            <input type="date" id="fecha_inicio_date" name="fecha_inicio_date" value="<?= htmlspecialchars($codeStartDateInput, ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="field">
                            <label for="fecha_inicio_time">Hora inicio</label>
                            <input type="time" id="fecha_inicio_time" name="fecha_inicio_time" value="<?= htmlspecialchars($codeStartTimeInput, ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="field">
                            <label for="fecha_fin_date">Fecha fin</label>
                            <input type="date" id="fecha_fin_date" name="fecha_fin_date" value="<?= htmlspecialchars($codeEndDateInput, ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="field">
                            <label for="fecha_fin_time">Hora fin</label>
                            <input type="time" id="fecha_fin_time" name="fecha_fin_time" value="<?= htmlspecialchars($codeEndTimeInput, ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                    </div>
                    <div class="actions-row">
                        <button type="submit">Generar codigo</button>
                    </div>
                </form>

                <div class="active-code">
                    <?php if ($activeCode): ?>
                        <strong>Codigo configurado:</strong> <?= htmlspecialchars((string)$activeCode['code'], ENT_QUOTES, 'UTF-8') ?><br>
                        <strong>Estado:</strong> <?= htmlspecialchars(get_access_code_status_label($activeCode), ENT_QUOTES, 'UTF-8') ?><br>
                        <strong>Inicia:</strong> <?= htmlspecialchars(format_access_code_datetime((string)$activeCode['fecha_inicio']), ENT_QUOTES, 'UTF-8') ?><br>
                        <strong>Finaliza:</strong> <?= htmlspecialchars(format_access_code_datetime((string)$activeCode['fecha_fin']), ENT_QUOTES, 'UTF-8') ?><br>
                        <strong>Vigencia:</strong> <?= htmlspecialchars(describe_access_code_validity((string)$activeCode['fecha_inicio'], (string)$activeCode['fecha_fin']), ENT_QUOTES, 'UTF-8') ?>
                    <?php else: ?>
                        No hay codigo configurado en este momento.
                    <?php endif; ?>
                </div>
            </section>

            <section class="card">
                <h2>Gestion de Administradores</h2>
                <p>Crea nuevos administradores con contraseñas seguras.</p>

                <form method="post" action="admin.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_admin">
                    <div class="form-grid">
                        <div class="field">
                            <label for="username">USUARIO</label>
                            <input type="text" id="username" name="username" maxlength="100" autocomplete="off" required>
                        </div>
                        <div class="field">
                            <label for="password">CONTRASEÑA</label>
                            <input type="password" id="password" name="password" minlength="8" autocomplete="new-password" required>
                        </div>
                        <div class="field">
                            <label for="confirm_password">CONFIRMAR CONTRASEÑA</label>
                            <input type="password" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required>
                        </div>
                    </div>
                    <div class="actions-row">
                        <button type="submit">Crear nuevo administrador</button>
                    </div>
                </form>

                <p style="margin-top:10px;">Reglas: usuario unico, contraseña minimo 8 caracteres y confirmacion obligatoria.</p>
            </section>
        </div>

        <section class="card" style="margin-bottom:16px;">
            <div class="section-head">
                <div>
                    <h2><?= htmlspecialchars($positionFormTitle, ENT_QUOTES, 'UTF-8') ?></h2>
                    <p>Administra los puestos disponibles para el registro. Los nombres se normalizan internamente para evitar duplicados.</p>
                </div>
                <div class="section-tools">
                    <span class="chip">Total puestos: <strong><?= count($positions) ?></strong></span>
                    <span class="chip">Habilitados: <strong><?= $enabledPositionsCount ?></strong></span>
                    <?php if ($positionFormMode === 'edit'): ?>
                        <a class="btn-secondary" href="admin.php">Cancelar edicion</a>
                    <?php endif; ?>
                </div>
            </div>

            <form method="post" action="admin.php<?= $positionFormMode === 'edit' ? '?edit_position=' . $positionFormId : '' ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= htmlspecialchars($positionFormAction, ENT_QUOTES, 'UTF-8') ?>">
                <?php if ($positionFormMode === 'edit'): ?>
                    <input type="hidden" name="position_id" value="<?= $positionFormId ?>">
                <?php endif; ?>

                <div class="form-grid">
                    <div class="field">
                        <label for="position_name">Nombre del puesto</label>
                        <input
                            type="text"
                            id="position_name"
                            name="position_name"
                            maxlength="255"
                            value="<?= htmlspecialchars($positionFormName, ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="Ej. gerente comercial"
                            required
                        >
                    </div>
                    <div class="field">
                        <label for="position_enabled">Estado</label>
                        <select id="position_enabled" name="position_enabled">
                            <option value="1" <?= $positionFormEnabled ? 'selected' : '' ?>>HABILITADO</option>
                            <option value="0" <?= !$positionFormEnabled ? 'selected' : '' ?>>DESHABILITADO</option>
                        </select>
                    </div>
                </div>

                <div class="actions-row">
                    <button type="submit"><?= htmlspecialchars($positionFormButton, ENT_QUOTES, 'UTF-8') ?></button>
                </div>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>PUESTO O CARGO</th>
                            <th>Estado</th>
                            <th>Usuarios</th>
                            <th>Actualizado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($positions)): ?>
                            <tr>
                                <td colspan="6">No hay puestos registrados.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($positions as $position): ?>
                                <?php $isEnabled = !empty($position['is_enabled']); ?>
                                <tr>
                                    <td><?= (int)$position['id'] ?></td>
                                    <td><?= htmlspecialchars((string)$position['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <span class="state-badge <?= $isEnabled ? 'state-enabled' : 'state-disabled' ?>">
                                            <?= $isEnabled ? 'Habilitado' : 'Deshabilitado' ?>
                                        </span>
                                    </td>
                                    <td><?= (int)($position['users_count'] ?? 0) ?></td>
                                    <td><?= htmlspecialchars((string)($position['updated_at'] ?? $position['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <div class="table-actions">
                                            <a
                                                class="btn-secondary"
                                                href="?<?= http_build_query(array_merge($baseQueryParams, ['edit_position' => (int)$position['id']])) ?>"
                                            >
                                                Editar
                                            </a>
                                            <form class="inline-form" method="post" action="admin.php">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_position">
                                                <input type="hidden" name="position_id" value="<?= (int)$position['id'] ?>">
                                                <input type="hidden" name="next_state" value="<?= $isEnabled ? 'disable' : 'enable' ?>">
                                                <button type="submit">
                                                    <?= $isEnabled ? 'Deshabilitar' : 'Habilitar' ?>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <h2>Ver Administradores</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Usuario</th>
                            <th>Fecha de creacion</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($admins)): ?>
                            <tr>
                                <td colspan="4">No hay administradores registrados.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($admins as $admin): ?>
                                <tr>
                                    <td><?= (int)$admin['id'] ?></td>
                                    <td><?= htmlspecialchars((string)$admin['username'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string)($admin['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?php if ((int)$admin['id'] === $currentAdminId): ?>
                                            <span class="disabled-note">Sesion actual (no se puede eliminar)</span>
                                        <?php else: ?>
                                            <form class="inline-form" method="post" action="admin.php" onsubmit="return confirm('ELIMINAR ESTE ADMINISTRADOR?');">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_admin">
                                                <input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>">
                                                <button class="btn-danger" type="submit">Eliminar administrador</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card" style="margin-top:16px;">
            <h2>Usuarios Registrados</h2>

            <div class="search-filters">
                <form method="get" class="search-row">
                    <div class="field">
                        <label for="search">Buscar</label>
                        <input type="text" id="search" name="search" placeholder="Nombre, apellidos, CI, edad, profesion, puesto, ID test" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="field">
                        <label for="filter_date">Fecha</label>
                        <select id="filter_date" name="filter_date">
                            <option value="">TODAS</option>
                            <option value="today" <?= $filter_date === 'today' ? 'selected' : '' ?>>HOY</option>
                            <option value="7days" <?= $filter_date === '7days' ? 'selected' : '' ?>>Últimos 7 días</option>
                            <option value="month" <?= $filter_date === 'month' ? 'selected' : '' ?>>Último mes</option>
                            <option value="custom" <?= $filter_date === 'custom' ? 'selected' : '' ?>>RANGO PERSONALIZADO</option>
                        </select>
                    </div>
                    <div class="field" id="start-date-field" style="display: <?= $filter_date === 'custom' ? 'block' : 'none' ?>;">
                        <label for="start_date">Desde</label>
                        <input type="date" id="start_date" name="start_date" value="<?= htmlspecialchars($_GET['start_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="field" id="end-date-field" style="display: <?= $filter_date === 'custom' ? 'block' : 'none' ?>;">
                        <label for="end_date">Hasta</label>
                        <input type="date" id="end_date" name="end_date" value="<?= htmlspecialchars($_GET['end_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div class="field">
                        <label for="filter_result">Resultado</label>
                        <select id="filter_result" name="filter_result">
                            <option value="">TODOS</option>
                            <option value="alto" <?= $filter_result === 'alto' ? 'selected' : '' ?>>ALTO</option>
                            <option value="promedio" <?= $filter_result === 'promedio' ? 'selected' : '' ?>>PROMEDIO</option>
                            <option value="bajo" <?= $filter_result === 'bajo' ? 'selected' : '' ?>>BAJO</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="filter_status">Estado</label>
                        <select id="filter_status" name="filter_status">
                            <option value="">TODOS</option>
                            <option value="completed" <?= $filter_status === 'completed' ? 'selected' : '' ?>>COMPLETADO</option>
                            <option value="in_progress" <?= $filter_status === 'in_progress' ? 'selected' : '' ?>>EN PROGRESO</option>
                        </select>
                    </div>
                    <div class="field">
                        <button type="submit">Buscar</button>
                    </div>
                    <div class="field">
                        <label>&nbsp;</label>
                        <a class="link-btn" href="?<?= htmlspecialchars($exportUsersQuery, ENT_QUOTES, 'UTF-8') ?>">Exportar a Excel</a>
                    </div>
                </form>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th><a href="?<?= http_build_query(array_merge($baseQueryParams, ['sort_by' => 'u.id', 'sort_order' => $sort_by === 'u.id' && $sort_order === 'ASC' ? 'DESC' : 'ASC', 'page' => 1])) ?>" style="color: inherit; text-decoration: none;">ID</a></th>
                            <th><a href="?<?= http_build_query(array_merge($baseQueryParams, ['sort_by' => 'full_name', 'sort_order' => $sort_by === 'full_name' && $sort_order === 'ASC' ? 'DESC' : 'ASC', 'page' => 1])) ?>" style="color: inherit; text-decoration: none;">Nombre completo</a></th>
                            <th>CI</th>
                            <th>Profesion</th>
                            <th>Puesto</th>
                            <th>Test ID</th>
                            <th><a href="?<?= http_build_query(array_merge($baseQueryParams, ['sort_by' => 't.start_datetime', 'sort_order' => $sort_by === 't.start_datetime' && $sort_order === 'ASC' ? 'DESC' : 'ASC', 'page' => 1])) ?>" style="color: inherit; text-decoration: none;">Fecha Inicio</a></th>
                            <th>Estado</th>
                            <th>Accion</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="9">No hay usuarios registrados.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?= (int)$user['user_id'] ?></td>
                                    <td><?= htmlspecialchars(build_user_full_name(
                                        (string)($user['name'] ?? ''),
                                        isset($user['apellido_paterno']) ? (string)$user['apellido_paterno'] : null,
                                        isset($user['apellido_materno']) ? (string)$user['apellido_materno'] : null
                                    ), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars(format_user_ci((string)($user['ci_numero'] ?? ''), isset($user['ci_complemento']) ? (string)$user['ci_complemento'] : null) ?: '-', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string)($user['profession'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string)($user['position_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= $user['test_id'] ? (int)$user['test_id'] : '-' ?></td>
                                    <td><?= htmlspecialchars((string)($user['start_datetime'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?php if ($user['end_datetime']): ?>
                                            <span style="color: var(--ok);">Completado</span>
                                        <?php elseif ($user['start_datetime']): ?>
                                            <span style="color: #ffc107;">En progreso</span>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($user['test_id']) && (int)$user['has_results'] === 1): ?>
                                            <a class="btn-view" href="results.php?test_id=<?= (int)$user['test_id'] ?>">Ver resultados</a>
                                        <?php else: ?>
                                            <span class="disabled-note">Sin resultados</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="active"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?<?= http_build_query(array_merge($baseQueryParams, ['page' => $i])) ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <script>
        const filterDate = document.getElementById('filter_date');
        if (filterDate) {
            const filterDateLabels = {
                '': 'TODAS',
                today: 'HOY',
                '7days': 'ULTIMOS 7 DIAS',
                month: 'ULTIMO MES',
                custom: 'RANGO PERSONALIZADO'
            };

            Array.from(filterDate.options).forEach(function(option) {
                if (Object.prototype.hasOwnProperty.call(filterDateLabels, option.value)) {
                    option.textContent = filterDateLabels[option.value];
                }
            });
        }

        const filterResult = document.getElementById('filter_result');
        if (filterResult) {
            const filterResultLabels = {
                '': 'TODOS',
                alto: 'ALTO',
                promedio: 'PROMEDIO',
                bajo: 'BAJO'
            };

            Array.from(filterResult.options).forEach(function(option) {
                if (Object.prototype.hasOwnProperty.call(filterResultLabels, option.value)) {
                    option.textContent = filterResultLabels[option.value];
                }
            });
        }

        const filterStatus = document.getElementById('filter_status');
        if (filterStatus) {
            const filterStatusLabels = {
                '': 'TODOS',
                completed: 'COMPLETADO',
                in_progress: 'EN PROGRESO'
            };

            Array.from(filterStatus.options).forEach(function(option) {
                if (Object.prototype.hasOwnProperty.call(filterStatusLabels, option.value)) {
                    option.textContent = filterStatusLabels[option.value];
                }
            });
        }

        if (filterDate) {
            filterDate.addEventListener('change', function() {
                const startField = document.getElementById('start-date-field');
                const endField = document.getElementById('end-date-field');
                if (this.value === 'custom') {
                    startField.style.display = 'block';
                    endField.style.display = 'block';
                } else {
                    startField.style.display = 'none';
                    endField.style.display = 'none';
                }
            });
        }

        const accessCodeForm = document.getElementById('access-code-form');
        if (accessCodeForm) {
            const startDateInput = document.getElementById('fecha_inicio_date');
            const startTimeInput = document.getElementById('fecha_inicio_time');
            const endDateInput = document.getElementById('fecha_fin_date');
            const endTimeInput = document.getElementById('fecha_fin_time');

            function buildRangeValue(dateValue, timeValue) {
                if (!dateValue || !timeValue) {
                    return null;
                }

                return new Date(dateValue + 'T' + timeValue + ':00');
            }

            function syncAccessCodeRangeValidity() {
                const startValue = buildRangeValue(startDateInput.value, startTimeInput.value);
                const endValue = buildRangeValue(endDateInput.value, endTimeInput.value);
                const message = 'LA FECHA Y HORA DE FIN DEBE SER MAYOR QUE LA FECHA Y HORA DE INICIO.';

                endDateInput.setCustomValidity('');
                endTimeInput.setCustomValidity('');

                if (!startValue || !endValue) {
                    return true;
                }

                if (endValue <= startValue) {
                    endDateInput.setCustomValidity(message);
                    endTimeInput.setCustomValidity(message);
                    return false;
                }

                return true;
            }

            [startDateInput, startTimeInput, endDateInput, endTimeInput].forEach(function(input) {
                input.addEventListener('input', syncAccessCodeRangeValidity);
                input.addEventListener('change', syncAccessCodeRangeValidity);
            });

            accessCodeForm.addEventListener('submit', function(event) {
                if (!syncAccessCodeRangeValidity()) {
                    event.preventDefault();
                    accessCodeForm.reportValidity();
                }
            });

            syncAccessCodeRangeValidity();
        }
    </script>
</body>
</html>
