<?php
session_start();
header('Content-Type: text/html; charset=UTF-8');

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/questions.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/admin_auth.php';
require __DIR__ . '/../includes/positions.php';
require_once __DIR__ . '/../includes/user_identity.php';

ensure_positions_schema($conn);
ensure_user_identity_schema($conn);

$all_scales = ['A','B','C','D','E','F','G','I','K','L','N','O','P','R','S','T','V','W','X','Z'];
$scores = array_fill_keys($all_scales, 0);
$levels = [];

$is_admin_view = ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['test_id']));
$duration = null;
$end_datetime = null;
$tiempo_total = "00:00";
$test_id_for_view = 0;
$display_name = '';
$display_age = '';
$display_profession = '';
$display_apellido_casada = '';
$display_ci = '';
$display_position = '';

if ($is_admin_view && !is_admin_authenticated()) {
    header('Location: admin_login.php');
    exit;
}

if ($is_admin_view) {
    $test_id_for_view = (int)($_GET['test_id'] ?? 0);
    if ($test_id_for_view < 1) {
        die('TEST INVALIDO.');
    }

    $meta_stmt = $conn->prepare(
        "SELECT t.id, t.end_datetime, t.duration, u.name, u.apellido_paterno, u.apellido_materno, u.apellido_casada, u.age, u.profession, u.ci_numero, u.ci_complemento, u.position_name
         FROM tests t
         INNER JOIN users u ON u.id = t.user_id
         WHERE t.id = :test_id
         LIMIT 1"
    );
    $meta_stmt->execute([':test_id' => $test_id_for_view]);
    $meta = $meta_stmt->fetch();

    if (!$meta) {
        die('NO SE ENCONTRO EL TEST SOLICITADO.');
    }

    $display_name = build_user_full_name(
        (string)$meta['name'],
        isset($meta['apellido_paterno']) ? (string)$meta['apellido_paterno'] : null,
        isset($meta['apellido_materno']) ? (string)$meta['apellido_materno'] : null
    );
    $display_age = (string)$meta['age'];
    $display_profession = (string)($meta['profession'] ?? '');
    $display_apellido_casada = normalize_user_name_part((string)($meta['apellido_casada'] ?? ''));
    $display_ci = format_user_ci(
        (string)($meta['ci_numero'] ?? ''),
        isset($meta['ci_complemento']) ? (string)$meta['ci_complemento'] : null
    );
    $display_position = (string)($meta['position_name'] ?? '');
    $duration = $meta['duration'] ? (string)$meta['duration'] : null;
    $end_datetime = $meta['end_datetime'] ? (string)$meta['end_datetime'] : date('Y-m-d H:i:s');

    if ($duration && preg_match('/^(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?$/', $duration, $matches)) {
        $minutes = ((int)$matches[1] * 60) + (int)$matches[2];
        $seconds = (int)$matches[3];
        $tiempo_total = sprintf('%02d:%02d', $minutes, $seconds);
    }

    $results_stmt = $conn->prepare("SELECT scale, score, level FROM results WHERE test_id = :test_id");
    $results_stmt->execute([':test_id' => $test_id_for_view]);

    $has_results = false;
    while ($row = $results_stmt->fetch()) {
        $scale = (string)$row['scale'];
        if (!array_key_exists($scale, $scores)) {
            continue;
        }
        $has_results = true;
        $scores[$scale] = (int)$row['score'];
        $levels[$scale] = (string)$row['level'];
    }

    if (!$has_results) {
        die('NO HAY RESULTADOS DISPONIBLES PARA ESTE TEST.');
    }
} else {
    if (!isset($_SESSION['test_id'])) {
        header('Location: ../index.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: test.php');
        exit;
    }

    if (isset($_POST['tiempo_final'])) {
        $tiempo_post = trim($_POST['tiempo_final']);
        if (preg_match('/^\d{1,2}:\d{2}$/', $tiempo_post)) {
            $_SESSION['tiempo_total'] = $tiempo_post;
        } else {
            $_SESSION['tiempo_total'] = "00:00";
        }
    }

    $tiempo_total = isset($_SESSION['tiempo_total']) ? (string)$_SESSION['tiempo_total'] : "00:00";

    $answers = $_POST;
    $missing = [];
    foreach ($preguntas as $q) {
        if (!isset($answers['q' . $q['id']])) {
            $missing[] = $q['id'];
        }
    }

    if (!empty($missing)) {
        $ids = implode(', ', $missing);
        die("ERROR: FALTAN RESPUESTAS PARA LOS ITEMS: $ids. POR FAVOR RESPONDA TODAS LAS PREGUNTAS.");
    }

    $result = calculate_scores($answers, $preguntas);
    $scores = $result['scores'];
    $levels = $result['levels'];

    $left_scales = ['G','L','I','T','V','S','R','C','E','D'];
    $right_scales = ['N','A','P','X','B','O','Z','K','F','W'];

    $sumLeft = 0;
    $sumRight = 0;
    foreach ($left_scales as $l) {
        $sumLeft += ($scores[$l] ?? 0);
    }
    foreach ($right_scales as $r) {
        $sumRight += ($scores[$r] ?? 0);
    }

    if ($sumLeft !== 45 || $sumRight !== 45) {
        $msg = "Validacion 45/45 fallida. Izquierda = $sumLeft, Derecha = $sumRight.";
        error_log("[KOSTICK] $msg Test ID: " . $_SESSION['test_id']);
        die("ERROR EN LA VALIDACION DEL TEST: $msg");
    }

    $end_datetime = date('Y-m-d H:i:s');
    $start_time = isset($_SESSION['start_time']) ? (int)$_SESSION['start_time'] : 0;
    if ($start_time > 0) {
        $duration_seconds = time() - $start_time;
        $duration = gmdate('H:i:s', $duration_seconds);
    }

    $test_id_for_view = (int)$_SESSION['test_id'];

    $stmt = $conn->prepare("UPDATE tests SET end_datetime = ?, duration = ? WHERE id = ?");
    $stmt->execute([
        $end_datetime,
        $duration,
        $test_id_for_view
    ]);

    $delete_stmt = $conn->prepare("DELETE FROM results WHERE test_id = ?");
    $delete_stmt->execute([$test_id_for_view]);

    foreach ($scores as $scale => $score) {
        $level = $levels[$scale];
        $stmt = $conn->prepare("INSERT INTO results (test_id, scale, score, level) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $test_id_for_view,
            $scale,
            (int)$score,
            $level
        ]);
    }

    $display_name = build_user_full_name(
        (string)($_SESSION['name'] ?? ''),
        isset($_SESSION['apellido_paterno']) ? (string)$_SESSION['apellido_paterno'] : null,
        isset($_SESSION['apellido_materno']) ? (string)$_SESSION['apellido_materno'] : null
    );
    $display_age = (string)($_SESSION['age'] ?? '');
    $display_profession = (string)($_SESSION['profession'] ?? '');
    $display_apellido_casada = normalize_user_name_part((string)($_SESSION['apellido_casada'] ?? ''));
    $display_ci = format_user_ci(
        (string)($_SESSION['ci_numero'] ?? ''),
        isset($_SESSION['ci_complemento']) ? (string)$_SESSION['ci_complemento'] : null
    );
    $display_position = (string)($_SESSION['position_name'] ?? '');
}

foreach ($scores as $scale => $score) {
    if (!isset($levels[$scale])) {
        if ($score <= 2) {
            $levels[$scale] = 'bajo';
        } elseif ($score <= 5) {
            $levels[$scale] = 'promedio';
        } else {
            $levels[$scale] = 'alto';
        }
    }
}

if (isset($_GET['export']) && $_GET['export'] == 'json') {
    $data = [
        'testId' => $test_id_for_view,
        'nombreCompleto' => $display_name,
        'puntuaciones' => $scores,
        'nivel' => $levels,
        'interpretacionDetallada' => 'Basado en los niveles: bajo (0-2), promedio (3-5), alto (6-9).',
        'tiempoRespuesta' => $duration,
        'fecha' => $end_datetime,
        'ci' => $display_ci,
        'apellidoCasada' => $display_apellido_casada,
        'profesion' => $display_profession,
        'puesto' => $display_position
    ];
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="kostick_results.json"');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TEST DE KOSTICK - RESULTADOS</title>
    <link rel="stylesheet" href="../css/styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Rusca:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body.results-page {
            margin: 0;
            overflow-x: hidden;
            background:
                radial-gradient(circle at top left, rgba(96, 165, 250, 0.18), transparent 28%),
                radial-gradient(circle at top right, rgba(37, 99, 235, 0.12), transparent 24%),
                linear-gradient(180deg, #f5f9ff 0%, #edf4ff 100%);
        }

        .results-page .main-container {
            width: min(1220px, calc(100% - 24px));
            margin: 16px auto 32px;
        }

        .results-page .results {
            margin-top: 0;
            padding: 24px 20px 28px;
            border-radius: 20px;
            border: 1px solid #c8dbff;
            background: rgba(255, 255, 255, 0.94);
            box-shadow: 0 20px 45px rgba(29, 78, 216, 0.10);
        }

        .results-page h1,
        .results-page h2,
        .results-page h3 {
            text-wrap: balance;
        }

        .results-page h1 {
            margin: 0 0 10px;
            font-size: clamp(1.7rem, 3vw, 2.4rem);
        }

        .results-page .results-admin-back {
            text-align: center;
            margin: 6px 0 18px;
        }

        .results-page .results-admin-back a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border-radius: 999px;
            text-decoration: none;
            color: #1d4ed8;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            font-weight: 600;
        }

        .results-page .user-info-card {
            margin-bottom: 28px;
            padding: 20px 18px;
            border-radius: 18px;
            border: 1px solid #dbe7ff;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            box-shadow: 0 12px 28px rgba(37, 99, 235, 0.08);
        }

        .results-page .user-info-card h2 {
            margin: 0 0 14px;
            font-size: 1.2rem;
            text-align: left;
        }

        .results-page .user-info-row {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }

        .results-page .user-info-item {
            min-width: 0;
            padding: 14px 12px;
            border-radius: 14px;
            background: linear-gradient(180deg, #dff2ff 0%, #b7e3ff 100%);
            border: 1px solid #9fd4ff;
            box-shadow: none;
        }

        .results-page .user-info-item .label {
            margin-bottom: 6px;
            color: #1e3a8a;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .results-page .user-info-item .value {
            color: #0f172a;
            font-size: 15px;
            line-height: 1.45;
            overflow-wrap: anywhere;
        }

        .results-page .results-section-title {
            margin: 0 0 14px;
            text-align: left;
            font-size: clamp(1.25rem, 2.5vw, 1.7rem);
        }

        .results-page .tabla-kostick {
            gap: 14px;
        }

        .results-page .tabla-kostick > h3 {
            margin: 10px 0 2px;
            padding: 0 2px;
            color: #1e3a8a;
            font-size: 1.05rem;
            text-align: left;
        }

        .results-page .fila-header,
        .results-page .fila-factor {
            display: grid;
            grid-template-columns: minmax(150px, 1.1fr) minmax(0, 1.25fr) minmax(180px, 0.9fr) minmax(0, 1.25fr);
            gap: 14px;
            align-items: center;
        }

        .results-page .fila-header {
            padding: 14px 16px;
            border-radius: 14px;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            box-shadow: 0 10px 22px rgba(37, 99, 235, 0.18);
        }

        .results-page .fila-header .col {
            font-size: 12px;
            letter-spacing: 0.05em;
        }

        .results-page .fila-factor {
            padding: 16px;
            border-radius: 16px;
            border: 1px solid #dbe7ff;
            background: #ffffff;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.06);
        }

        .results-page .fila-factor .col {
            min-width: 0;
            overflow-wrap: anywhere;
        }

        .results-page .col.factor strong {
            font-size: 24px;
            font-weight: 800;
            color: #1d4ed8;
        }

        .results-page .col.factor span {
            display: inline-block;
            margin-top: 2px;
            color: #334155;
            line-height: 1.45;
        }

        .results-page .col.bajo,
        .results-page .col.alto {
            font-size: 14px;
            line-height: 1.55;
            color: #334155;
        }

        .results-page .col.alto {
            text-align: left !important;
        }

        .results-page .barra-base {
            width: 100%;
            height: 14px;
            margin-bottom: 8px;
            background: #dbeafe;
            border: 1px solid #bfdbfe;
            border-radius: 999px;
        }

        .results-page .barra-fill {
            background: linear-gradient(90deg, #60a5fa, #2563eb);
            border-radius: 999px;
        }

        .results-page .puntaje {
            text-align: center;
            font-size: 13px;
            color: #1e3a8a;
        }

        .results-page .kostick-charts-container {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            margin-top: 28px;
        }

        .results-page .kostick-chart-container,
        .results-page .kostick-pie-container {
            padding: 18px;
            border-radius: 18px;
            border: 1px solid #dbe7ff;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            box-shadow: 0 10px 26px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }

        .results-page .kostick-chart-container h2,
        .results-page .kostick-pie-container h2 {
            margin: 0 0 12px;
            text-align: left;
            font-size: 1.05rem;
        }

        .results-page .kostick-chart-container canvas,
        .results-page .kostick-pie-container canvas {
            display: block;
            width: 100% !important;
            height: 320px !important;
        }

        .results-page #exportForm {
            margin-top: 24px;
            display: flex;
            justify-content: center;
        }

        .results-page #exportForm button {
            width: min(100%, 320px);
            margin-top: 0;
        }

        @media (max-width: 900px) {
            .results-page .user-info-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .results-page .fila-header,
            .results-page .fila-factor {
                grid-template-columns: minmax(120px, 1fr) minmax(0, 1fr) minmax(160px, 0.8fr) minmax(0, 1fr);
            }

            .results-page .kostick-charts-container {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .results-page .main-container {
                width: min(1220px, calc(100% - 16px));
                margin: 10px auto 20px;
            }

            .results-page .results {
                padding: 16px 12px 20px;
                border-radius: 16px;
            }

            .results-page .results-admin-back {
                margin-bottom: 14px;
            }

            .results-page .user-info-card {
                padding: 16px 12px;
                margin-bottom: 20px;
            }

            .results-page .user-info-row {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .results-page .results-section-title {
                margin-bottom: 10px;
            }

            .results-page .tabla-kostick > h3 {
                margin-top: 8px;
            }

            .results-page .fila-header {
                display: none;
            }

            .results-page .fila-factor {
                grid-template-columns: 1fr;
                gap: 12px;
                padding: 14px 12px;
            }

            .results-page .fila-factor .col::before {
                display: block;
                margin-bottom: 6px;
                color: #2563eb;
                font-size: 11px;
                font-weight: 700;
                letter-spacing: 0.05em;
                text-transform: uppercase;
            }

            .results-page .fila-factor .col.factor::before {
                content: "Factor";
            }

            .results-page .fila-factor .col.bajo::before {
                content: "Interpretacion Negativa";
            }

            .results-page .fila-factor .col.barra::before {
                content: "Puntaje";
            }

            .results-page .fila-factor .col.alto::before {
                content: "Interpretacion Positiva";
            }

            .results-page .col.factor strong {
                font-size: 22px;
            }

            .results-page .col.bajo,
            .results-page .col.alto {
                font-size: 13px;
            }

            .results-page .puntaje {
                text-align: left;
            }

            .results-page .kostick-chart-container,
            .results-page .kostick-pie-container {
                padding: 14px 12px;
            }

            .results-page .fila-factor,
            .results-page .kostick-chart-container,
            .results-page .kostick-pie-container {
                animation: none;
            }

            .results-page .user-info-item:hover,
            .results-page .kostick-chart-container:hover,
            .results-page .kostick-pie-container:hover {
                transform: none;
                box-shadow: 0 10px 26px rgba(15, 23, 42, 0.06);
            }

            .results-page .kostick-chart-container canvas,
            .results-page .kostick-pie-container canvas {
                height: 260px !important;
            }
        }

        @media (max-width: 420px) {
            .results-page .main-container {
                width: min(1220px, calc(100% - 12px));
                margin: 8px auto 16px;
            }

            .results-page .results {
                padding: 14px 10px 18px;
            }

            .results-page .user-info-item {
                padding: 12px 10px;
            }

            .results-page .user-info-item .value {
                font-size: 14px;
            }

            .results-page .kostick-chart-container canvas,
            .results-page .kostick-pie-container canvas {
                height: 220px !important;
            }

            .results-page #exportForm button {
                width: 100%;
            }
        }
    </style>
</head>
<body class="results-page kostick-uppercase">
    <div class="main-container">
        <div class="results">
            <h1>Resultados del Test Psicologico</h1>
            <?php if ($is_admin_view): ?>
                <p class="results-admin-back">
                    <a href="admin.php">Volver al panel de administrador</a>
                </p>
            <?php endif; ?>
            <div class="user-info-card">
    <h2>Informacion del Usuario</h2>
    <div class="user-info-row">
        <div class="user-info-item">
            <div class="label">Nombre completo</div>
            <div class="value"><?= htmlspecialchars($display_name) ?></div>
        </div>
        <?php if ($display_apellido_casada !== ''): ?>
        <div class="user-info-item">
            <div class="label">Apellido de casada</div>
            <div class="value"><?= htmlspecialchars($display_apellido_casada) ?></div>
        </div>
        <?php endif; ?>
        <div class="user-info-item">
            <div class="label">Edad</div>
            <div class="value"><?= htmlspecialchars($display_age) ?></div>
        </div>
        <div class="user-info-item">
            <div class="label">Profesion</div>
            <div class="value"><?= htmlspecialchars($display_profession) ?></div>
        </div>
        <div class="user-info-item">
            <div class="label">CI</div>
            <div class="value"><?= htmlspecialchars($display_ci !== '' ? $display_ci : '-') ?></div>
        </div>
        <div class="user-info-item">
            <div class="label">Puesto</div>
            <div class="value"><?= htmlspecialchars($display_position !== '' ? $display_position : '-') ?></div>
        </div>
        <div class="user-info-item">
            <div class="label">Tiempo del Test</div>
            <div class="value"><?= htmlspecialchars($tiempo_total) ?></div>
        </div>
    </div>
</div>
<h2 class="results-section-title">Resultados por Factores</h2>
<div class="tabla-kostick">
<?php
$factores = [
                "N" => [
        "nombre" => "Necesidad de terminar la tarea",
        "positivo1" => "Termina lo que empieza y es confiable.",
        "positivo2" => "Se obstina y le cuesta soltar una tarea.",
        "negativo1" => "Puede manejar varias tareas sin apego.",
        "negativo2" => "No termina lo que empieza y se distrae."
    ],
    "G" => [
        "nombre" => "Desempeñar una intensa actividad",
        "positivo1" => "Es trabajador dedicado e intenso.",
        "positivo2" => "Trabaja por trabajar, sin enfocarse en resultados.",
        "negativo1" => "Busca métodos eficientes para ahorrar tiempo.",
        "negativo2" => "Evita trabajos que demandan esfuerzo."
    ],
    "A" => [
        "nombre" => "Ambición",
        "positivo1" => "Es ambicioso, competitivo y le gustan los retos.",
        "positivo2" => "Se frustra por metas demasiado altas.",
        "negativo1" => "Es tranquilo y se conforma sin presión.",
        "negativo2" => "Tiene poca iniciativa y se conforma con el mínimo."
    ],
    "L" => [
        "nombre" => "Liderazgo",
        "positivo1" => "Lidera con confianza sin competir.",
        "positivo2" => "Es ostentoso y busca estatus.",
        "negativo1" => "Funciona bien sin buscar liderazgo.",
        "negativo2" => "No se ve como líder y evita dominar."
    ],
    "P" => [
        "nombre" => "Asumir autoridad",
        "positivo1" => "Le gusta dirigir y asumir responsabilidad.",
        "positivo2" => "Es posesivo y puede acaparar autoridad.",
        "negativo1" => "Es tolerante y no sabotea al líder.",
        "negativo2" => "Rechaza asumir responsabilidad."
    ],
    "I" => [
        "nombre" => "Rapidez para decidir",
        "positivo1" => "Decide rápido, con entusiasmo.",
        "positivo2" => "Es impulsivo e impaciente.",
        "negativo1" => "Es cuidadoso y serio al decidir.",
        "negativo2" => "Vacila demasiado y se bloquea."
    ],
    "T" => [
        "nombre" => "Ritmo de trabajo",
        "positivo1" => "Produce mucho y trabaja rápido.",
        "positivo2" => "Se apresura demasiado y pierde eficiencia.",
        "negativo1" => "Es calmado y no se frustra.",
        "negativo2" => "Trabaja con ritmo inconsistente."
    ],
    "V" => [
        "nombre" => "Nivel de energía",
        "positivo1" => "Tiene mucha energía física y es activo.",
        "positivo2" => "Se mueve demasiado cuando debería concentrarse.",
        "negativo1" => "Puede permanecer sentado por largos periodos.",
        "negativo2" => "Se cansa o tiene poca energía."
    ],
    "X" => [
        "nombre" => "Autoimagen",
        "positivo1" => "Tiene buena presencia y estilo en grupo.",
        "positivo2" => "Se vuelve exhibicionista.",
        "negativo1" => "Es modesto y sincero.",
        "negativo2" => "Es tímido y no se hace notar."
    ],
    "S" => [
        "nombre" => "Sociabilidad",
        "positivo1" => "Es sociable y crea buenas relaciones.",
        "positivo2" => "Pierde tiempo en lo social y evita trabajo duro.",
        "negativo1" => "Enfoca energía en el trabajo.",
        "negativo2" => "Tiene poco tacto y es introvertido."
    ],
    "B" => [
        "nombre" => "Actitud grupal",
        "positivo1" => "Se interesa por el grupo y coopera.",
        "positivo2" => "Se vuelve dependiente del grupo.",
        "negativo1" => "Piensa independiente del grupo.",
        "negativo2" => "Es solitario y no percibe emociones del grupo."
    ],
    "O" => [
        "nombre" => "Empatía",
        "positivo1" => "Es empático y crea vínculos genuinos.",
        "positivo2" => "Se hiere fácilmente y se vuelve muy emocional.",
        "negativo1" => "Es imparcial y analítico.",
        "negativo2" => "Evita la intimidad y mantiene distancia."
    ],
    "R" => [
        "nombre" => "Planeación",
        "positivo1" => "Planea bien y piensa a largo plazo.",
        "positivo2" => "Se pierde en teorías sin acción práctica.",
        "negativo1" => "Es práctico y orientado a la acción.",
        "negativo2" => "No organiza ni planea bien."
    ],
    "D" => [
        "nombre" => "Detalle",
        "positivo1" => "Disfruta los detalles y es preciso.",
        "positivo2" => "Se pierde en detalles y malinterpreta.",
        "negativo1" => "Delegador y distingue lo importante.",
        "negativo2" => "Evita los detalles y los descuida."
    ],
    "C" => [
        "nombre" => "Orden",
        "positivo1" => "Es ordenado, pulcro y estructurado.",
        "positivo2" => "Es rígido y terco por exceso de orden.",
        "negativo1" => "Es flexible ante el desorden.",
        "negativo2" => "Es descuidado y sin sistema."
    ],
    "Z" => [
        "nombre" => "Adaptación al cambio",
        "positivo1" => "Acepta el cambio y se entusiasma con novedades.",
        "positivo2" => "Se aburre rápido y no se mantiene en un puesto.",
        "negativo1" => "Se adapta fácilmente a rutinas.",
        "negativo2" => "Es rígido y resiste el cambio."
    ],
    "E" => [
        "nombre" => "Estabilidad emocional",
        "positivo1" => "Es calmado, estable y maduro emocionalmente.",
        "positivo2" => "Puede parecer frío o pasivo emocionalmente.",
        "negativo1" => "Aporta energía emocional al trabajo.",
        "negativo2" => "Es inestable y cambia de ánimo fácilmente."
    ],
    "K" => [
        "nombre" => "Manejo de conflicto",
        "positivo1" => "Enfrenta problemas abiertamente y es directo.",
        "positivo2" => "Se ofende fácilmente y malinterpreta.",
        "negativo1" => "Es objetivo y evita dañar a otros.",
        "negativo2" => "Reprime emociones y evita conflictos."
    ],
    "F" => [
        "nombre" => "Relación con autoridad",
        "positivo1" => "Es leal, sensible y busca guía del jefe.",
        "positivo2" => "Evita o no le importa agradar al superior.",
        "negativo1" => "Es autónomo y no necesita motivación externa.",
        "negativo2" => "Desafía la autoridad y puede ser rebelde."
    ],
    "W" => [
        "nombre" => "Cumplimiento de reglas",
        "positivo1" => "Respeta reglas y sigue instrucciones con precisión.",
        "positivo2" => "No le gustan las reglas y depende demasiado de ellas.",
        "negativo1" => "Es autónomo y orientado a metas.",
        "negativo2" => "No sigue reglas estrictamente y actúa por su cuenta."
]
];
$grupos = [
    "Grado de Energia" => ["N","G","A"],
    "Liderazgo" => ["L","P","I"],
    "Modo de Vida" => ["T","V"],
    "Naturaleza Social" => ["X","S","B","O"],
    "Adaptación al Trabajo" => ["R","D","C"],
    "Naturaleza Emocional" => ["Z","E","K"],
    "Subordinación" => ["F","W"]
];

foreach($grupos as $grupo => $letras): ?>
    <h3><?= $grupo ?></h3>
    <div class="fila-header">
        <div class="col factor">Factor</div>
        <div class="col bajo">Interpretacion Negativa</div>
        <div class="col barra">Puntaje</div>
        <div class="col alto">Interpretacion Positiva</div>
    </div>
    <?php foreach($letras as $letra):
        $info = $factores[$letra];
        $valor = $scores[$letra] ?? 0;
        $porcentaje = ($valor / 9) * 100;
    ?>
    <div class="fila-factor">
    <div class="col factor">
        <strong><?= $letra ?></strong><br>
        <span><?= $info['nombre'] ?></span>
    </div>

    <!-- COLUMNA BAJO -->
    <div class="col bajo">
        <?php if ($valor >= 6): ?>
            <?= $info['positivo2'] ?>
        <?php elseif ($valor <= 3): ?>
            <?= $info['negativo2'] ?>
        <?php else: ?>
        Normal, Nada a resaltar
        <?php endif; ?>
    </div>

    <!-- BARRA DE PUNTAJE -->
    <div class="col barra">
        <div class="barra-base">
            <div class="barra-fill" style="width: <?= $porcentaje ?>%;"></div>
        </div>
        <div class="puntaje"><?= $valor ?>/9</div>
    </div>

    <!-- COLUMNA ALTO -->
    <div class="col alto">
        <?php if ($valor >= 6): ?>
            <?= $info['positivo1'] ?>
        <?php elseif ($valor <= 3): ?>
            <?= $info['negativo1'] ?>
        <?php else: ?>
        Normal, Nada a resaltar
    <?php endif; ?>
    </div>
</div>

<?php endforeach; ?>
<?php endforeach; ?>
</div>
<div class="kostick-charts-container">
            <div class="kostick-chart-container">
                <h2>Gráfico Radar Kostick</h2>
                <canvas id="kostickChart"></canvas>
            </div>

            <div class="kostick-pie-container">
                <h2>Distribución por Dimensión</h2>
                <canvas id="kostickPie"></canvas>
            </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>

<script>
const scores = <?php echo json_encode($scores); ?>;

const dataKostick = {
    labels: [
        "GRADO DE ENERGIA",
        "LIDERAZGO",
        "MODO DE VIDA",
        "NATURALEZA SOCIAL",
        "ADAPTACION AL TRABAJO",
        "NATURALEZA EMOCIONAL"
    ],
    datasets: [{
        label: "PUNTAJE PROMEDIO POR DIMENSION",
        data: [
            (scores.N + scores.G + scores.A) / 3,
            (scores.L + scores.P + scores.I) / 3,
            (scores.T + scores.V) / 2,
            (scores.X + scores.S + scores.B + scores.O) / 4,
            (scores.R + scores.D + scores.C) / 3,
            (scores.Z + scores.E + scores.K) / 3
        ],
        fill: true,
        backgroundColor: "rgba(0, 128, 0, 0.3)",
        borderColor: "green",
        pointBackgroundColor: "darkgreen",
        pointBorderColor: "#fff",
        pointRadius: 5,
        pointHoverRadius: 8,
        tension: 0.3
    }]
};

const ctx = document.getElementById('kostickChart').getContext('2d');

new Chart(ctx, {
    type: 'radar',
    data: dataKostick,
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            r: {
                min: 0,
                max: 9,
                ticks: {
                    stepSize: 1,
                    color: "#555",
                    backdropColor: "transparent",
                    font: {
                        size: window.matchMedia('(max-width: 768px)').matches ? 10 : 12
                    }
                },
                grid: { color: "rgba(255,140,80,0.3)" },
                angleLines: { color: "rgba(255,140,80,0.4)" },
                pointLabels: {
                    color: "#ff6f3c",
                    font: {
                        size: window.matchMedia('(max-width: 768px)').matches ? 10 : 14,
                        weight: "bold"
                    }
                }
            }
        },
        plugins: {
            legend: { display: false },

            // Ã¢Å“â€¦ NÃƒÅ¡MEROS EN CADA ESQUINA
            datalabels: {
                display: !window.matchMedia('(max-width: 768px)').matches,
                color: '#000',
                font: {
                    size: 14,
                    weight: 'bold'
                },
                formatter: (value) => value.toFixed(1),
                align: 'top',
                offset: 6
            }
        },
        animation: {
            duration: 1200,
            easing: "easeOutCirc"
        }
    },
    plugins: [ChartDataLabels]
});
</script>

<script>
const pieData = {
    labels: [
        "GRADO DE ENERGIA",
        "LIDERAZGO",
        "MODO DE VIDA",
        "NATURALEZA SOCIAL",
        "ADAPTACION AL TRABAJO",
        "NATURALEZA EMOCIONAL"
    ],
    datasets: [{
        data: [
            (scores.N + scores.G + scores.A) / 3,
            (scores.L + scores.P + scores.I) / 3,
            (scores.T + scores.V) / 2,
            (scores.X + scores.S + scores.B + scores.O) / 4,
            (scores.R + scores.D + scores.C) / 3,
            (scores.Z + scores.E + scores.K) / 3
        ],
        backgroundColor: [
            "red",
            "blue",
            "green",
            "yellow",
            "purple",
            "orange"
        ],

        borderColor: "#fffaf0",
        borderWidth: 2
    }]
};
const pieCtx = document.getElementById('kostickPie').getContext('2d');
new Chart(pieCtx, {
    type: 'pie',
    data: pieData,
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    color: "#ff6f3c",
                    boxWidth: window.matchMedia('(max-width: 768px)').matches ? 10 : 16,
                    padding: window.matchMedia('(max-width: 768px)').matches ? 10 : 16,
                    font: {
                        size: window.matchMedia('(max-width: 768px)').matches ? 11 : 14,
                        weight: "bold"
                    }
                }
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.label + ": " + context.formattedValue;
                    }
                }
            }
        },
        animation: {
            animateScale: true,
            duration: 1200,
            easing: "easeOutCirc"
        }
    }
});
</script>

<form id="exportForm" method="POST" action="export_pdf.php" target="_blank">
    <input type="hidden" name="html" id="html-input">
    <input type="hidden" name="test_id" value="<?= (int)$test_id_for_view ?>">
    <button type="submit">Exportar resultados a PDF</button>
</form>

<script>
document.getElementById('html-input').value = JSON.stringify(scores);

</script>


</body>
</html>
