<?php
declare(strict_types=1);

function ensure_access_codes_schema(PDO $conn): void
{
    static $initialized = false;

    if ($initialized) {
        return;
    }

    if ($conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS access_codes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(20) NOT NULL UNIQUE,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                fecha_inicio DATETIME NULL,
                fecha_fin DATETIME NULL,
                expires_at DATETIME NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        foreach (['fecha_inicio', 'fecha_fin', 'expires_at'] as $column) {
            $check = $conn->prepare(
                'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
            );
            $check->execute([':table' => 'access_codes', ':column' => $column]);
            if (!$check->fetchColumn()) {
                $conn->exec("ALTER TABLE access_codes ADD COLUMN `{$column}` DATETIME NULL");
            }
        }

        $conn->exec(
            "UPDATE access_codes
             SET fecha_inicio = COALESCE(fecha_inicio, created_at, CURRENT_TIMESTAMP),
                 fecha_fin = COALESCE(fecha_fin, expires_at, created_at, CURRENT_TIMESTAMP),
                 expires_at = COALESCE(expires_at, fecha_fin, fecha_inicio, created_at, CURRENT_TIMESTAMP)
             WHERE fecha_inicio IS NULL OR fecha_fin IS NULL OR expires_at IS NULL"
        );
        $conn->exec('ALTER TABLE access_codes MODIFY fecha_inicio DATETIME NOT NULL');
        $conn->exec('ALTER TABLE access_codes MODIFY fecha_fin DATETIME NOT NULL');
        $conn->exec('ALTER TABLE access_codes MODIFY expires_at DATETIME NOT NULL');

        $constraintCheck = $conn->prepare(
            'SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = :table AND constraint_name = :name AND constraint_type = \'CHECK\''
        );
        $constraintCheck->execute([':table' => 'access_codes', ':name' => 'chk_access_codes_valid_range']);
        if (!$constraintCheck->fetchColumn()) {
            $conn->exec('ALTER TABLE access_codes ADD CONSTRAINT chk_access_codes_valid_range CHECK (fecha_fin > fecha_inicio)');
        }

        foreach ([
            'idx_access_codes_window' => '(is_active, fecha_inicio, fecha_fin)',
            'idx_access_codes_active_fecha_fin' => '(is_active, fecha_fin)',
        ] as $index => $columns) {
            $indexCheck = $conn->prepare(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :name'
            );
            $indexCheck->execute([':table' => 'access_codes', ':name' => $index]);
            if (!$indexCheck->fetchColumn()) {
                $conn->exec("CREATE INDEX `{$index}` ON access_codes {$columns}");
            }
        }
    } else {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS access_codes (
                id SERIAL PRIMARY KEY,
                code VARCHAR(20) NOT NULL UNIQUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                fecha_inicio TIMESTAMP,
                fecha_fin TIMESTAMP,
                expires_at TIMESTAMP,
                is_active BOOLEAN NOT NULL DEFAULT TRUE
            )"
        );

        $conn->exec("ALTER TABLE access_codes ADD COLUMN IF NOT EXISTS fecha_inicio TIMESTAMP");
        $conn->exec("ALTER TABLE access_codes ADD COLUMN IF NOT EXISTS fecha_fin TIMESTAMP");
        $conn->exec("ALTER TABLE access_codes ADD COLUMN IF NOT EXISTS expires_at TIMESTAMP");
        $conn->exec(
            "UPDATE access_codes
             SET fecha_inicio = COALESCE(fecha_inicio, created_at, NOW()),
                 fecha_fin = COALESCE(fecha_fin, expires_at, created_at, NOW()),
                 expires_at = COALESCE(expires_at, fecha_fin, fecha_inicio, created_at, NOW())
             WHERE fecha_inicio IS NULL OR fecha_fin IS NULL OR expires_at IS NULL"
        );
        $conn->exec("ALTER TABLE access_codes ALTER COLUMN fecha_inicio SET NOT NULL");
        $conn->exec("ALTER TABLE access_codes ALTER COLUMN fecha_fin SET NOT NULL");
        $conn->exec("ALTER TABLE access_codes ALTER COLUMN expires_at SET NOT NULL");
        $conn->exec(
            "DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_access_codes_valid_range') THEN
                    ALTER TABLE access_codes
                    ADD CONSTRAINT chk_access_codes_valid_range CHECK (fecha_fin > fecha_inicio);
                END IF;
            END $$;"
        );
        $conn->exec("CREATE INDEX IF NOT EXISTS idx_access_codes_window ON access_codes(is_active, fecha_inicio, fecha_fin)");
        $conn->exec("CREATE INDEX IF NOT EXISTS idx_access_codes_active_fecha_fin ON access_codes(is_active, fecha_fin)");
    }

    $initialized = true;
}

function normalize_access_code(string $code): string
{
    $normalized = strtoupper(trim($code));
    return preg_replace('/[^A-Z0-9]/', '', $normalized) ?? '';
}

function build_random_access_code(int $length = 6): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $maxIndex = strlen($alphabet) - 1;
    $code = '';

    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, $maxIndex)];
    }

    return $code;
}

function access_code_timezone(): DateTimeZone
{
    $timezone = defined('APP_TIMEZONE') ? APP_TIMEZONE : 'America/La_Paz';

    return new DateTimeZone($timezone);
}

function access_code_timezone_name(): string
{
    return access_code_timezone()->getName();
}

function format_access_code_datetime(?string $datetime): string
{
    if ($datetime === null || trim($datetime) === '') {
        return '-';
    }

    try {
        $dt = new DateTimeImmutable($datetime);
    } catch (Throwable $e) {
        return (string)$datetime;
    }

    return $dt->setTimezone(access_code_timezone())->format('d/m/Y H:i');
}

function describe_access_code_validity(?string $fechaInicio, ?string $fechaFin): string
{
    if (
        $fechaInicio === null || trim($fechaInicio) === '' ||
        $fechaFin === null || trim($fechaFin) === ''
    ) {
        return 'No se encontro informacion de vigencia para ese codigo.';
    }

    return sprintf(
    'Este código es válido desde: %s hasta %s.',
    str_replace(' ', ' a las ', format_access_code_datetime($fechaInicio)),
    str_replace(' ', ' a las ', format_access_code_datetime($fechaFin))
);
}

function build_access_code_datetime(string $dateInput, string $timeInput): DateTimeImmutable
{
    $dateInput = trim($dateInput);
    $timeInput = trim($timeInput);

    if ($dateInput === '' || $timeInput === '') {
        throw new InvalidArgumentException('Debes completar fecha y hora de inicio y fin.');
    }

    $dateTime = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i',
        $dateInput . ' ' . $timeInput,
        access_code_timezone()
    );

    $errors = DateTimeImmutable::getLastErrors();
    $hasErrors = is_array($errors)
        && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);

    if (!$dateTime || $hasErrors) {
        throw new InvalidArgumentException('La fecha u hora ingresada no tiene un formato valido.');
    }

    return $dateTime;
}

function validate_access_code_window_inputs(
    string $startDateInput,
    string $startTimeInput,
    string $endDateInput,
    string $endTimeInput
): array {
    $fechaInicio = build_access_code_datetime($startDateInput, $startTimeInput);
    $fechaFin = build_access_code_datetime($endDateInput, $endTimeInput);

    if ($fechaFin <= $fechaInicio) {
        throw new InvalidArgumentException(
            'La fecha y hora de fin debe ser mayor que la fecha y hora de inicio.'
        );
    }

    return [
        'fecha_inicio' => $fechaInicio->format('Y-m-d H:i:s'),
        'fecha_fin' => $fechaFin->format('Y-m-d H:i:s'),
        'fecha_inicio_dt' => $fechaInicio,
        'fecha_fin_dt' => $fechaFin,
    ];
}

function create_access_code(PDO $conn, string $fechaInicio, string $fechaFin): array
{
    ensure_access_codes_schema($conn);

    $conn->beginTransaction();
    try {
        $conn->exec("UPDATE access_codes SET is_active = FALSE WHERE is_active = TRUE");

        $insertStmt = $conn->prepare(
            "INSERT INTO access_codes (code, fecha_inicio, fecha_fin, expires_at, is_active)
             VALUES (:code, :fecha_inicio, :fecha_fin, :expires_at, TRUE)"
        );

        $code = '';
        $attempts = 0;
        while ($attempts < 5) {
            $attempts++;
            $candidate = build_random_access_code(6);
            try {
                $insertStmt->execute([
                    ':code' => $candidate,
                    ':fecha_inicio' => $fechaInicio,
                    ':fecha_fin' => $fechaFin,
                    ':expires_at' => $fechaFin,
                ]);
                $code = $candidate;
                break;
            } catch (PDOException $e) {
                $driverError = $e->errorInfo[1] ?? null;
                $isDuplicate = $e->getCode() === '23505' || ($e->getCode() === '23000' && (int)$driverError === 1062);
                if (!$isDuplicate) {
                    throw $e;
                }
            }
        }

        if ($code === '') {
            throw new RuntimeException('No se pudo generar un codigo unico.');
        }

        $conn->commit();

        return [
            'code' => $code,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'expires_at' => $fechaFin,
        ];
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }

        throw $e;
    }
}

function find_access_code_by_code(PDO $conn, string $rawCode): ?array
{
    ensure_access_codes_schema($conn);

    $code = normalize_access_code($rawCode);
    if ($code === '') {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT id, code, fecha_inicio, fecha_fin, expires_at, is_active, created_at
         FROM access_codes
         WHERE code = :code
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function is_access_code_currently_valid(array $row, ?DateTimeImmutable $now = null): bool
{
    $now = $now ?? new DateTimeImmutable('now', access_code_timezone());

    try {
        $fechaInicio = new DateTimeImmutable((string)$row['fecha_inicio'], access_code_timezone());
        $fechaFin = new DateTimeImmutable((string)$row['fecha_fin'], access_code_timezone());
    } catch (Throwable $e) {
        return false;
    }

    $isActive = !empty($row['is_active']) && !in_array(
        strtolower((string)$row['is_active']),
        ['0', 'f', 'false', 'no'],
        true
    );

    return $isActive && $now >= $fechaInicio && $now <= $fechaFin;
}

function find_access_code_validation_result(PDO $conn, string $rawCode): array
{
    $normalizedCode = normalize_access_code($rawCode);

    if ($normalizedCode === '') {
        return [
            'status' => 'not_found',
            'message' => 'Codigo incorrecto',
            'code' => '',
            'row' => null,
        ];
    }

    $row = find_access_code_by_code($conn, $normalizedCode);

    if (!$row) {
        return [
            'status' => 'not_found',
            'message' => 'Codigo incorrecto',
            'code' => $normalizedCode,
            'row' => null,
        ];
    }

    if (!is_access_code_currently_valid($row)) {
        return [
            'status' => 'out_of_window',
            'message' => 'Codigo fuera de vigencia',
            'code' => $normalizedCode,
            'row' => $row,
        ];
    }

    return [
        'status' => 'valid',
        'message' => 'Codigo valido',
        'code' => $normalizedCode,
        'row' => $row,
    ];
}

function find_valid_access_code(PDO $conn, string $rawCode): ?array
{
    $result = find_access_code_validation_result($conn, $rawCode);

    return $result['status'] === 'valid' ? ($result['row'] ?? null) : null;
}

function get_active_access_code_validation_result(PDO $conn, ?DateTimeImmutable $now = null): array
{
    $row = get_active_access_code($conn);

    if (!$row) {
        return [
            'status' => 'not_found',
            'message' => 'No hay codigo de acceso disponible en este momento.',
            'row' => null,
        ];
    }

    if (!is_access_code_currently_valid($row, $now)) {
        return [
            'status' => 'out_of_window',
            'message' => 'Codigo fuera de vigencia',
            'row' => $row,
        ];
    }

    return [
        'status' => 'valid',
        'message' => 'Codigo valido',
        'row' => $row,
    ];
}

function get_active_access_code(PDO $conn): ?array
{
    ensure_access_codes_schema($conn);

    $stmt = $conn->query(
        "SELECT id, code, fecha_inicio, fecha_fin, expires_at, is_active, created_at
         FROM access_codes
         WHERE is_active = TRUE
         ORDER BY id DESC
         LIMIT 1"
    );

    $row = $stmt->fetch();
    return $row ?: null;
}

function get_access_code_status_label(array $row): string
{
    $now = new DateTimeImmutable('now', access_code_timezone());

    if (is_access_code_currently_valid($row, $now)) {
        return 'Vigente';
    }

    try {
        $fechaInicio = new DateTimeImmutable((string)$row['fecha_inicio'], access_code_timezone());
    } catch (Throwable $e) {
        return 'Inactivo';
    }

    if ($now < $fechaInicio) {
        return 'Programado';
    }

    return 'Fuera de vigencia';
}
?>
