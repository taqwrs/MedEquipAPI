<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

include "../config/jwt.php";
include "../config/pagination_helper.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$user_id = $decoded->data->ID ?? null;
if (!$user_id) {
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit;
}

$stmt = $dbh->prepare("
    SELECT department_id
    FROM users
    WHERE ID = :user_id
    LIMIT 1
");
$stmt->execute([':user_id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo json_encode(["status" => "error", "message" => "User not found"]);
    exit;
}

try {
    $baseSql = "
        SELECT
            e.equipment_id,
            e.asset_code,
            e.name,
            e.end_date,
            e.status,
            e.record_status,
            gu.group_name AS group_user_name
        FROM equipments e
        INNER JOIN relation_group rg ON rg.subcategory_id = e.subcategory_id
        INNER JOIN group_user gu ON gu.group_user_id = rg.group_user_id
    ";

    $countSql = "SELECT COUNT(DISTINCT e.equipment_id) FROM equipments e";

    $whereClause = "
        WHERE e.active = 1
        AND e.location_department_id = :user_department_id
    ";

    $additionalParams = [
        ':user_department_id' => $user['department_id']
    ];

    $search = trim($input['search'] ?? '');
    $searchFields = ['e.name', 'e.asset_code', 'e.end_date', 'e.status', 'e.record_status'];
    $record_status = trim($input['record_status'] ?? '');
    if ($record_status !== '') {
        $whereClause .= " AND e.record_status = :record_status";
        $additionalParams[':record_status'] = $record_status;
    }

    $response = handlePaginatedSearch(
        $dbh,
        $input,
        $baseSql,
        $countSql,
        $searchFields,
        "GROUP BY e.equipment_id ORDER BY e.equipment_id DESC",
        $whereClause,
        $additionalParams
    );

    if ($response['status'] === 'success') {
        foreach ($response['data'] as &$row) {
            $row['end_date_display'] = $row['end_date']
                ? date('d/m/Y', strtotime($row['end_date']))
                : '-';

            $row['statusInfo'] = [
                'status' => $row['status'],
                'record_status' => $row['record_status']
            ];
        }
    }

    echo json_encode($response);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
