<?php
declare(strict_types=1);

require_once __DIR__ . '/../dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

session_start();
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/admin_auth.php';
require __DIR__ . '/../includes/positions.php';
require_once __DIR__ . '/../includes/user_identity.php';

ensure_positions_schema($conn);
ensure_user_identity_schema($conn);

function interpretarResultado(int $puntaje): string
{
    if ($puntaje <= 3) {
        return 'BAJO';
    }
    if ($puntaje >= 6) {
        return 'ALTO';
    }
    return 'PROMEDIO';
}

function pdf_escape_upper(?string $value): string
{
    return htmlspecialchars(app_to_upper((string)$value), ENT_QUOTES, 'UTF-8');
}

function render_pdf_two_row_grid(array $items, string $tableClass): string
{
    if ($items === []) {
        return '';
    }

    $firstRowCount = (int)ceil(count($items) / 2);
    $rows = [
        array_slice($items, 0, $firstRowCount),
        array_slice($items, $firstRowCount),
    ];
    $columnCount = max(array_map('count', $rows));

    $html = '<table class="' . htmlspecialchars($tableClass, ENT_QUOTES, 'UTF-8') . '">';

    foreach ($rows as $rowItems) {
        if ($rowItems === []) {
            continue;
        }

        $html .= '<tr>';

        foreach ($rowItems as $item) {
            $extraClass = trim((string)($item['class'] ?? ''));
            $cellClass = 'info-cell' . ($extraClass !== '' ? ' ' . $extraClass : '');

            $html .= '<td class="' . htmlspecialchars($cellClass, ENT_QUOTES, 'UTF-8') . '">'
                . '<div class="info-label">' . pdf_escape_upper((string)($item['label'] ?? '')) . '</div>'
                . '<div class="info-value">' . pdf_escape_upper((string)($item['value'] ?? '-')) . '</div>'
                . '</td>';
        }

        for ($i = count($rowItems); $i < $columnCount; $i++) {
            $html .= '<td class="info-cell info-cell-empty"></td>';
        }

        $html .= '</tr>';
    }

    $html .= '</table>';

    return $html;
}

$test_id = isset($_POST['test_id']) ? (int)$_POST['test_id'] : 0;
if ($test_id < 1 && isset($_SESSION['test_id'])) {
    $test_id = (int)$_SESSION['test_id'];
}
if ($test_id < 1) {
    die('NO SE ENCONTRO REFERENCIA DEL TEST.');
}

if (
    !is_admin_authenticated() &&
    (!isset($_SESSION['test_id']) || (int)$_SESSION['test_id'] !== $test_id)
) {
    die('ACCESO NO AUTORIZADO AL REPORTE.');
}

$metaStmt = $conn->prepare(
    "SELECT
        t.id,
        t.start_datetime,
        t.end_datetime,
        t.duration,
        u.name,
        u.apellido_paterno,
        u.apellido_materno,
        u.apellido_casada,
        u.age,
        u.profession,
        u.ci_numero,
        u.ci_complemento,
        u.position_name
    FROM tests t
    INNER JOIN users u ON u.id = t.user_id
    WHERE t.id = :test_id
    LIMIT 1"
);
$metaStmt->execute([':test_id' => $test_id]);
$meta = $metaStmt->fetch();

if (!$meta) {
    die('NO EXISTE INFORMACION PARA EL TEST SOLICITADO.');
}

$resultsStmt = $conn->prepare("SELECT scale, score, level FROM results WHERE test_id = :test_id");
$resultsStmt->execute([':test_id' => $test_id]);
$rows = $resultsStmt->fetchAll();

if (empty($rows)) {
    die('EL TEST NO TIENE RESULTADOS PARA EXPORTAR.');
}

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

$dimensiones = [
    "Grado de Energía" => ['N', 'G', 'A'],
    "Liderazgo" => ['L', 'P', 'I'],
    "Modo de Vida" => ['T', 'V'],
    "Naturaleza Social" => ['X', 'S', 'B', 'O'],
    "Adaptación al Trabajo" => ['R', 'D', 'C'],
    "Naturaleza Emocional" => ['Z', 'E', 'K'],
    "Subordinación" => ['F', 'W']
];

$scores = [];
$levels = [];
foreach ($rows as $row) {
    $scale = (string)$row['scale'];
    $score = (int)$row['score'];
    $scores[$scale] = $score;
    $levels[$scale] = (string)$row['level'];
}

foreach (array_keys($factores) as $scale) {
    if (!isset($scores[$scale])) {
        $scores[$scale] = 0;
    }
    if (!isset($levels[$scale]) || $levels[$scale] === '') {
        $levels[$scale] = strtolower(interpretarResultado($scores[$scale]));
    }
}

$dimensionSummaryItems = [];
foreach ($dimensiones as $nombreDimension => $letras) {
    $acumulado = 0;
    foreach ($letras as $letra) {
        $acumulado += (int)($scores[$letra] ?? 0);
    }
    $promedio = round($acumulado / count($letras), 2);
    $dimensionSummaryItems[] = [
        'label' => $nombreDimension,
        'value' => number_format($promedio, 2, '.', ''),
    ];
}

$detalleRows = '';
foreach ($dimensiones as $nombreDimension => $letras) {
    $rowspan = count($letras);
    $primeraFila = true;
    $groupRows = '';

    foreach ($letras as $letra) {
        $puntaje = (int)($scores[$letra] ?? 0);
        $resultado = interpretarResultado($puntaje);
        $factor = $factores[$letra];

        if ($puntaje >=6) {
            $interpPositiva = '<span class="underline">' . pdf_escape_upper($factor['positivo1']) . '</span>';
            $interpNegativa = pdf_escape_upper($factor['positivo2']);
        } elseif ($puntaje <= 3) {
            $interpPositiva = pdf_escape_upper($factor['negativo1']);
            $interpNegativa = '<span class="underline">' . pdf_escape_upper($factor['negativo2']) . '</span>';
        } else {
            $interpPositiva = 'NADA A RESALTAR';
            $interpNegativa = 'NADA A RESALTAR';
        }

        $groupRows .= '<tr>';
        if ($primeraFila) {
            $groupRows .= '<td rowspan="' . $rowspan . '" class="dim-cell">'
                . pdf_escape_upper($nombreDimension)
                . '</td>';
            $primeraFila = false;
        }
        $groupRows .= '<td>' . pdf_escape_upper($factor['nombre']) . '</td>'
            . '<td class="text-center">' . pdf_escape_upper((string)$puntaje) . '</td>'
            . '<td class="text-center">' . pdf_escape_upper($resultado) . '</td>'
            . '<td>' . $interpPositiva . '</td>'
            . '<td>' . $interpNegativa . '</td>'
            . '</tr>';
    }

    $detalleRows .= '<tbody class="detail-group">' . $groupRows . '</tbody>';
}

$fechaInforme = date('d/m/Y H:i');
$fechaInicio = $meta['start_datetime'] ? (string)$meta['start_datetime'] : '-';
$fechaFin = $meta['end_datetime'] ? (string)$meta['end_datetime'] : '-';
$duracion = $meta['duration'] ? (string)$meta['duration'] : '-';
$fullName = build_user_full_name(
    (string)($meta['name'] ?? ''),
    isset($meta['apellido_paterno']) ? (string)$meta['apellido_paterno'] : null,
    isset($meta['apellido_materno']) ? (string)$meta['apellido_materno'] : null
);
$ciDisplay = format_user_ci(
    (string)($meta['ci_numero'] ?? ''),
    isset($meta['ci_complemento']) ? (string)$meta['ci_complemento'] : null
);
$ciDisplay = $ciDisplay !== '' ? $ciDisplay : '-';

$evaluadoItems = [
    ['label' => 'Nombre Completo', 'value' => $fullName],
    ['label' => 'CI', 'value' => $ciDisplay],
    ['label' => 'Edad', 'value' => (string)$meta['age']],
    ['label' => 'Profesion', 'value' => (string)($meta['profession'] ?? '-') ?: '-'],
    ['label' => 'Puesto', 'value' => (string)($meta['position_name'] ?? '-') ?: '-'],
];

$apellidoCasada = normalize_user_name_part((string)($meta['apellido_casada'] ?? ''));
if ($apellidoCasada !== '') {
    array_splice($evaluadoItems, 1, 0, [[
        'label' => 'Apellido de Casada',
        'value' => $apellidoCasada,
    ]]);
}

$evaluadoGrid = render_pdf_two_row_grid($evaluadoItems, 'info-grid info-grid-3col');

$testGrid = render_pdf_two_row_grid(
    [
        ['label' => 'ID Test', 'value' => (string)$test_id],
        ['label' => 'Inicio', 'value' => str_replace(' ', '  a las  ', $fechaInicio)],
        ['label' => 'Fin', 'value' => str_replace(' ', '  a las  ', $fechaFin)],
        ['label' => 'Duracion', 'value' => $duracion],
    ],
    'info-grid info-grid-2col'
);

$dimensionGrid = render_pdf_two_row_grid(
    $dimensionSummaryItems,
    'info-grid summary-grid info-grid-4col'
);

$html = '
<style>
    @page { size: letter portrait; margin: 4.5mm 5mm 5mm 5mm; }
    body { font-family: Arial, sans-serif; font-size: 11px; line-height: 1.1; color: #111; margin: 0; text-transform: uppercase; }
    .sheet { width: 100%; }
    h1 { text-align: center; margin: 0 0 2px 0; font-size: 11px; line-height: 1.05; }
    .subtitle { text-align: center; margin: 0 0 6px 0; color: #555; font-size: 11px; line-height: 1; }
    .section-block { margin-bottom: 30px; }
    .section-title { margin: 0 0 4px 0; padding: 2px 4px; font-weight: bold; font-size: 11px; line-height: 1.05; border: 1px solid #c8d0d8; background: #eef2f6; }
    table { width: 100%; border-collapse: collapse; margin: 0; table-layout: fixed; }
    th, td { border: 1px solid #d0d7de; padding: 2px 3px; text-align: left; vertical-align: top; word-break: break-word; overflow-wrap: break-word; }
    th { background: #e8eef4; font-size: 11px; line-height: 1.02; }
    td { font-size: 11px; line-height: 1.06; }
    thead { display: table-header-group; }
    table, thead, tbody, tr, td, th { page-break-inside: avoid; }
    .info-grid { margin-bottom: 0; }
    .info-grid-2col td { width: 50%; }
    .info-grid-3col td { width: 33.33%; }
    .info-grid-4col td { width: 25%; }
    .info-cell { background: #fff; }
    .info-cell-empty { background: #f8fafc; }
    .info-label { font-size: 11px; font-weight: bold; color: #5a6673; margin-bottom: 1px; line-height: 1; }
    .info-value { font-size: 11px; font-weight: bold; color: #111; line-height: 1.06; }
    .summary-grid .info-cell { text-align: center; }
    .summary-grid .info-value { font-size: 11px; }
    .small { color: #555; font-size: 11px; margin-top: 3px; line-height: 1.02; text-align: center; }
    .dim-cell { font-weight: bold; background: #f7f9fb; }
    .underline { text-decoration: underline; font-weight: bold; }
    .detail-group { page-break-inside: avoid; }
    .detail-table { margin-top: 0; }
    .detail-table th,
    .detail-table td { padding: 1.5px 2px; }
    .detail-table th { text-align: center; font-size: 11px; }
    .detail-table th.col-puntaje { white-space: nowrap; word-break: normal; overflow-wrap: normal; }
    .detail-table td { font-size: 11px; line-height: 1.05; }
    .text-center { text-align: center; }
    .col-dimension { width: 16%; }
    .col-area { width: 18%; }
    .col-puntaje { width: 7%; }
    .col-resultado { width: 10%; }
    .col-interpretacion-positiva { width: 24.5%; }
    .col-interpretacion-negativa { width: 24.5%; }
    .interp-block { display: inline; margin: 0; padding: 0; border: 0; }
    .interp-block > div { display: inline; }
    .interp-block + .interp-block:before { content: " | "; font-size: 5.8px; font-weight: bold; color: #5a6673; }
    .interp-title { font-size: 5.8px; font-weight: bold; color: #5a6673; }
    .interp-title:after { content: ": "; }
</style>

<div class="sheet">
<h1>INFORME EJECUTIVO - TEST DE KOSTICK</h1>
<div class="subtitle">GENERADO: ' . pdf_escape_upper($fechaInforme) . '</div>

<div class="section-block">
    <div class="section-title">1. DATOS DEL EVALUADO</div>
    ' . $evaluadoGrid . '
</div>

<div class="section-block">
    <div class="section-title">2. DATOS DEL TEST</div>
    ' . $testGrid . '
</div>

<div class="section-block">
    <div class="section-title">3. PROMEDIO POR DIMENSION</div>
    ' . $dimensionGrid . '
</div>

<div class="section-block">
    <div class="section-title">4. DETALLE POR FACTOR</div>
    <table class="detail-table">
        <thead>
            <tr>
                <th class="col-dimension">DIMENSION</th>
                <th class="col-area">AREA</th>
                <th class="col-puntaje">PUNTAJE</th>
                <th class="col-resultado">RESULTADO</th>
                <th class="col-interpretacion-positiva">INTERPRETACION POSITIVA</th>
                <th class="col-interpretacion-negativa">INTERPRETACION NEGATIVA</th>
            </tr>
        </thead>
        ' . $detalleRows . '
    </table>
</div>

<p class="small">INTERPRETACION DE NIVEL: BAJO (0-3), PROMEDIO (4-5), ALTO (6-9).</p>
</div>
';

$options = new Options();
$options->set('defaultFont', 'Arial');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('letter', 'portrait');
$dompdf->render();
$dompdf->stream('KOSTICK_INFORME_EJECUTIVO.pdf', ['Attachment' => false]);
