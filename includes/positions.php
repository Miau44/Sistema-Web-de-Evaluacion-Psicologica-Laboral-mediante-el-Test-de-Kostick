<?php
declare(strict_types=1);

function ensure_positions_schema(PDO $conn): void
{
    static $initialized = false;

    if ($initialized) {
        return;
    }

    if ($conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS positions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                normalized_name VARCHAR(255) NOT NULL UNIQUE,
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        foreach (['position_id' => 'INTEGER NULL', 'position_name' => 'VARCHAR(255) NULL'] as $column => $definition) {
            $check = $conn->prepare(
                'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
            );
            $check->execute([':table' => 'users', ':column' => $column]);
            if (!$check->fetchColumn()) {
                $conn->exec("ALTER TABLE users ADD COLUMN `{$column}` {$definition}");
            }
        }

        $foreignKey = $conn->prepare(
            'SELECT 1 FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = :table AND constraint_name = :name AND constraint_type = \'FOREIGN KEY\''
        );
        $foreignKey->execute([':table' => 'users', ':name' => 'fk_users_position_id']);
        if (!$foreignKey->fetchColumn()) {
            $conn->exec('ALTER TABLE users ADD CONSTRAINT fk_users_position_id FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE SET NULL');
        }

        foreach (['idx_positions_enabled' => ['positions', 'is_enabled'], 'idx_users_position_id' => ['users', 'position_id']] as $index => [$table, $column]) {
            $check = $conn->prepare(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :name'
            );
            $check->execute([':table' => $table, ':name' => $index]);
            if (!$check->fetchColumn()) {
                $conn->exec("CREATE INDEX `{$index}` ON `{$table}` (`{$column}`)");
            }
        }
    } else {
        $conn->exec(
            "CREATE TABLE IF NOT EXISTS positions (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                normalized_name VARCHAR(255) NOT NULL UNIQUE,
                is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )"
        );

        $conn->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS position_id INTEGER");
        $conn->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS position_name VARCHAR(255)");

        $conn->exec(
            "DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'fk_users_position_id'
                ) THEN
                    ALTER TABLE users
                    ADD CONSTRAINT fk_users_position_id
                    FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE SET NULL;
                END IF;
            END $$;"
        );

        $conn->exec("CREATE INDEX IF NOT EXISTS idx_positions_enabled ON positions(is_enabled)");
        $conn->exec("CREATE INDEX IF NOT EXISTS idx_positions_normalized_name ON positions(normalized_name)");
        $conn->exec("CREATE INDEX IF NOT EXISTS idx_users_position_id ON users(position_id)");
    }

    $initialized = true;
}

function position_db_bool_to_php($value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_int($value)) {
        return $value === 1;
    }

    if (is_string($value)) {
        return in_array(strtolower($value), ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }

    return !empty($value);
}

function normalize_position_row(array $row): array
{
    if (array_key_exists('id', $row)) {
        $row['id'] = (int)$row['id'];
    }

    if (array_key_exists('is_enabled', $row)) {
        $row['is_enabled'] = position_db_bool_to_php($row['is_enabled']);
    }

    if (array_key_exists('users_count', $row)) {
        $row['users_count'] = (int)$row['users_count'];
    }

    return $row;
}

function position_string_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
}

function position_to_lower(string $value): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }

    return strtolower($value);
}

function normalize_position_name(string $name): string
{
    $collapsed = preg_replace('/\s+/u', ' ', $name);
    $collapsed = $collapsed === null ? $name : $collapsed;

    return position_to_lower(trim($collapsed));
}

function is_valid_position_name(string $name): bool
{
    if ($name === '' || position_string_length($name) > 255) {
        return false;
    }

    if (preg_match('/[\p{L}\p{N}]/u', $name) !== 1) {
        return false;
    }

    return preg_match("/^[\p{L}\p{N}\s\.\,\-\/&()#+']+$/u", $name) === 1;
}

function validate_position_name(string $rawName): string
{
    $normalizedName = normalize_position_name($rawName);

    if (!is_valid_position_name($normalizedName)) {
        throw new InvalidArgumentException(
            'El nombre del puesto es obligatorio y solo puede contener letras, numeros y signos comunes.'
        );
    }

    return $normalizedName;
}

function get_enabled_positions(PDO $conn): array
{
    ensure_positions_schema($conn);

    $stmt = $conn->query(
        "SELECT id, name
         FROM positions
         WHERE is_enabled = TRUE
         ORDER BY name ASC, id ASC"
    );

    $rows = $stmt->fetchAll();

    return array_map('normalize_position_row', $rows);
}

function get_all_positions(PDO $conn): array
{
    ensure_positions_schema($conn);

    $stmt = $conn->query(
        "SELECT
            p.id,
            p.name,
            p.is_enabled,
            p.created_at,
            p.updated_at,
            COUNT(u.id) AS users_count
        FROM positions p
        LEFT JOIN users u ON u.position_id = p.id
        GROUP BY p.id, p.name, p.is_enabled, p.created_at, p.updated_at
        ORDER BY p.name ASC, p.id ASC"
    );

    $rows = $stmt->fetchAll();

    return array_map('normalize_position_row', $rows);
}

function get_position_by_id(PDO $conn, int $positionId): ?array
{
    ensure_positions_schema($conn);

    $stmt = $conn->prepare(
        "SELECT id, name, normalized_name, is_enabled, created_at, updated_at
         FROM positions
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $positionId]);
    $row = $stmt->fetch();

    return $row ? normalize_position_row($row) : null;
}

function get_enabled_position_by_id(PDO $conn, int $positionId): ?array
{
    ensure_positions_schema($conn);

    $stmt = $conn->prepare(
        "SELECT id, name, normalized_name, is_enabled
         FROM positions
         WHERE id = :id AND is_enabled = TRUE
         LIMIT 1"
    );
    $stmt->execute([':id' => $positionId]);
    $row = $stmt->fetch();

    return $row ? normalize_position_row($row) : null;
}

function create_position(PDO $conn, string $rawName, bool $isEnabled = true): array
{
    ensure_positions_schema($conn);

    $normalizedName = validate_position_name($rawName);

    $existsStmt = $conn->prepare(
        "SELECT id, name
         FROM positions
         WHERE normalized_name = :normalized_name
         LIMIT 1"
    );
    $existsStmt->execute([':normalized_name' => $normalizedName]);
    $existing = $existsStmt->fetch();

    if ($existing) {
        throw new InvalidArgumentException(
            "El puesto '{$existing['name']}' ya existe."
        );
    }

    $isMysql = $conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $insertSql = "INSERT INTO positions (name, normalized_name, is_enabled)
                  VALUES (:name, :normalized_name, :is_enabled)";
    if (!$isMysql) {
        $insertSql .= ' RETURNING id, name, is_enabled, created_at, updated_at';
    }
    $insertStmt = $conn->prepare($insertSql);
    $insertStmt->bindValue(':name', $normalizedName, PDO::PARAM_STR);
    $insertStmt->bindValue(':normalized_name', $normalizedName, PDO::PARAM_STR);
    $insertStmt->bindValue(':is_enabled', $isEnabled, PDO::PARAM_BOOL);
    $insertStmt->execute();

    if ($isMysql) {
        $createdStmt = $conn->prepare(
            'SELECT id, name, is_enabled, created_at, updated_at FROM positions WHERE id = :id'
        );
        $createdStmt->execute([':id' => $conn->lastInsertId()]);
        $created = $createdStmt->fetch();
    } else {
        $created = $insertStmt->fetch();
    }

    if (!$created) {
        throw new RuntimeException('No se pudo crear el puesto.');
    }

    return normalize_position_row($created);
}

function update_position(PDO $conn, int $positionId, string $rawName, bool $isEnabled): array
{
    ensure_positions_schema($conn);

    if ($positionId < 1) {
        throw new InvalidArgumentException('ID de puesto invalido.');
    }

    $current = get_position_by_id($conn, $positionId);
    if (!$current) {
        throw new RuntimeException('El puesto solicitado no existe.');
    }

    $normalizedName = validate_position_name($rawName);

    $existsStmt = $conn->prepare(
        "SELECT id, name
         FROM positions
         WHERE normalized_name = :normalized_name
           AND id <> :id
         LIMIT 1"
    );
    $existsStmt->execute([
        ':normalized_name' => $normalizedName,
        ':id' => $positionId,
    ]);
    $existing = $existsStmt->fetch();

    if ($existing) {
        throw new InvalidArgumentException(
            "El puesto '{$existing['name']}' ya existe."
        );
    }

    $isMysql = $conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $updateSql = "UPDATE positions
                  SET name = :name,
                      normalized_name = :normalized_name,
                      is_enabled = :is_enabled,
                      updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id";
    if (!$isMysql) {
        $updateSql .= ' RETURNING id, name, is_enabled, created_at, updated_at';
    }
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->bindValue(':id', $positionId, PDO::PARAM_INT);
    $updateStmt->bindValue(':name', $normalizedName, PDO::PARAM_STR);
    $updateStmt->bindValue(':normalized_name', $normalizedName, PDO::PARAM_STR);
    $updateStmt->bindValue(':is_enabled', $isEnabled, PDO::PARAM_BOOL);
    $updateStmt->execute();

    if ($isMysql) {
        $updated = get_position_by_id($conn, $positionId);
    } else {
        $updated = $updateStmt->fetch();
    }

    if (!$updated) {
        throw new RuntimeException('No se pudo actualizar el puesto.');
    }

    return normalize_position_row($updated);
}

function set_position_enabled(PDO $conn, int $positionId, bool $isEnabled): array
{
    ensure_positions_schema($conn);

    if ($positionId < 1) {
        throw new InvalidArgumentException('ID de puesto invalido.');
    }

    $isMysql = $conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $updateSql = "UPDATE positions
                  SET is_enabled = :is_enabled,
                      updated_at = CURRENT_TIMESTAMP
                  WHERE id = :id";
    if (!$isMysql) {
        $updateSql .= ' RETURNING id, name, is_enabled, created_at, updated_at';
    }
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->bindValue(':id', $positionId, PDO::PARAM_INT);
    $updateStmt->bindValue(':is_enabled', $isEnabled, PDO::PARAM_BOOL);
    $updateStmt->execute();

    if ($isMysql) {
        $updated = get_position_by_id($conn, $positionId);
    } else {
        $updated = $updateStmt->fetch();
    }

    if (!$updated) {
        throw new RuntimeException('No se pudo actualizar el estado del puesto.');
    }

    return normalize_position_row($updated);
}
?>
