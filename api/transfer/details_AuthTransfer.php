<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit;
}

try {
    $u_id = $decoded->data->ID ?? null;
    if (!$u_id) {
        throw new Exception("User ID not found");
    }
    
    $u_id = (int)$u_id;
    if ($u_id <= 0) {
        throw new Exception("Invalid User ID");
    }

    $params = [
        ':u_id' => $u_id
    ];

    $baseWhere = "
        ru.u_id = :u_id 
        AND gu.type = 'ผู้ดูแลหลัก'
        AND e.active = 1
        AND (
            et.equipment_id IS NULL 
            OR et.status != 0
        )";
    
    $searchWhere = '';
    if (!empty($_POST['keyword'])) {
        $keyword = trim($_POST['keyword']);
        $searchWhere .= " AND e.name LIKE :keyword";
        $params[':keyword'] = "%{$keyword}%";
    }

    $joinTables = "
        FROM equipments e
        INNER JOIN equipment_subcategories es ON e.subcategory_id = es.subcategory_id
        INNER JOIN relation_group rg ON es.subcategory_id = rg.subcategory_id
        INNER JOIN group_user gu ON rg.group_user_id = gu.group_user_id
        INNER JOIN relation_user ru ON gu.group_user_id = ru.group_user_id
        LEFT JOIN departments d ON e.location_department_id = d.department_id
        LEFT JOIN (
            SELECT 
                equipment_id, 
                status,
                ROW_NUMBER() OVER (PARTITION BY equipment_id ORDER BY transfer_id DESC) as rn
            FROM equipment_transfers
        ) et ON e.equipment_id = et.equipment_id AND et.rn = 1
        WHERE $baseWhere $searchWhere
    ";

    $countSQL = "SELECT COUNT(DISTINCT e.equipment_id) as total $joinTables";
    error_log("DEBUG: COUNT SQL => $countSQL");

    $countStmt = $dbh->prepare($countSQL);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalItems = (int)$countStmt->fetchColumn();

    $dataSQL = "
        SELECT DISTINCT
            e.equipment_id,
            e.name as equipment_name,
            e.asset_code,
            e.subcategory_id,
            es.name as subcategory_name,
            es.category_id,
            e.location_department_id,
            e.location_details,
            e.status,
            d.department_name as location_department_name,
            e.updated_at,
            CASE 
                WHEN et.equipment_id IS NULL THEN 'available'
                WHEN et.status = 0 THEN 'in_transfer'
                ELSE 'available'
            END as transfer_status
        $joinTables
        ORDER BY e.updated_at DESC
    ";
    error_log("DEBUG: DATA SQL => $dataSQL");

    $dataStmt = $dbh->prepare($dataSQL);
    foreach ($params as $key => $value) {
        $dataStmt->bindValue($key, $value);
    }
    $dataStmt->execute();

    $equipment_list = [];
    $subcategory_ids = [];
    while ($row = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
        $equipment_list[] = [
            "equipment_id" => (int)$row['equipment_id'],
            "asset_code" => $row['asset_code'],
            "equipment_name" => $row['equipment_name'],
            "subcategory_id" => (int)$row['subcategory_id'],
            "subcategory_name" => $row['subcategory_name'],
            "location_department_id" => $row['location_department_id'] ? (int)$row['location_department_id'] : null,
            "location_department_name" => $row['location_department_name'],
            "location_details" => $row['location_details'],
            "category_id" => (int)$row['category_id']
        ];
        $subcategory_ids[] = (int)$row['subcategory_id'];
    }


    $admins_data = [];
    if (!empty($subcategory_ids)) {
        $subcategory_ids = array_unique($subcategory_ids);
        $placeholders = str_repeat('?,', count($subcategory_ids) - 1) . '?';

        $adminSQL = "
            SELECT DISTINCT
                es.subcategory_id,
                gu.group_user_id as group_id,
                gu.group_name,
                gu.type as group_type,
                u.ID,
                u.user_id,
                u.full_name,
                ud.department_name
            FROM equipment_subcategories es
            INNER JOIN relation_group rg ON es.subcategory_id = rg.subcategory_id
            INNER JOIN group_user gu ON rg.group_user_id = gu.group_user_id
            INNER JOIN relation_user ru ON gu.group_user_id = ru.group_user_id
            INNER JOIN users u ON ru.u_id = u.ID
            LEFT JOIN departments ud ON u.department_id = ud.department_id
            WHERE es.subcategory_id IN ($placeholders)
                AND gu.type = 'ผู้ดูแลหลัก'
            ORDER BY es.subcategory_id, gu.group_user_id, u.ID
        ";
        error_log("DEBUG: ADMIN SQL => $adminSQL");

        $adminStmt = $dbh->prepare($adminSQL);
        $adminStmt->execute($subcategory_ids);

        while ($admin = $adminStmt->fetch(PDO::FETCH_ASSOC)) {
            $subcategory_id = (int)$admin['subcategory_id'];
            $group_id = (int)$admin['group_id'];

            if (!isset($admins_data[$subcategory_id])) {
                $admins_data[$subcategory_id] = [];
            }
            if (!isset($admins_data[$subcategory_id][$group_id])) {
                $admins_data[$subcategory_id][$group_id] = [
                    "group_id" => $group_id,
                    "group_name" => $admin['group_name'],
                    "group_type" => $admin['group_type'],
                    "user_group" => []
                ];
            }
            $admins_data[$subcategory_id][$group_id]["user_group"][] = [
                "ID" => (int)$admin['ID'],
                "user_id" => $admin['user_id'],
                "full_name" => $admin['full_name'],
                "department_name" => $admin['department_name']
            ];
        }
    }

    foreach ($equipment_list as &$equipment) {
        $subcategory_id = $equipment['subcategory_id'];
        $equipment['admins'] = [];
        if (isset($admins_data[$subcategory_id])) {
            $equipment['admins'] = array_values($admins_data[$subcategory_id]);
        }
    }

    echo json_encode([
        "status" => "success",
        "data" => $equipment_list
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Database error: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
