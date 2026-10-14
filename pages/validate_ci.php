<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=UTF-8');

require __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/user_identity.php';

ensure_user_identity_schema($conn);

$rawCiNumero = (string)($_GET['ci_numero'] ?? '');
$rawCiComplemento = (string)($_GET['ci_complemento'] ?? '');

try {
    $ciNumero = validate_ci_numero($rawCiNumero);
    $ciComplemento = validate_ci_complemento($rawCiComplemento);
    $formattedCi = format_user_ci($ciNumero, $ciComplemento);

    echo json_encode([
        'valid' => true,
        'exists' => false,
        'formatted' => $formattedCi,
        'message' => 'CI VALIDO: ' . $formattedCi,
    ], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    echo json_encode([
        'valid' => false,
        'exists' => false,
        'formatted' => '',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'valid' => false,
        'exists' => false,
        'formatted' => '',
        'message' => 'NO SE PUDO VALIDAR EL CI EN ESTE MOMENTO.',
    ], JSON_UNESCAPED_UNICODE);
}
?>
