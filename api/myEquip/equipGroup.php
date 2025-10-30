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
        INNER JOIN relation_user ru ON ru.group_user_id = gu.group_user_id
        INNER JOIN users u ON u.ID = ru.u_id
    ";


    $countSql = "
        SELECT COUNT(DISTINCT e.equipment_id)
        FROM equipments e
        INNER JOIN relation_group rg ON rg.subcategory_id = e.subcategory_id
        INNER JOIN group_user gu ON gu.group_user_id = rg.group_user_id
        INNER JOIN relation_user ru ON ru.group_user_id = gu.group_user_id
        INNER JOIN users u ON u.ID = ru.u_id
    ";


    $whereClause = "
        WHERE e.active = 1
        AND ru.u_id = :user_id
        AND (
            (gu.type = 'ผู้ดูแลหลัก' AND u.department_id IS NOT NULL)
            OR e.dep_join = u.department_id
        )
    ";

    $additionalParams = [
        ':user_id' => $user_id
    ];

    $search = trim($input['search'] ?? '');
    $searchFields = ['e.name', 'e.asset_code', 'e.status', 'e.record_status', 'gu.group_name'];
    
    if ($search !== '') {
        $whereClause .= " AND (" . implode(" LIKE :search OR ", $searchFields) . " LIKE :search)";
        $additionalParams[':search'] = "%$search%";
    }


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
        }
    }

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}