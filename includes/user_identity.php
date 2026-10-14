<?php
declare(strict_types=1);

function ensure_user_identity_schema(PDO $conn): void
{
    static $initialized = false;

    if ($initialized) {
        return;
    }

    if ($conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        foreach ([
            'ci_numero' => 'VARCHAR(20) NULL',
            'ci_complemento' => 'VARCHAR(10) NULL',
            'apellido_paterno' => 'VARCHAR(255) NULL',
            'apellido_materno' => 'VARCHAR(255) NULL',
            'apellido_casada' => 'VARCHAR(255) NULL',
        ] as $column => $definition) {
            $check = $conn->prepare(
                'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
            );
            $check->execute([':table' => 'users', ':column' => $column]);
            if (!$check->fetchColumn()) {
                $conn->exec("ALTER TABLE users ADD COLUMN `{$column}` {$definition}");
            }
        }

        $indexCheck = $conn->prepare(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :name'
        );
        $indexCheck->execute([':table' => 'users', ':name' => 'idx_users_ci_lookup']);
        if (!$indexCheck->fetchColumn()) {
            $conn->exec('CREATE INDEX idx_users_ci_lookup ON users (ci_numero, ci_complemento)');
        }
    } else {
        $conn->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS ci_numero VARCHAR(20)");
        $conn->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS ci_complemento VARCHAR(10)");
        $conn->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS apellido_paterno VARCHAR(255)");
        $conn->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS apellido_materno VARCHAR(255)");
        $conn->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS apellido_casada VARCHAR(255)");

        $conn->exec(
        "DO $$
        BEGIN
            IF NOT EXISTS (
                SELECT 1
                FROM pg_constraint
                WHERE conname = 'chk_users_ci_numero_format'
            ) THEN
                ALTER TABLE users
                ADD CONSTRAINT chk_users_ci_numero_format
                CHECK (
                    ci_numero IS NULL
                    OR ci_numero ~ '^[0-9]{4,20}$'
                );
            END IF;
        END $$;"
        );

        $conn->exec(
        "DO $$
        BEGIN
            IF NOT EXISTS (
                SELECT 1
                FROM pg_constraint
                WHERE conname = 'chk_users_ci_complemento_format'
            ) THEN
                ALTER TABLE users
                ADD CONSTRAINT chk_users_ci_complemento_format
                CHECK (
                    ci_complemento IS NULL
                    OR ci_complemento ~ '^[A-Z0-9]{1,10}$'
                );
            END IF;
        END $$;"
        );

        $conn->exec(
        "DO $$
        BEGIN
            IF NOT EXISTS (
                SELECT 1
                FROM pg_constraint
                WHERE conname = 'chk_users_ci_complement_requires_numero'
            ) THEN
                ALTER TABLE users
                ADD CONSTRAINT chk_users_ci_complement_requires_numero
                CHECK (ci_numero IS NOT NULL OR ci_complemento IS NULL);
            END IF;
        END $$;"
        );

        $conn->exec("ALTER TABLE users DROP CONSTRAINT IF EXISTS uq_users_ci_numero_ci_complemento");
        $conn->exec("DROP INDEX IF EXISTS uq_users_ci_numero_ci_complemento");
        $conn->exec("DROP INDEX IF EXISTS uq_users_ci_numero_without_complemento");
        $conn->exec("CREATE INDEX IF NOT EXISTS idx_users_ci_lookup ON users (ci_numero, ci_complemento)");
    }

    $initialized = true;
}

function ci_strlen(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
}

function normalize_user_name_part(string $value): string
{
    $collapsed = preg_replace('/\s+/u', ' ', trim($value));

    return $collapsed === null ? trim($value) : $collapsed;
}

function app_to_upper(string $value): string
{
    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($value, 'UTF-8');
    }

    return strtoupper($value);
}

function ci_to_upper(string $value): string
{
    return app_to_upper($value);
}

function validate_user_name_part(string $rawValue, string $fieldLabel): string
{
    $value = normalize_user_name_part($rawValue);

    if (
        $value === '' ||
        ci_strlen($value) > 255 ||
        preg_match('/[\p{L}\p{M}]/u', $value) !== 1 ||
        preg_match("/^[\p{L}\p{M}\s\.\'\-]+$/u", $value) !== 1
    ) {
        throw new InvalidArgumentException(
            'EL CAMPO ' . $fieldLabel . ' ES OBLIGATORIO Y SOLO PUEDE CONTENER LETRAS Y SIGNOS COMUNES.'
        );
    }

    return $value;
}

function validate_optional_user_name_part(?string $rawValue, string $fieldLabel): ?string
{
    $value = normalize_user_name_part((string)$rawValue);

    if ($value === '') {
        return null;
    }

    if (
        ci_strlen($value) > 255 ||
        preg_match('/[\p{L}\p{M}]/u', $value) !== 1 ||
        preg_match("/^[\p{L}\p{M}\s\.\'\-]+$/u", $value) !== 1
    ) {
        throw new InvalidArgumentException(
            'EL CAMPO ' . $fieldLabel . ' SOLO PUEDE CONTENER LETRAS Y SIGNOS COMUNES.'
        );
    }

    return $value;
}

function build_user_full_name(?string $name, ?string $apellidoPaterno = null, ?string $apellidoMaterno = null): string
{
    $parts = [
        normalize_user_name_part((string)$name),
        normalize_user_name_part((string)$apellidoPaterno),
        normalize_user_name_part((string)$apellidoMaterno),
    ];

    $parts = array_values(array_filter($parts, static function (string $part): bool {
        return $part !== '';
    }));

    return implode(' ', $parts);
}

function build_user_full_name_from_array(array $source): string
{
    return build_user_full_name(
        isset($source['name']) ? (string)$source['name'] : '',
        isset($source['apellido_paterno']) ? (string)$source['apellido_paterno'] : null,
        isset($source['apellido_materno']) ? (string)$source['apellido_materno'] : null
    );
}

function normalize_ci_numero(string $rawNumero): string
{
    $numero = preg_replace('/\s+/', '', trim($rawNumero));

    return $numero === null ? '' : $numero;
}

function normalize_ci_complemento(?string $rawComplemento): ?string
{
    if ($rawComplemento === null) {
        return null;
    }

    $complemento = trim($rawComplemento);
    if ($complemento === '') {
        return null;
    }

    $complemento = preg_replace('/[\s-]+/', '', $complemento);
    if ($complemento === null || $complemento === '') {
        return null;
    }

    return ci_to_upper($complemento);
}

function validate_ci_numero(string $rawNumero): string
{
    $numero = normalize_ci_numero($rawNumero);

    if ($numero === '' || preg_match('/^[0-9]{4,20}$/', $numero) !== 1) {
        throw new InvalidArgumentException(
            'EL NUMERO DE CI ES OBLIGATORIO Y SOLO PUEDE CONTENER DIGITOS.'
        );
    }

    return $numero;
}

function validate_ci_complemento(?string $rawComplemento): ?string
{
    $complemento = normalize_ci_complemento($rawComplemento);

    if ($complemento === null) {
        return null;
    }

    if (
        ci_strlen($complemento) > 10 ||
        preg_match('/^[A-Z0-9]+$/', $complemento) !== 1
    ) {
        throw new InvalidArgumentException(
            'EL COMPLEMENTO DEL CI SOLO PUEDE CONTENER LETRAS Y NUMEROS.'
        );
    }

    return $complemento;
}

function format_user_ci(?string $ciNumero, ?string $ciComplemento): string
{
    $numero = normalize_ci_numero((string)$ciNumero);
    $complemento = normalize_ci_complemento($ciComplemento);

    if ($numero === '') {
        return '';
    }

    return $numero . ($complemento !== null ? '-' . $complemento : '');
}

?>
