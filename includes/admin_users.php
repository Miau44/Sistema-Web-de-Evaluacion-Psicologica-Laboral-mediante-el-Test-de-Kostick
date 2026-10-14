<?php
declare(strict_types=1);

require_once __DIR__ . '/user_identity.php';

function get_admin_user_listing_filters(array $source): array
{
    $allowedResults = ['', 'alto', 'promedio', 'bajo'];
    $allowedStatuses = ['', 'completed', 'in_progress'];
    $allowedDates = ['', 'today', '7days', 'month', 'custom'];
    $allowedSort = ['u.id', 'u.name', 'full_name', 't.start_datetime', 't.end_datetime'];

    $search = mb_substr(trim((string)($source['search'] ?? '')), 0, 120, 'UTF-8');
    $filterDate = (string)($source['filter_date'] ?? '');
    $filterResult = (string)($source['filter_result'] ?? '');
    $filterStatus = (string)($source['filter_status'] ?? '');
    $sortBy = (string)($source['sort_by'] ?? 'u.id');
    $sortOrder = strtoupper((string)($source['sort_order'] ?? 'DESC'));
    $page = max(1, (int)($source['page'] ?? 1));
    $perPage = min(100, max(1, (int)($source['per_page'] ?? 20)));
    $startDate = trim((string)($source['start_date'] ?? ''));
    $endDate = trim((string)($source['end_date'] ?? ''));
    $export = (string)($source['export'] ?? '');

    if (!in_array($filterDate, $allowedDates, true)) {
        $filterDate = '';
    }

    if (!in_array($filterResult, $allowedResults, true)) {
        $filterResult = '';
    }

    if (!in_array($filterStatus, $allowedStatuses, true)) {
        $filterStatus = '';
    }

    if (!in_array($sortBy, $allowedSort, true)) {
        $sortBy = 'u.id';
    } elseif ($sortBy === 'u.name') {
        $sortBy = 'full_name';
    }

    if (!in_array($sortOrder, ['ASC', 'DESC'], true)) {
        $sortOrder = 'DESC';
    }

    if ($filterDate !== 'custom') {
        $startDate = '';
        $endDate = '';
    }

    return [
        'search' => $search,
        'filter_date' => $filterDate,
        'filter_result' => $filterResult,
        'filter_status' => $filterStatus,
        'sort_by' => $sortBy,
        'sort_order' => $sortOrder,
        'page' => $page,
        'per_page' => $perPage,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'export' => $export,
    ];
}

function build_admin_user_listing_where(array $filters, bool $isMysql = false): array
{
    $where = 'WHERE 1=1';
    $params = [];

    if ($filters['search'] !== '') {
        $searchFields = $isMysql
            ? [
                "COALESCE(u.name, '')",
                "COALESCE(u.apellido_paterno, '')",
                "COALESCE(u.apellido_materno, '')",
                "COALESCE(u.apellido_casada, '')",
                "CONCAT_WS(' ', u.name, u.apellido_paterno, u.apellido_materno)",
                "CONCAT_WS(' ', u.name, u.apellido_paterno, u.apellido_materno, u.apellido_casada)",
                "COALESCE(u.ci_numero, '')",
                "COALESCE(u.ci_complemento, '')",
                "CONCAT(COALESCE(u.ci_numero, ''), CASE WHEN u.ci_complemento IS NOT NULL THEN CONCAT('-', u.ci_complemento) ELSE '' END)",
                'CAST(u.age AS CHAR)',
                "COALESCE(u.profession, '')",
                "COALESCE(u.position_name, '')",
                'CAST(t.id AS CHAR)',
            ]
            : [
                'u.name',
                "COALESCE(u.apellido_paterno, '')",
                "COALESCE(u.apellido_materno, '')",
                "COALESCE(u.apellido_casada, '')",
                "CONCAT_WS(' ', u.name, u.apellido_paterno, u.apellido_materno)",
                "CONCAT_WS(' ', u.name, u.apellido_paterno, u.apellido_materno, u.apellido_casada)",
                'u.ci_numero',
                "COALESCE(u.ci_complemento, '')",
                "(u.ci_numero || CASE WHEN u.ci_complemento IS NOT NULL THEN '-' || u.ci_complemento ELSE '' END)",
                'CAST(u.age AS TEXT)',
                'u.profession',
                'u.position_name',
                'CAST(t.id AS TEXT)',
            ];
        $searchConditions = [];
        foreach ($searchFields as $index => $field) {
            $placeholder = ':search_' . $index;
            $searchConditions[] = $field . ($isMysql ? ' LIKE ' : ' ILIKE ') . $placeholder;
            $params[$placeholder] = '%' . $filters['search'] . '%';
        }
        $where .= ' AND (' . implode(' OR ', $searchConditions) . ')';
    }

    if ($filters['filter_date'] === 'today') {
        $where .= ' AND DATE(t.start_datetime) = CURRENT_DATE';
    } elseif ($filters['filter_date'] === '7days') {
        $where .= $isMysql
            ? ' AND t.start_datetime >= DATE_SUB(CURRENT_DATE, INTERVAL 7 DAY)'
            : " AND t.start_datetime >= CURRENT_DATE - INTERVAL '7 days'";
    } elseif ($filters['filter_date'] === 'month') {
        $where .= $isMysql
            ? ' AND t.start_datetime >= DATE_SUB(CURRENT_DATE, INTERVAL 1 MONTH)'
            : " AND t.start_datetime >= CURRENT_DATE - INTERVAL '1 month'";
    } elseif (
        $filters['filter_date'] === 'custom'
        && $filters['start_date'] !== ''
        && $filters['end_date'] !== ''
    ) {
        $where .= ' AND t.start_datetime >= :start_date AND t.start_datetime <= :end_date';
        $params[':start_date'] = $filters['start_date'];
        $params[':end_date'] = $filters['end_date'];
    }

    if ($filters['filter_status'] === 'completed') {
        $where .= ' AND t.end_datetime IS NOT NULL';
    } elseif ($filters['filter_status'] === 'in_progress') {
        $where .= ' AND t.start_datetime IS NOT NULL AND t.end_datetime IS NULL';
    }

    return [$where, $params];
}

function admin_user_matches_result_filter(array $user, string $filterResult): bool
{
    if ($filterResult === '') {
        return true;
    }

    if (empty($user['levels'])) {
        return false;
    }

    $levels = explode(',', (string)$user['levels']);
    foreach ($levels as $levelPair) {
        $parts = explode(':', $levelPair, 2);
        if (count($parts) === 2 && $parts[1] === $filterResult) {
            return true;
        }
    }

    return false;
}

function fetch_admin_user_listing_rows(PDO $conn, array $filters): array
{
    ensure_user_identity_schema($conn);

    $isMysql = $conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    [$where, $params] = build_admin_user_listing_where($filters, $isMysql);
    $levelsSql = $isMysql
        ? "(SELECT GROUP_CONCAT(CONCAT(scale, ':', level) ORDER BY scale SEPARATOR ',') FROM results WHERE test_id = t.id)"
        : "(SELECT STRING_AGG(scale || ':' || level, ',' ORDER BY scale) FROM results WHERE test_id = t.id)";

    $stmt = $conn->prepare(
        "SELECT
            u.id AS user_id,
            u.name,
            u.apellido_paterno,
            u.apellido_materno,
            u.apellido_casada,
            CONCAT_WS(' ', u.name, u.apellido_paterno, u.apellido_materno) AS full_name,
            u.ci_numero,
            u.ci_complemento,
            u.age,
            u.profession,
            u.position_name,
            t.id AS test_id,
            t.start_datetime,
            t.end_datetime,
            t.duration,
            CASE
                WHEN t.id IS NOT NULL AND EXISTS (
                    SELECT 1 FROM results r WHERE r.test_id = t.id
                ) THEN 1
                ELSE 0
            END AS has_results,
            {$levelsSql} AS levels
        FROM users u
        LEFT JOIN tests t ON t.id = (
            SELECT t2.id
            FROM tests t2
            WHERE t2.user_id = u.id
            ORDER BY t2.id DESC
            LIMIT 1
        )
        $where
        ORDER BY {$filters['sort_by']} {$filters['sort_order']}"
    );

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $rows = $stmt->fetchAll();

    $filteredRows = array_filter($rows, function ($user) use ($filters) {
        return admin_user_matches_result_filter($user, $filters['filter_result']);
    });

    return array_values($filteredRows);
}

function get_admin_user_listing_page(PDO $conn, array $filters): array
{
    $allUsers = fetch_admin_user_listing_rows($conn, $filters);
    $total = count($allUsers);
    $perPage = (int)$filters['per_page'];
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min((int)$filters['page'], $totalPages);
    $offset = ($page - 1) * $perPage;

    return [
        'all_users' => $allUsers,
        'users' => array_slice($allUsers, $offset, $perPage),
        'total' => $total,
        'total_pages' => $totalPages,
        'page' => $page,
    ];
}

function get_admin_user_export_headers(): array
{
    return [
        'ID',
        'NOMBRE',
        'APELLIDO PATERNO',
        'APELLIDO MATERNO',
        'APELLIDO DE CASADA',
        'NOMBRE COMPLETO',
        'CI',
        'PROFESION',
        'PUESTO',
        'TEST ID',
        'FECHA INICIO',
        'ESTADO',
    ];
}

function get_admin_user_status_label(array $user): string
{
    if (!empty($user['end_datetime'])) {
        return 'COMPLETADO';
    }

    if (!empty($user['start_datetime'])) {
        return 'EN PROGRESO';
    }

    return '-';
}

function build_admin_user_export_rows(array $users): array
{
    $rows = [];

    foreach ($users as $user) {
        $rows[] = [
            (string)((int)($user['user_id'] ?? 0)),
            app_to_upper((string)($user['name'] ?? '')),
            app_to_upper((string)($user['apellido_paterno'] ?? '')),
            app_to_upper((string)($user['apellido_materno'] ?? '')),
            app_to_upper((string)($user['apellido_casada'] ?? '')),
            app_to_upper(build_user_full_name(
                (string)($user['name'] ?? ''),
                isset($user['apellido_paterno']) ? (string)$user['apellido_paterno'] : null,
                isset($user['apellido_materno']) ? (string)$user['apellido_materno'] : null
            )),
            app_to_upper(format_user_ci(
                isset($user['ci_numero']) ? (string)$user['ci_numero'] : '',
                isset($user['ci_complemento']) ? (string)$user['ci_complemento'] : null
            )),
            app_to_upper((string)($user['profession'] ?? '')),
            app_to_upper((string)($user['position_name'] ?? '')),
            !empty($user['test_id']) ? (string)((int)$user['test_id']) : '-',
            (string)($user['start_datetime'] ?? '-'),
            get_admin_user_status_label($user),
        ];
    }

    return $rows;
}
?>
