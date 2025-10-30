<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

try {
    $input = json_decode(file_get_contents("php://input"), true);
    $u_id = $decoded->data->ID ?? null;
    if (!$u_id) {
        throw new Exception("User ID not found");
    }

    $u_id = (int) $u_id;
    if ($u_id <= 0) {
        throw new Exception("Invalid User ID");
    }

    // ดึง department_id ของผู้ใช้งาน
    $userDeptSQL = "SELECT department_id FROM users WHERE ID = :u_id";
    $userDeptStmt = $dbh->prepare($userDeptSQL);
    $userDeptStmt->bindValue(':u_id', $u_id, PDO::PARAM_INT);
    $userDeptStmt->execute();
    $user_department_id = $userDeptStmt->fetchColumn();

    $params = [':u_id' => $u_id];
    if ($user_department_id) {
        $params[':user_dept_id'] = (int) $user_department_id;
    }

    $searchWhere = '';
    if (!empty($input['keyword'])) {
        $keyword = trim($input['keyword']);
        $searchWhere = " AND e.name LIKE :keyword";
        $params[':keyword'] = "%{$keyword}%";
    }

    // สร้าง WHERE clause ตามเงื่อนไขใหม่
    $conditionSQL = "
        FROM equipments e
        INNER JOIN equipment_subcategories es ON e.subcategory_id = es.subcategory_id
        LEFT JOIN departments d ON e.location_department_id = d.department_id
        LEFT JOIN (
            SELECT 
                t1.equipment_id,
                t1.status
            FROM equipment_transfers t1
            INNER JOIN (
                SELECT equipment_id, MAX(transfer_id) AS latest_transfer_id
                FROM equipment_transfers
                GROUP BY equipment_id
            ) t2 ON t1.equipment_id = t2.equipment_id AND t1.transfer_id = t2.latest_transfer_id
        ) et ON e.equipment_id = et.equipment_id
        WHERE e.active = 1
        AND (
            -- เงื่อนไข 1: equipment ที่อยู่ใน subcategory ที่มีผู้ดูแลหลัก และ status = 1
            (
                EXISTS (
                    SELECT 1
                    FROM relation_group rg
                    INNER JOIN group_user gu ON rg.group_user_id = gu.group_user_id
                    INNER JOIN relation_user ru ON gu.group_user_id = ru.group_user_id
                    WHERE rg.subcategory_id = e.subcategory_id
                    AND gu.type = 'ผู้ดูแลหลัก'
                    AND ru.u_id = :u_id
                )
                AND (et.status = 1 OR et.equipment_id IS NULL)
            )
            OR
            -- เงื่อนไข 2: equipment ที่ location_department_id = user department 
            -- และ subcategory ไม่มีผู้ดูแลหลัก
            " . ($user_department_id ? "
            (
                e.location_department_id = :user_dept_id
                AND NOT EXISTS (
                    SELECT 1
                    FROM relation_group rg
                    INNER JOIN group_user gu ON rg.group_user_id = gu.group_user_id
                    WHERE rg.subcategory_id = e.subcategory_id
                    AND gu.type = 'ผู้ดูแลหลัก'
                )
            )
            OR
            -- เงื่อนไข 3: equipment ที่ dep_join = user department และ status != 0
            (
                e.dep_join = :user_dept_id
                AND (et.status IS NULL OR et.status != 0)
            )
            " : "1=0") . "
        )
        $searchWhere
    ";

    // นับจำนวน equipment
    $countSQL = "SELECT COUNT(DISTINCT e.equipment_id) as total $conditionSQL";
    $countStmt = $dbh->prepare($countSQL);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $countStmt->execute();
    $totalItems = (int) $countStmt->fetchColumn();

    // ดึงข้อมูล equipment
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
            e.dep_join,
            e.status,
            d.department_name as location_department_name,
            e.updated_at,
            CASE 
                WHEN et.equipment_id IS NULL THEN 'available'
                WHEN et.status = 0 THEN 'in_transfer'
                WHEN et.status = 1 THEN 'transferred'
                ELSE 'available'
            END as transfer_status,
            et.status as transfer_status_code
        $conditionSQL
        ORDER BY e.updated_at DESC
    ";
    
    $dataStmt = $dbh->prepare($dataSQL);
    foreach ($params as $key => $value) {
        $dataStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $dataStmt->execute();

    $equipment_list = [];
    $subcategory_ids = [];
    while ($row = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
        $equipment_list[] = [
            "equipment_id" => (int) $row['equipment_id'],
            "asset_code" => $row['asset_code'],
            "equipment_name" => $row['equipment_name'],
            "subcategory_id" => (int) $row['subcategory_id'],
            "subcategory_name" => $row['subcategory_name'],
            "location_department_id" => $row['location_department_id'] ? (int) $row['location_department_id'] : null,
            "location_department_name" => $row['location_department_name'],
            "location_details" => $row['location_details'],
            "dep_join" => $row['dep_join'] ? (int) $row['dep_join'] : null,
            "category_id" => (int) $row['category_id'],
            "transfer_status" => $row['transfer_status'],
            "transfer_status_code" => $row['transfer_status_code'] !== null ? (int) $row['transfer_status_code'] : null
        ];
        $subcategory_ids[] = (int) $row['subcategory_id'];
    }

    // ดึงข้อมูล admins
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

        $adminStmt = $dbh->prepare($adminSQL);
        $adminStmt->execute(array_values($subcategory_ids));
        while ($admin = $adminStmt->fetch(PDO::FETCH_ASSOC)) {
            $subcategory_id = (int) $admin['subcategory_id'];
            $group_id = (int) $admin['group_id'];

            if (!isset($admins_data[$subcategory_id])) {
                $admins_data[$subcategory_id] = [];
            }

            $found = false;
            foreach ($admins_data[$subcategory_id] as &$group) {
                if ($group['group_id'] == $group_id) {
                    $group['user_group'][] = [
                        "ID" => (int) $admin['ID'],
                        "user_id" => $admin['user_id'],
                        "full_name" => $admin['full_name'],
                        "department_name" => $admin['department_name']
                    ];
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $admins_data[$subcategory_id][] = [
                    "group_id" => $group_id,
                    "group_name" => $admin['group_name'],
                    "group_type" => $admin['group_type'],
                    "user_group" => [
                        [
                            "ID" => (int) $admin['ID'],
                            "user_id" => $admin['user_id'],
                            "full_name" => $admin['full_name'],
                            "department_name" => $admin['department_name']
                        ]
                    ]
                ];
            }
        }
    }

    // เพิ่ม admins เข้าไปใน equipment
    foreach ($equipment_list as &$equipment) {
        $subcategory_id = $equipment['subcategory_id'];
        $equipment['admins'] = [];
        if (isset($admins_data[$subcategory_id])) {
            $equipment['admins'] = $admins_data[$subcategory_id];
        }
    }

    echo json_encode([
        "status" => "success",
        "total" => $totalItems,
        "data" => $equipment_list
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>