<?php
require_once __DIR__ . '/../includes/session.php';
app_start_session();
header('Content-Type: text/html; charset=UTF-8');

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/csrf.php';

if (is_admin_authenticated()) {
    header('Location: admin.php');
    exit;
}

$error = '';
$info = '';
$username_value = '';

if (isset($_GET['logout']) && $_GET['logout'] === '1') {
    $info = 'Sesión cerrada correctamente.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_request_is_valid()) {
    http_response_code(400);
    exit('La sesión del formulario expiró. Recargue la página e inténtelo nuevamente.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username_input = trim((string)($_POST['username'] ?? ''));
    $password_input = (string)($_POST['password'] ?? '');
    $username_value = $username_input;

    $username_sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '', $username_input) ?? '';

    if ($username_input === '' || $password_input === '') {
        $error = 'Debe ingresar usuario y contraseña.';
    } elseif ($username_sanitized !== $username_input) {
        $error = 'Formato de usuario inválido.';
    } else {
        $stmt = $conn->prepare(
            "SELECT id, username, password_hash, is_active
             FROM admin_users
             WHERE LOWER(username) = LOWER(:username)
             LIMIT 1"
        );
        $stmt->execute([':username' => $username_sanitized]);
        $admin = $stmt->fetch();
        $dummyPasswordHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';
        $storedPasswordHash = (string)($admin['password_hash'] ?? '');
        $passwordHash = $storedPasswordHash !== '' ? $storedPasswordHash : $dummyPasswordHash;
        $passwordIsValid = password_verify($password_input, $passwordHash);

        $is_active = false;
        if ($admin && isset($admin['is_active'])) {
            $active_raw = strtolower((string)$admin['is_active']);
            $is_active = in_array($active_raw, ['1', 't', 'true', 'yes'], true);
        }

        if (
            $admin &&
            $is_active &&
            $passwordIsValid
        ) {
            if (password_needs_rehash($storedPasswordHash, PASSWORD_DEFAULT)) {
                $updatedHash = password_hash($password_input, PASSWORD_DEFAULT);
                $rehashStmt = $conn->prepare(
                    'UPDATE admin_users SET password_hash = :password_hash WHERE id = :id'
                );
                $rehashStmt->execute([
                    ':password_hash' => $updatedHash,
                    ':id' => (int)$admin['id'],
                ]);
            }

            session_regenerate_id(true);
            $_SESSION['admin_authenticated'] = true;
            $_SESSION['admin_user_id'] = (int)$admin['id'];
            $_SESSION['admin_username'] = (string)$admin['username'];
            $_SESSION['admin_logged_at'] = time();

            header('Location: admin.php');
            exit;
        }

        $error = 'Credenciales inválidas.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#075077">
    <title>Acceso administrativo · Mi Teleférico</title>
    <link rel="stylesheet" href="../css/admin-login.css">
</head>
<body class="admin-login-page">
    <main class="login-shell">
        <aside class="login-brand" aria-label="Identidad institucional de Mi Teleférico">
            <div class="brand-topline"><span class="brand-status-dot"></span> PLATAFORMA INSTITUCIONAL</div>
            <div class="brand-art" aria-hidden="true">
                <span class="cable cable-one"></span><span class="cable cable-two"></span>
                <span class="cable cable-three"></span>
                <img class="brand-symbol" src="../img/User%20attachment.png" alt="">
                <span class="station station-one"></span><span class="station station-two"></span>
            </div>
            <div class="brand-copy">
                <p class="brand-eyebrow">CONECTANDO CIUDADES, ACERCANDO PERSONAS</p>
                <h2>Unimos nuestras vidas.</h2>
                <p>Un servicio que nos acerca y transforma la manera de vivir nuestra ciudad.</p>
            </div>
            <div class="line-spectrum" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></div>
            <div class="brand-footer"><span>LA PAZ · BOLIVIA</span><span>MI TELEFÉRICO</span></div>
        </aside>

        <section class="login-content" aria-labelledby="login-title">
            <div class="login-card">
                <a class="corporate-logo" href="../index.php" aria-label="Mi Teleférico, volver al inicio">
                    <img src="../img/mi-teleferico-logo.png" alt="Mi Teleférico · Uniendo nuestras vidas">
                </a>
                <div class="form-heading">
                    <span class="heading-kicker">ACCESO SEGURO</span>
                    <h1 id="login-title">Bienvenido</h1>
                    <p>Inicia sesión para acceder al sistema.</p>
                </div>

                <?php if ($info !== ''): ?>
                    <div class="status-info" role="status"><?= htmlspecialchars($info, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <div class="status-error" id="login-error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <form class="login-form" method="post" action="admin_login.php">
                    <?= csrf_field() ?>
                    <label for="username">Usuario</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="login-input"
                        maxlength="100"
                        autocomplete="username"
                        placeholder="Ingresa tu usuario"
                        value="<?= htmlspecialchars($username_value, ENT_QUOTES, 'UTF-8') ?>"
                        aria-invalid="<?= $error !== '' ? 'true' : 'false' ?>"
                        <?= $error !== '' ? 'aria-describedby="login-error"' : '' ?>
                        required
                    >

                    <label for="password">Contraseña</label>
                    <div class="password-control">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="login-input"
                            autocomplete="current-password"
                            placeholder="Ingresa tu contraseña"
                            aria-invalid="<?= $error !== '' ? 'true' : 'false' ?>"
                            <?= $error !== '' ? 'aria-describedby="login-error"' : '' ?>
                            required
                        >
                        <button class="password-toggle" type="button" aria-label="Mostrar contraseña" aria-pressed="false">
                            <svg class="eye-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>

                    <button class="login-submit" type="submit"><span>Iniciar sesión</span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
                </form>

                <div class="bottom-link">
                    <a href="../index.php"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>Volver al inicio</a>
                </div>
            </div>
            <footer class="login-footer">© <?= date('Y') ?> Mi Teleférico <span>·</span> Uniendo nuestras vidas</footer>
        </section>
    </main>
    <script src="../js/admin-login.js" defer></script>
</body>
</html>
