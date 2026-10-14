<?php
require_once __DIR__ . '/../includes/session.php';
app_start_session();
header('Content-Type: text/html; charset=UTF-8');

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/captcha.php';
require __DIR__ . '/../includes/access_codes.php';
require __DIR__ . '/../includes/positions.php';
require_once __DIR__ . '/../includes/user_identity.php';

ensure_access_codes_schema($conn);
ensure_positions_schema($conn);
ensure_user_identity_schema($conn);

function redirect_to_public_form(string $errorMessage, array $oldInput = []): void
{
    unset(
        $_SESSION['pending_user'],
        $_SESSION['captcha_verified'],
        $_SESSION['captcha_verified_at'],
        $_SESSION['access_code_granted'],
        $_SESSION['access_code_id'],
        $_SESSION['access_code_granted_at'],
        $_SESSION['validated_access_code'],
        $_SESSION['test_id'],
        $_SESSION['start_time'],
        $_SESSION['tiempo_total']
    );

    $_SESSION['form_error'] = $errorMessage;
    $_SESSION['form_old'] = $oldInput;

    header('Location: ../index.php');
    exit;
}

$error = '';
$info = '';
$step = $_POST['step'] ?? '';
$enteredAccessCode = '';
$accessCodeInfo = null;
$showValidatedCode = false;
$visibleAccessCode = null;
$visibleAccessCodeStatus = 'not_found';
$visibleAccessCodeMessage = '';
$showVisibleAccessCode = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'captcha') {
    $captchaToken = $_POST['cf-turnstile-response'] ?? '';

    if (empty($captchaToken) || !verify_turnstile_token($captchaToken, $_SERVER['REMOTE_ADDR'] ?? '')) {
        die('ERROR: VALIDACION CAPTCHA FALLIDA. REGRESA AL INICIO E INTENTALO DE NUEVO.');
    }

    $name = trim((string)($_POST['name'] ?? ''));
    $apellidoPaterno = trim((string)($_POST['apellido_paterno'] ?? ''));
    $apellidoMaterno = trim((string)($_POST['apellido_materno'] ?? ''));
    $apellidoCasada = trim((string)($_POST['apellido_casada'] ?? ''));
    $age = (int)($_POST['age'] ?? 0);
    $profession = trim((string)($_POST['profession'] ?? ''));
    $rawCiNumero = (string)($_POST['ci_numero'] ?? '');
    $rawCiComplemento = (string)($_POST['ci_complemento'] ?? '');
    $positionId = filter_input(
        INPUT_POST,
        'position_id',
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    $oldInput = [
        'name' => $name,
        'apellido_paterno' => $apellidoPaterno,
        'apellido_materno' => $apellidoMaterno,
        'apellido_casada' => $apellidoCasada,
        'age' => $age > 0 ? (string)$age : '',
        'profession' => $profession,
        'ci_numero' => normalize_ci_numero($rawCiNumero),
        'ci_complemento' => normalize_ci_complemento($rawCiComplemento) ?? '',
        'position_id' => is_int($positionId) ? (string)$positionId : '',
    ];

    if ($age < 1 || !is_int($positionId)) {
        redirect_to_public_form('DEBES COMPLETAR CORRECTAMENTE TODOS LOS DATOS OBLIGATORIOS.', $oldInput);
    }

    try {
        $name = validate_user_name_part($name, 'NOMBRE');
        $apellidoPaterno = validate_user_name_part($apellidoPaterno, 'APELLIDO PATERNO');
        $apellidoMaterno = validate_user_name_part($apellidoMaterno, 'APELLIDO MATERNO');
        $apellidoCasada = validate_optional_user_name_part($apellidoCasada, 'APELLIDO DE CASADA');
        $ciNumero = validate_ci_numero($rawCiNumero);
        $ciComplemento = validate_ci_complemento($rawCiComplemento);
    } catch (InvalidArgumentException $e) {
        redirect_to_public_form($e->getMessage(), $oldInput);
    }

    $position = get_enabled_position_by_id($conn, $positionId);
    if (!$position) {
        redirect_to_public_form(
            'EL PUESTO SELECCIONADO NO ESTA DISPONIBLE. REGRESA AL INICIO Y VUELVE A INTENTARLO.',
            $oldInput
        );
    }

    unset(
        $_SESSION['test_id'],
        $_SESSION['start_time'],
        $_SESSION['tiempo_total'],
        $_SESSION['access_code_granted'],
        $_SESSION['access_code_id'],
        $_SESSION['access_code_granted_at'],
        $_SESSION['validated_access_code']
    );

    $_SESSION['pending_user'] = [
        'name' => $name,
        'apellido_paterno' => $apellidoPaterno,
        'apellido_materno' => $apellidoMaterno,
        'apellido_casada' => $apellidoCasada,
        'age' => $age,
        'profession' => $profession,
        'ci_numero' => $ciNumero,
        'ci_complemento' => $ciComplemento,
        'position_id' => (int)$position['id'],
        'position_name' => (string)$position['name'],
    ];
    $_SESSION['captcha_verified'] = true;
    $_SESSION['captcha_verified_at'] = time();

    header('Location: access_code.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'access_code') {
    if (empty($_SESSION['captcha_verified']) || !isset($_SESSION['pending_user'])) {
        header('Location: ../index.php');
        exit;
    }

    $enteredCode = (string)($_POST['access_code'] ?? '');
    $activeCodeResult = get_active_access_code_validation_result($conn);
    $activeCodeStatus = (string)($activeCodeResult['status'] ?? 'not_found');

    if ($activeCodeStatus !== 'valid') {
        $enteredAccessCode = normalize_access_code($enteredCode);
        $accessCodeInfo = isset($activeCodeResult['row']) && is_array($activeCodeResult['row'])
            ? $activeCodeResult['row']
            : null;
        $error = (string)($activeCodeResult['message'] ?? 'Codigo fuera de vigencia');
    } else {
        $validationResult = find_access_code_validation_result($conn, $enteredCode);
        $enteredAccessCode = (string)($validationResult['code'] ?? '');
        $accessCodeInfo = $validationResult['row'] ?? null;

        if (($validationResult['status'] ?? '') === 'valid' && is_array($accessCodeInfo)) {
            $_SESSION['access_code_granted'] = true;
            $_SESSION['access_code_id'] = (int)$accessCodeInfo['id'];
            $_SESSION['access_code_granted_at'] = time();
            $_SESSION['validated_access_code'] = [
                'code' => (string)$accessCodeInfo['code'],
                'fecha_inicio' => (string)$accessCodeInfo['fecha_inicio'],
                'fecha_fin' => (string)$accessCodeInfo['fecha_fin'],
            ];

            header('Location: access_code.php?validated=1');
            exit;
        }

        $error = (string)($validationResult['message'] ?? 'Codigo incorrecto');
    }
}

if (
    isset($_GET['validated']) &&
    $_GET['validated'] === '1' &&
    !empty($_SESSION['access_code_granted']) &&
    isset($_SESSION['validated_access_code']) &&
    is_array($_SESSION['validated_access_code'])
) {
    $showValidatedCode = true;
    $info = 'Codigo validado correctamente.';
    $enteredAccessCode = (string)($_SESSION['validated_access_code']['code'] ?? '');
    $accessCodeInfo = $_SESSION['validated_access_code'];
}

$captchaVerifiedAt = (int)($_SESSION['captcha_verified_at'] ?? 0);
if ($captchaVerifiedAt > 0 && (time() - $captchaVerifiedAt) > 1800) {
    unset($_SESSION['pending_user'], $_SESSION['captcha_verified'], $_SESSION['captcha_verified_at']);
}

if (empty($_SESSION['captcha_verified']) || !isset($_SESSION['pending_user'])) {
    header('Location: ../index.php');
    exit;
}

$pendingFullName = build_user_full_name_from_array($_SESSION['pending_user']);
$pendingApellidoCasada = normalize_user_name_part((string)($_SESSION['pending_user']['apellido_casada'] ?? ''));
$pendingAge = (string)($_SESSION['pending_user']['age'] ?? '');
$pendingProfession = (string)($_SESSION['pending_user']['profession'] ?? '');
$pendingCi = format_user_ci(
    (string)($_SESSION['pending_user']['ci_numero'] ?? ''),
    isset($_SESSION['pending_user']['ci_complemento']) ? (string)$_SESSION['pending_user']['ci_complemento'] : null
);
$pendingPosition = (string)($_SESSION['pending_user']['position_name'] ?? '');
$visibleAccessCodeResult = get_active_access_code_validation_result($conn);
$visibleAccessCodeStatus = (string)($visibleAccessCodeResult['status'] ?? 'not_found');
$visibleAccessCodeMessage = (string)($visibleAccessCodeResult['message'] ?? '');
$visibleAccessCode = isset($visibleAccessCodeResult['row']) && is_array($visibleAccessCodeResult['row'])
    ? $visibleAccessCodeResult['row']
    : null;
$showVisibleAccessCode = $visibleAccessCodeStatus === 'valid' && $visibleAccessCode !== null;
$accessCodeTimezoneLabel = access_code_timezone_name();
$accessCodeValidityMessage = is_array($accessCodeInfo)
    ? describe_access_code_validity(
        isset($accessCodeInfo['fecha_inicio']) ? (string)$accessCodeInfo['fecha_inicio'] : null,
        isset($accessCodeInfo['fecha_fin']) ? (string)$accessCodeInfo['fecha_fin'] : null
    )
    : '';
$visibleAccessCodeValidityMessage = $showVisibleAccessCode
    ? describe_access_code_validity(
        (string)$visibleAccessCode['fecha_inicio'],
        (string)$visibleAccessCode['fecha_fin']
    )
    : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VALIDAR CÓDIGO DE ACCESO</title>
    <link rel="stylesheet" href="../css/styles.css">
    <style>
        .code-card {
            max-width: 520px;
            margin: 40px auto;
            padding: 28px;
            border-radius: 14px;
            background: #ffffff;
            box-shadow: 0 8px 20px rgba(30, 64, 175, 0.15);
            border: 1px solid #dce5f7;
        }

        .user-summary {
            margin: 18px 0;
            padding: 14px;
            border-radius: 10px;
            background: #f4f8ff;
            color: #1e293b;
        }

        .error-box {
            margin-bottom: 14px;
            padding: 10px 12px;
            border-radius: 8px;
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .back-link {
            margin-top: 14px;
            text-align: center;
        }

        .info-box {
            margin-bottom: 14px;
            padding: 10px 12px;
            border-radius: 8px;
            background: #eff6ff;
            color: #1e40af;
            border: 1px solid #93c5fd;
        }

        .code-info {
            margin: 18px 0;
            padding: 14px;
            border-radius: 10px;
            background: #f8fbff;
            color: #1e293b;
            border: 1px solid #dbe7ff;
        }

        .code-info strong {
            color: #1e3a8a;
        }

        .code-info-note {
            margin-top: 10px;
            color: #334155;
            line-height: 1.5;
        }

        .visible-code {
            margin: 18px 0;
            padding: 18px;
            border-radius: 12px;
            background: linear-gradient(135deg, #eff6ff, #f8fbff);
            border: 1px solid #bfdbfe;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.65);
        }

        .visible-code-label {
            margin: 0 0 10px;
            color: #1e3a8a;
            font-weight: 700;
            font-size: 15px;
        }

        .visible-code-value {
            margin-bottom: 12px;
            padding: 14px 16px;
            border: 1px solid #93c5fd;
            border-radius: 10px;
            background: #ffffff;
            color: #0f172a;
            font-size: 30px;
            font-weight: 700;
            letter-spacing: 0.18em;
            text-align: center;
            font-family: Consolas, "Courier New", monospace;
            user-select: all;
        }

        .visible-code-meta {
            color: #1e293b;
            line-height: 1.6;
        }

        .visible-code-note {
            margin-top: 10px;
            color: #334155;
            font-size: 13px;
        }

        .continue-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            margin-top: 14px;
            padding: 12px 16px;
            border-radius: 10px;
            background: #1d4ed8;
            color: #ffffff;
            text-decoration: none;
            font-weight: 600;
        }

        @media (max-width: 640px) {
            .code-card {
                width: min(520px, calc(100% - 20px));
                margin: 20px auto;
                padding: 20px 16px;
            }
        }
    </style>
</head>
<body class="kostick-uppercase">
    <div class="code-card">
        <h1>Código de acceso</h1>

        <?php if ($info !== ''): ?>
            <div class="info-box"><?= htmlspecialchars($info, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="error-box"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="user-summary">
            <strong>Usuario:</strong> <?= htmlspecialchars($pendingFullName, ENT_QUOTES, 'UTF-8') ?><br>
            <?php if ($pendingApellidoCasada !== ''): ?>
                <strong>Apellido de casada:</strong> <?= htmlspecialchars($pendingApellidoCasada, ENT_QUOTES, 'UTF-8') ?><br>
            <?php endif; ?>
            <strong>Edad:</strong> <?= htmlspecialchars($pendingAge, ENT_QUOTES, 'UTF-8') ?><br>
            <strong>CI:</strong> <?= htmlspecialchars($pendingCi !== '' ? $pendingCi : '-', ENT_QUOTES, 'UTF-8') ?><br>
            <strong>Profesion :</strong> <?= htmlspecialchars($pendingProfession, ENT_QUOTES, 'UTF-8') ?><br>
            <strong>Puesto:</strong> <?= htmlspecialchars($pendingPosition, ENT_QUOTES, 'UTF-8') ?>
        </div>

        <?php if ($showVisibleAccessCode): ?>
            <div class="visible-code">
                <p class="visible-code-label">Tu código de acceso es:</p>
                <div class="visible-code-value" tabindex="0" aria-label="Codigo de acceso vigente">
                    <?= htmlspecialchars((string)$visibleAccessCode['code'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div class="visible-code-meta">
                    <strong>Válido desde:</strong> <?= htmlspecialchars(format_access_code_datetime((string)$visibleAccessCode['fecha_inicio']), ENT_QUOTES, 'UTF-8') ?><br>
                    <strong>Hasta:</strong> <?= htmlspecialchars(format_access_code_datetime((string)$visibleAccessCode['fecha_fin']), ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div class="visible-code-note">
                    <?= htmlspecialchars($visibleAccessCodeValidityMessage, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div class="visible-code-note">
                    Selecciona y copia el código de acceso e ingrésalo al campo de validación de abajo.
                </div>
            </div>
            
            <div style="display: flex; justify-content: center; align-items: center;">
                <img src="../img/flechitas.gif" 
                    alt="Icono de información" 
                    style="width:160px; height:160px;">
            </div>
            
        <?php elseif ($visibleAccessCodeStatus === 'out_of_window'): ?>
            <div class="error-box">Código fuera de vigencia</div>
        <?php elseif ($visibleAccessCodeStatus === 'not_found'): ?>
            <div class="info-box"><?= htmlspecialchars($visibleAccessCodeMessage, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <?php if ($enteredAccessCode !== ''): ?>
            <div class="code-info">
                <strong>Código ingresado:</strong> <?= htmlspecialchars($enteredAccessCode, ENT_QUOTES, 'UTF-8') ?><br>
                <?php if (is_array($accessCodeInfo)): ?>
                    <strong>Fecha y hora de inicio:</strong> <?= htmlspecialchars(format_access_code_datetime((string)$accessCodeInfo['fecha_inicio']), ENT_QUOTES, 'UTF-8') ?><br>
                    <strong>Fecha y hora de fin:</strong> <?= htmlspecialchars(format_access_code_datetime((string)$accessCodeInfo['fecha_fin']), ENT_QUOTES, 'UTF-8') ?>
                    <div class="code-info-note">
                        <?= htmlspecialchars($accessCodeValidityMessage, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php else: ?>
                    <div class="code-info-note">
                        No se encontro informacion de vigencia para ese codigo.
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($showValidatedCode): ?>
            <a class="continue-link" href="test.php">Continuar al test</a>
            <script>
                window.setTimeout(function () {
                    window.location.href = 'test.php';
                }, 1400);
            </script>
        <?php elseif ($showVisibleAccessCode): ?>
            <form method="post" action="access_code.php">
                <input type="hidden" name="step" value="access_code">
                <label for="access_code">Codigo de acceso</label>
                <input
                    type="text"
                    id="access_code"
                    name="access_code"
                    maxlength="12"
                    autocomplete="off"
                    placeholder="Escribe el codigo mostrado arriba"
                    value="<?= htmlspecialchars($enteredAccessCode, ENT_QUOTES, 'UTF-8') ?>"
                    required
                >
                <button type="submit">Validar codigo</button>
            </form>
        <?php else: ?>
            <div class="info-box">No es posible continuar hasta que exista un codigo de acceso vigente.</div>
        <?php endif; ?>

        <div class="back-link">
            <a href="../index.php">Volver al inicio</a>
        </div>
    </div>
</body>
</html>
