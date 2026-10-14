<?php
require_once __DIR__ . '/includes/session.php';
app_start_session();
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/positions.php';
require __DIR__ . '/includes/user_identity.php';

ensure_positions_schema($conn);
ensure_user_identity_schema($conn);

$enabledPositions = get_enabled_positions($conn);
$hasEnabledPositions = !empty($enabledPositions);
$formError = (string)($_SESSION['form_error'] ?? '');
$formOld = isset($_SESSION['form_old']) && is_array($_SESSION['form_old'])
    ? $_SESSION['form_old']
    : [];

unset($_SESSION['form_error'], $_SESSION['form_old']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SISTEMA DIGITAL DEL TEST DE KOSTICK</title>
    <link rel="stylesheet" href="css/styles.css">
    <link rel="stylesheet" href="css/registration.css">
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
</head>
<body class="registration-page kostick-uppercase">
<main class="registration-shell">
    <aside class="registration-brand">
        <img
            class="registration-brand-image"
            src="img/image.png"
            alt="Mi Teleférico, uniendo nuestras vidas en La Paz"
        >
    </aside>

    <section class="registration-content" aria-labelledby="registration-title">
        <header class="registration-header">
            <div class="registration-title-group">
                <h1 id="registration-title">Sistema Digital del Test de Kostick</h1>
                <p>Evaluación psicológica de uso académico</p>
            </div>
            <div class="registration-logo-lockup">
                <span class="registration-spectrum" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></span>
                <img src="img/mi-teleferico-logo.png" alt="Mi Teleférico · Uniendo nuestras vidas">
            </div>
        </header>

        <div class="registration-section-heading">
            <span class="user-heading-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"/><path d="M5.5 20v-1.7a6.5 6.5 0 0 1 13 0V20z"/></svg></span>
            <h2>Datos del usuario</h2>
            <span class="heading-rule" aria-hidden="true"></span>
        </div>

        <?php if ($formError !== ''): ?>
            <div class="field-error"><?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form id="registration-form" action="pages/access_code.php" method="post" novalidate>
            <input type="hidden" name="step" value="captcha">

            <div class="form-row surname-row">
                <div class="field">
                    <label for="apellido_paterno">Apellido paterno</label>
                    <input
                        type="text"
                        id="apellido_paterno"
                        name="apellido_paterno"
                        placeholder="Ej. Perez"
                        value="<?= htmlspecialchars((string)($formOld['apellido_paterno'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        autocomplete="additional-name"
                        required
                    >
                </div>

                <div class="field">
                    <label for="apellido_materno">Apellido materno</label>
                    <input
                        type="text"
                        id="apellido_materno"
                        name="apellido_materno"
                        placeholder="Ej. Gomez"
                        value="<?= htmlspecialchars((string)($formOld['apellido_materno'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        autocomplete="family-name"
                        required
                    >
                </div>
            </div>

            <div class="field">
                <label for="apellido_casada">Apellido de casada</label>
                <input
                    type="text"
                    id="apellido_casada"
                    name="apellido_casada"
                    placeholder="Ej. de Rodriguez"
                    value="<?= htmlspecialchars((string)($formOld['apellido_casada'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    autocomplete="family-name"
                >
                <div class="field-note">Opcional</div>
            </div>

            <div class="form-row name-age-row">
                <div class="field">
                    <label for="name">Nombres</label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        placeholder="Ej. Juan"
                        value="<?= htmlspecialchars((string)($formOld['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        autocomplete="given-name"
                        required
                    >
                </div>

                <div class="field">
                    <label for="age">Edad</label>
                    <input
                        type="number"
                        id="age"
                        name="age"
                        min="1"
                        placeholder="Ej. 25"
                        value="<?= htmlspecialchars((string)($formOld['age'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        required
                    >
                </div>
            </div>

            <div class="field">
                <label for="profession">Profesion</label>
                <input
                    type="text"
                    id="profession"
                    name="profession"
                    placeholder="Ej. Analista de sistemas"
                    value="<?= htmlspecialchars((string)($formOld['profession'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                >
            </div>

            <div class="field">
                <label for="ci_numero">CI</label>
                <div class="ci-grid">
                    <input
                        type="text"
                        id="ci_numero"
                        name="ci_numero"
                        inputmode="numeric"
                        maxlength="20"
                        pattern="[0-9]{4,20}"
                        placeholder="Numero de carnet"
                        value="<?= htmlspecialchars((string)($formOld['ci_numero'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        required
                    >
                    <input
                        type="text"
                        id="ci_complemento"
                        name="ci_complemento"
                        maxlength="10"
                        pattern="[A-Za-z0-9]{1,10}"
                        placeholder="Complemento"
                        value="<?= htmlspecialchars((string)($formOld['ci_complemento'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    >
                </div>
                <div class="field-note">
                    Numero obligatorio y complemento opcional. Ejemplos: 12345678 o 12345678-1A.
                </div>
                <div id="ci-feedback" class="ci-feedback" aria-live="polite"></div>
            </div>

            <div class="field">
                <label for="position_id">Puesto</label>
                <select id="position_id" name="position_id" <?= $hasEnabledPositions ? 'required' : 'disabled' ?>>
                    <?php if ($hasEnabledPositions): ?>
                        <option value="">SELECCIONE UN PUESTO</option>
                        <?php foreach ($enabledPositions as $position): ?>
                            <option
                                value="<?= (int)$position['id'] ?>"
                                <?= (string)($formOld['position_id'] ?? '') === (string)$position['id'] ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars(app_to_upper((string)$position['name']), ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="">NO HAY PUESTOS HABILITADOS</option>
                    <?php endif; ?>
                </select>
                <div class="field-note">
                    <?php if ($hasEnabledPositions): ?>
                        Selecciona uno de los puestos habilitados por administracion.
                    <?php else: ?>
                        No hay puestos habilitados. Debes crear o habilitar al menos uno desde administracion.
                    <?php endif; ?>
                </div>
            </div>

            <div class="field">
                <label>Verificacion de seguridad</label>
                <div class="cf-turnstile" data-sitekey="<?= htmlspecialchars(TURNSTILE_SITE_KEY, ENT_QUOTES, 'UTF-8') ?>"></div>
            </div>

            <button type="submit" <?= $hasEnabledPositions ? '' : 'disabled' ?>>Continuar</button>
        </form>

        <p class="admin-access-link"><a href="pages/admin_login.php">Ir al panel de administrador</a></p>

        <div class="legal">
            <p>
                Este sistema es <strong>libre de derechos de autor</strong> y ha sido desarrollado con fines
                <strong>academicos y educativos</strong>.
                <br>
                <strong>No esta destinado para uso comercial</strong>.
            </p>
        </div>
    </section>
</main>
</body>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('registration-form');
    const ciNumeroInput = document.getElementById('ci_numero');
    const ciComplementoInput = document.getElementById('ci_complemento');
    const ciFeedback = document.getElementById('ci-feedback');
    let lastValidationKey = '';
    let lastValidationResult = null;

    function normalizeCiNumero(value) {
        return value.replace(/\s+/g, '');
    }

    function normalizeCiComplemento(value) {
        return value.replace(/[\s-]+/g, '').toUpperCase();
    }

    function setFeedback(message, type) {
        ciFeedback.textContent = message;
        ciFeedback.className = 'ci-feedback' + (type ? ' ' + type : '');
    }

    function clearCiValidity() {
        ciNumeroInput.setCustomValidity('');
        ciComplementoInput.setCustomValidity('');
    }

    function validateCiFormat(showFeedback) {
        const numero = normalizeCiNumero(ciNumeroInput.value);
        const complemento = normalizeCiComplemento(ciComplementoInput.value);

        ciNumeroInput.value = numero;
        ciComplementoInput.value = complemento;
        clearCiValidity();

        if (!/^\d{4,20}$/.test(numero)) {
            const message = 'EL NUMERO DE CI ES OBLIGATORIO Y SOLO PUEDE CONTENER DIGITOS.';
            ciNumeroInput.setCustomValidity(message);
            if (showFeedback) {
                setFeedback(message, 'error');
            }
            return false;
        }

        if (complemento !== '' && !/^[A-Z0-9]{1,10}$/.test(complemento)) {
            const message = 'EL COMPLEMENTO DEL CI SOLO PUEDE CONTENER LETRAS Y NUMEROS.';
            ciComplementoInput.setCustomValidity(message);
            if (showFeedback) {
                setFeedback(message, 'error');
            }
            return false;
        }

        if (showFeedback) {
            setFeedback(
                'FORMATO DETECTADO: ' + numero + (complemento !== '' ? '-' + complemento : ''),
                'success'
            );
        }

        return true;
    }

    async function validateCiAvailability(showFeedback) {
        if (!validateCiFormat(showFeedback)) {
            return false;
        }

        const numero = ciNumeroInput.value;
        const complemento = ciComplementoInput.value;
        const validationKey = numero + '|' + complemento;

        if (validationKey === lastValidationKey && lastValidationResult !== null) {
            clearCiValidity();

            if (!lastValidationResult.valid) {
                ciNumeroInput.setCustomValidity(lastValidationResult.message || 'CI INVALIDO.');
                if (showFeedback) {
                    setFeedback(lastValidationResult.message || 'CI INVALIDO.', 'error');
                }
                return false;
            }

            if (showFeedback) {
                setFeedback(
                    lastValidationResult.message || ('CI VALIDO: ' + lastValidationResult.formatted),
                    'success'
                );
            }
            return true;
        }

        if (showFeedback) {
            setFeedback('VALIDANDO CI...', '');
        }

        try {
            const params = new URLSearchParams({ ci_numero: numero });
            if (complemento !== '') {
                params.set('ci_complemento', complemento);
            }

            const response = await fetch('pages/validate_ci.php?' + params.toString(), {
                headers: { 'Accept': 'application/json' }
            });

            if (!response.ok) {
                throw new Error('validation-request-failed');
            }

            const data = await response.json();
            lastValidationKey = validationKey;
            lastValidationResult = data;
            clearCiValidity();

            if (!data.valid) {
                ciNumeroInput.setCustomValidity(data.message || 'CI INVALIDO.');
                if (showFeedback) {
                    setFeedback(data.message || 'CI INVALIDO.', 'error');
                }
                return false;
            }

            if (showFeedback) {
                setFeedback(data.message || ('CI VALIDO: ' + data.formatted), 'success');
            }

            return true;
        } catch (error) {
            lastValidationKey = '';
            lastValidationResult = null;
            clearCiValidity();

            if (showFeedback) {
                setFeedback(
                    'NO SE PUDO VALIDAR EL CI EN ESTE MOMENTO. EL SERVIDOR LO REVISARA AL ENVIAR.',
                    ''
                );
            }

            return true;
        }
    }

    function resetCiValidationCache() {
        lastValidationKey = '';
        lastValidationResult = null;
        clearCiValidity();
    }

    ciNumeroInput.addEventListener('input', () => {
        resetCiValidationCache();
        validateCiFormat(false);
    });

    ciComplementoInput.addEventListener('input', () => {
        ciComplementoInput.value = normalizeCiComplemento(ciComplementoInput.value);
        resetCiValidationCache();
        if (ciNumeroInput.value.trim() !== '') {
            validateCiFormat(false);
        }
    });

    ciNumeroInput.addEventListener('blur', () => {
        if (ciNumeroInput.value.trim() !== '') {
            validateCiAvailability(true);
        }
    });

    ciComplementoInput.addEventListener('blur', () => {
        if (ciNumeroInput.value.trim() !== '') {
            validateCiAvailability(true);
        }
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        const isCiValid = await validateCiAvailability(true);
        if (!isCiValid) {
            form.reportValidity();
            return;
        }

        form.submit();
    });

    if (ciNumeroInput.value.trim() !== '') {
        validateCiFormat(false);
    }
});
</script>
</html>
