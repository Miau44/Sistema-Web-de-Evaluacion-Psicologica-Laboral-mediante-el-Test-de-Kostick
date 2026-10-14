<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/questions.php';
require __DIR__ . '/../includes/positions.php';
require_once __DIR__ . '/../includes/user_identity.php';

ensure_positions_schema($conn);
ensure_user_identity_schema($conn);

function redirect_test_registration_error(string $errorMessage, array $pendingUser = []): void
{
    $_SESSION['form_error'] = $errorMessage;
    $_SESSION['form_old'] = [
        'name' => (string)($pendingUser['name'] ?? ''),
        'apellido_paterno' => (string)($pendingUser['apellido_paterno'] ?? ''),
        'apellido_materno' => (string)($pendingUser['apellido_materno'] ?? ''),
        'apellido_casada' => (string)($pendingUser['apellido_casada'] ?? ''),
        'age' => isset($pendingUser['age']) ? (string)$pendingUser['age'] : '',
        'profession' => (string)($pendingUser['profession'] ?? ''),
        'ci_numero' => (string)($pendingUser['ci_numero'] ?? ''),
        'ci_complemento' => (string)($pendingUser['ci_complemento'] ?? ''),
        'position_id' => isset($pendingUser['position_id']) ? (string)$pendingUser['position_id'] : '',
    ];

    unset(
        $_SESSION['pending_user'],
        $_SESSION['captcha_verified'],
        $_SESSION['captcha_verified_at'],
        $_SESSION['access_code_granted'],
        $_SESSION['access_code_granted_at'],
        $_SESSION['access_code_id'],
        $_SESSION['validated_access_code'],
        $_SESSION['test_id'],
        $_SESSION['start_time'],
        $_SESSION['tiempo_total']
    );

    header('Location: ../index.php');
    exit;
}

if (!isset($_SESSION['test_id'])) {
    $pendingUser = $_SESSION['pending_user'] ?? null;
    $captchaOk = !empty($_SESSION['captcha_verified']);
    $accessGranted = !empty($_SESSION['access_code_granted']);

    if (!$captchaOk || !$accessGranted || !is_array($pendingUser)) {
        header('Location: ../index.php');
        exit;
    }

    $name = trim((string)($pendingUser['name'] ?? ''));
    $apellidoPaterno = trim((string)($pendingUser['apellido_paterno'] ?? ''));
    $apellidoMaterno = trim((string)($pendingUser['apellido_materno'] ?? ''));
    $apellidoCasada = trim((string)($pendingUser['apellido_casada'] ?? ''));
    $age = (int)($pendingUser['age'] ?? 0);
    $profession = trim((string)($pendingUser['profession'] ?? ''));
    $ciNumero = normalize_ci_numero((string)($pendingUser['ci_numero'] ?? ''));
    $ciComplemento = normalize_ci_complemento(
        isset($pendingUser['ci_complemento']) ? (string)$pendingUser['ci_complemento'] : null
    );
    $positionId = (int)($pendingUser['position_id'] ?? 0);
    $positionName = trim((string)($pendingUser['position_name'] ?? ''));

    if ($age < 1 || $positionId < 1 || $positionName === '' || $ciNumero === '') {
        die('ERROR: DATOS DEL USUARIO INVALIDOS.');
    }

    try {
        $name = validate_user_name_part($name, 'NOMBRE');
        $apellidoPaterno = validate_user_name_part($apellidoPaterno, 'APELLIDO PATERNO');
        $apellidoMaterno = validate_user_name_part($apellidoMaterno, 'APELLIDO MATERNO');
        $apellidoCasada = validate_optional_user_name_part($apellidoCasada, 'APELLIDO DE CASADA');
        $ciNumero = validate_ci_numero($ciNumero);
        $ciComplemento = validate_ci_complemento($ciComplemento);
    } catch (InvalidArgumentException $e) {
        redirect_test_registration_error($e->getMessage(), $pendingUser);
    }

    try {
        $conn->beginTransaction();

        $isMysql = $conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $userInsertSql = "INSERT INTO users (
                name,
                apellido_paterno,
                apellido_materno,
                apellido_casada,
                age,
                profession,
                ci_numero,
                ci_complemento,
                position_id,
                position_name
            )
             VALUES (
                :name,
                :apellido_paterno,
                :apellido_materno,
                :apellido_casada,
                :age,
                :profession,
                :ci_numero,
                :ci_complemento,
                :position_id,
                :position_name
             )";
        if (!$isMysql) {
            $userInsertSql .= ' RETURNING id';
        }
        $stmt = $conn->prepare($userInsertSql);
        $stmt->bindValue(':name', $name, PDO::PARAM_STR);
        $stmt->bindValue(':apellido_paterno', $apellidoPaterno, PDO::PARAM_STR);
        $stmt->bindValue(':apellido_materno', $apellidoMaterno, PDO::PARAM_STR);
        $stmt->bindValue(
            ':apellido_casada',
            $apellidoCasada,
            $apellidoCasada !== null ? PDO::PARAM_STR : PDO::PARAM_NULL
        );
        $stmt->bindValue(':age', $age, PDO::PARAM_INT);
        $stmt->bindValue(
            ':profession',
            $profession !== '' ? $profession : null,
            $profession !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL
        );
        $stmt->bindValue(':ci_numero', $ciNumero, PDO::PARAM_STR);
        $stmt->bindValue(
            ':ci_complemento',
            $ciComplemento,
            $ciComplemento !== null ? PDO::PARAM_STR : PDO::PARAM_NULL
        );
        $stmt->bindValue(':position_id', $positionId, PDO::PARAM_INT);
        $stmt->bindValue(':position_name', $positionName, PDO::PARAM_STR);
        $stmt->execute();
        $user_id = $isMysql ? (int)$conn->lastInsertId() : (int)$stmt->fetchColumn();

        $start_datetime = date('Y-m-d H:i:s');
        $testInsertSql = "INSERT INTO tests (user_id, start_datetime)
                          VALUES (:user_id, :start_datetime)";
        if (!$isMysql) {
            $testInsertSql .= ' RETURNING id';
        }
        $stmt = $conn->prepare($testInsertSql);
        $stmt->execute([
            ':user_id' => $user_id,
            ':start_datetime' => $start_datetime,
        ]);
        $test_id = $isMysql ? (int)$conn->lastInsertId() : (int)$stmt->fetchColumn();

        $conn->commit();
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        error_log('[KOSTICK] Error al registrar usuario: ' . $e->getMessage());
        die('ERROR: NO SE PUDO REGISTRAR AL USUARIO.');
    }

    $_SESSION['test_id'] = $test_id;
    $_SESSION['start_time'] = time();
    $_SESSION['name'] = $name;
    $_SESSION['apellido_paterno'] = $apellidoPaterno;
    $_SESSION['apellido_materno'] = $apellidoMaterno;
    $_SESSION['apellido_casada'] = $apellidoCasada;
    $_SESSION['age'] = $age;
    $_SESSION['profession'] = $profession;
    $_SESSION['ci_numero'] = $ciNumero;
    $_SESSION['ci_complemento'] = $ciComplemento;
    $_SESSION['position_name'] = $positionName;

    unset(
        $_SESSION['pending_user'],
        $_SESSION['captcha_verified'],
        $_SESSION['captcha_verified_at'],
        $_SESSION['access_code_granted'],
        $_SESSION['access_code_granted_at'],
        $_SESSION['access_code_id'],
        $_SESSION['validated_access_code']
    );
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TEST DE KOSTICK - PREGUNTAS</title>
    <link rel="stylesheet" href="../css/styles.css">

    <style>
    /* PREGUNTAS EN BLANCO */
        .question p {
            color: #ffffff;
            font-size: 20px;      /* Tamaño más grande */
            font-weight: 700;     /* Más grueso */
        }
        /* Indicador A/B */
        .selected-indicator {
            margin-top: 10px;
            font-weight: bold;
            padding: 6px 10px;
            color: #ffffffff;
            opacity: 0;
            transform: scale(0.8);
            transition: opacity .3s ease, transform .3s ease;

            display: inline-block;
            max-width: 100%;
            width: fit-content;

            word-wrap: break-word;
            word-break: break-word;
            overflow-wrap: break-word;
            white-space: normal;

            overflow: hidden;
            text-overflow: ellipsis;

            opacity: 0;
            transition: opacity 0.3s ease-in-out;
        }
        .selected-indicator.show {
            opacity: 1;
            transform: scale(1.2);
        }

        /* Timer */
        #timer-box {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #b4bffaff;
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 22px;
            font-weight: bold;
            box-shadow: 0 0 10px rgba(0,0,0,0.2);
        }

        @media (max-width: 768px) {
            body {
                padding-bottom: 84px;
            }

            .selected-indicator {
                font-size: 14px;
                padding: 6px 8px;
            }
        }
    </style>

    <script>
        function validateForm() {
            const questions = document.querySelectorAll(".question");
            const warningBox = document.getElementById("warning-box");

            let unanswered = [];

            questions.forEach((q, index) => {
                const name = "q" + (index + 1);
                const selected = document.querySelector(`input[name="${name}"]:checked`);

                if (!selected) {
                    unanswered.push(index + 1);
                }
            });

            if (unanswered.length > 0) {
                warningBox.style.display = "block";

                warningBox.innerHTML = `
                    <strong>⚠️ Faltan preguntas por responder (${unanswered.length}):</strong><br>
                    ${unanswered.map(n => `<div class="warning-item">• Pregunta ${n}</div>`).join('')}
                `;

                warningBox.classList.remove("show");
                setTimeout(() => warningBox.classList.add("show"), 20);

                window.scrollTo({ top: 0, behavior: "smooth" });

                return false;
            }

            return true;
        }
    </script>
</head>
<body class="kostick-uppercase">

    <!-- TIMER -->
    <div id="timer-box">
        <span id="timer">00:00</span>
    </div>

    <div class="container">
        <h1>Test de Kostick</h1>
        <div id="warning-box" class="warning-box" style="display:none;">
            <h3>⛔ Faltan preguntas por responder</h3>
            <p>Antes de enviar, por favor completa las siguientes preguntas:</p>
            <ul id="missing-list"></ul>
        </div>

        <div class="instructions">
            <p>
                Instrucciones:<br><br>
                Hay 90 pares de frases, usted debe elegir de cada par, aquella que más 
                se asemeje a su forma de ser o de pensar. A veces tendrá la impresión de que ninguna 
                frases se asemeja a su manera de ser, o al contrario, que ambas lo hacen, en cualquier 
                caso usted debe optar por una de las dos. 
                Lea a continuación cada una de las afirmaciones y conteste en su hoja de 
                respuestas de acuerdo a sus preferencias.  La prueba esta hecha para un maximo de 15 min, sea espontáneo y sincero 
                con  sus respuestas.  
                </p>
        </div>

        <!-- FORM -->
        <form id="test-form" action="results.php" method="post" onsubmit="return validateForm()">

            <?php foreach ($preguntas as $q): ?>
                <div class="question">
                    <p><?php echo $q['id'] . '. A: ' . $q['a'] . ' | B: ' . $q['b']; ?></p>

                    <div class="options">
                        <input type="radio" id="q<?= $q['id'] ?>a" name="q<?= $q['id'] ?>" value="A">
                        <label for="q<?= $q['id'] ?>a">A</label>

                        <input type="radio" id="q<?= $q['id'] ?>b" name="q<?= $q['id'] ?>" value="B">
                        <label for="q<?= $q['id'] ?>b">B</label>
                    </div>

                    <!-- Indicador animado -->
                    <div class="selected-indicator"></div>
                </div>
            <?php endforeach; ?>

            <!-- tiempo final (mm:ss) -->
            <input type="hidden" id="tiempo_final" name="tiempo_final" value="00:00">

            <button type="submit">Enviar Respuestas</button>
        </form>
    </div>

    <!-- Script Indicador Elegido -->
    <script>
    document.addEventListener("DOMContentLoaded", () => {
        const radios = document.querySelectorAll(".options input[type='radio']");

        radios.forEach(radio => {
            radio.addEventListener("change", () => {
                const question = radio.closest(".question");
                const indicator = question.querySelector(".selected-indicator");

                indicator.textContent = "ELEGISTE: " + radio.value.toUpperCase();

                indicator.classList.remove("show");
                setTimeout(() => indicator.classList.add("show"), 50);
            });
        });
    });
    </script>

    <!-- Timer + enviar tiempo -->
    <script>
    document.addEventListener('DOMContentLoaded', () => {

        let startTime = <?php echo (int)($_SESSION['start_time'] ?? time()); ?>;

        function updateTimer() {
            let now = Math.floor(Date.now() / 1000);
            let elapsed = now - startTime;

            let minutes = Math.floor(elapsed / 60);
            let seconds = elapsed % 60;

            if (minutes < 10) minutes = "0" + minutes;
            if (seconds < 10) seconds = "0" + seconds;

            document.getElementById('timer').textContent = minutes + ":" + seconds;
        }

        updateTimer();
        setInterval(updateTimer, 1000);

        // Enviar tiempo final al backend
        const form = document.getElementById('test-form');
        const timerEl = document.getElementById('timer');
        const inputTiempo = document.getElementById('tiempo_final');

        form.addEventListener('submit', () => {
            inputTiempo.value = timerEl.textContent.trim();
        });
    });
    </script>

</body>
</html>
