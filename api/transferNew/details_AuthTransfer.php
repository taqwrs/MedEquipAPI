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

    $equipment_id = isset($input['equipment_id']) ? (int) $input['equipment_id'] : null;
    if (!$equipment_id) {
        throw new Exception("Equipment ID is required");
    }

    // ดึงข้อมูล department_id ของผู้ใช้ที่ล็อกอิน
    $userDeptSQL = "SELECT department_id FROM users WHERE ID = :u_id";
    $userDeptStmt = $dbh->prepare($userDeptSQL);
    $userDeptStmt->bindValue(':u_id', $u_id, PDO::PARAM_INT);
    $userDeptStmt->execute();
    $user_department_id = $userDeptStmt->fetchColumn();

    // ดึงข้อมูลเครื่องมือ (เพิ่ม dep_join)
    $equipmentSQL = "
        SELECT 
            e.equipment_id,
            e.name as equipment_name,
            e.asset_code,
            e.subcategory_id,
            es.name as subcategory_name,
            es.category_id,
            e.location_department_id,
            e.location_details,
            e.status,
            e.dep_join,
            d.department_name as location_department_name,
            e.updated_at
        FROM equipments e
        INNER JOIN equipment_subcategories es ON e.subcategory_id = es.subcategory_id
        LEFT JOIN departments d ON e.location_department_id = d.department_id
        WHERE e.equipment_id = :equipment_id AND e.active = 1
    ";
    $equipmentStmt = $dbh->prepare($equipmentSQL);
    $equipmentStmt->bindValue(':equipment_id', $equipment_id, PDO::PARAM_INT);
    $equipmentStmt->execute();
    $equipment = $equipmentStmt->fetch(PDO::FETCH_ASSOC);

    if (!$equipment) {
        throw new Exception("Equipment not found");
    }

    $subcategory_id = (int) $equipment['subcategory_id'];
    $dep_join = $equipment['dep_join'];

    // ตรวจสอบว่ามีผู้ดูแลหลักสำหรับ subcategory นี้หรือไม่
    $checkAdminSQL = "
        SELECT COUNT(*) as admin_count
        FROM relation_group rg
        INNER JOIN group_user gu ON rg.group_user_id = gu.group_user_id
        WHERE rg.subcategory_id = :subcategory_id
            AND gu.type = 'ผู้ดูแลหลัก'
    ";
    $checkAdminStmt = $dbh->prepare($checkAdminSQL);
    $checkAdminStmt->bindValue(':subcategory_id', $subcategory_id, PDO::PARAM_INT);
    $checkAdminStmt->execute();
    $admin_count = (int) $checkAdminStmt->fetchColumn();

    $has_permission = false;
    $admins_data = [];
    $dep_join_name = null;

    // กรณีที่ 1: มีผู้ดูแลหลัก
    if ($admin_count > 0) {
        // ตรวจสอบว่าผู้ใช้อยู่ในกลุ่มผู้ดูแลหลักหรือไม่
        $checkUserInGroupSQL = "
            SELECT COUNT(*) as in_group
            FROM relation_group rg
            INNER JOIN group_user gu ON rg.group_user_id = gu.group_user_id
            INNER JOIN relation_user ru ON gu.group_user_id = ru.group_user_id
            WHERE rg.subcategory_id = :subcategory_id
                AND gu.type = 'ผู้ดูแลหลัก'
                AND ru.u_id = :u_id
        ";
        $checkUserStmt = $dbh->prepare($checkUserInGroupSQL);
        $checkUserStmt->bindValue(':subcategory_id', $subcategory_id, PDO::PARAM_INT);
        $checkUserStmt->bindValue(':u_id', $u_id, PDO::PARAM_INT);
        $checkUserStmt->execute();
        $in_group = (int) $checkUserStmt->fetchColumn();

        // เช็คสิทธิ์ 3 กรณี: ผู้ดูแลหลัก, แผนก location, หรือ แผนก dep_join
        if ($in_group > 0 || 
            ($user_department_id && $equipment['location_department_id'] == $user_department_id) ||
            ($user_department_id && $dep_join == $user_department_id)) {
            
            $has_permission = true;

            // ดึงข้อมูลผู้ดูแลหลักทั้งหมด
            $adminSQL = "
                SELECT DISTINCT
                    gu.group_user_id as group_id,
                    gu.group_name,
                    gu.type as group_type,
                    u.ID,
                    u.user_id,
                    u.full_name,
                    ud.department_name
                FROM relation_group rg
                INNER JOIN group_user gu ON rg.group_user_id = gu.group_user_id
                INNER JOIN relation_user ru ON gu.group_user_id = ru.group_user_id
                INNER JOIN users u ON ru.u_id = u.ID
                LEFT JOIN departments ud ON u.department_id = ud.department_id
                WHERE rg.subcategory_id = :subcategory_id
                    AND gu.type = 'ผู้ดูแลหลัก'
                ORDER BY gu.group_user_id, u.ID
            ";
            $adminStmt = $dbh->prepare($adminSQL);
            $adminStmt->bindValue(':subcategory_id', $subcategory_id, PDO::PARAM_INT);
            $adminStmt->execute();

            while ($admin = $adminStmt->fetch(PDO::FETCH_ASSOC)) {
                $group_id = (int) $admin['group_id'];

                $found = false;
                foreach ($admins_data as &$group) {
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
                    $admins_data[] = [
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

            // ถ้ามี dep_join ให้ดึงชื่อแผนก
            if ($dep_join) {
                $deptSQL = "SELECT department_name FROM departments WHERE department_id = :dept_id";
                $deptStmt = $dbh->prepare($deptSQL);
                $deptStmt->bindValue(':dept_id', $dep_join, PDO::PARAM_INT);
                $deptStmt->execute();
                $dep_join_name = $deptStmt->fetchColumn();
            }
        }
    } 
    // กรณีที่ 2: ไม่มีผู้ดูแลหลัก
    else {
        // เช็คว่า transfer status ไม่เท่ากับ 0
        $checkTransferSQL = "
            SELECT COUNT(*) as transfer_count
            FROM equipment_transfers
            WHERE equipment_id = :equipment_id AND status != 0
        ";
        $checkTransferStmt = $dbh->prepare($checkTransferSQL);
        $checkTransferStmt->bindValue(':equipment_id', $equipment_id, PDO::PARAM_INT);
        $checkTransferStmt->execute();
        $transfer_valid = (int) $checkTransferStmt->fetchColumn() > 0;

        // ให้สิทธิ์ถ้า: แผนก location ตรงกัน หรือ แผนก dep_join ตรงกัน
        if ($user_department_id && 
            ($equipment['location_department_id'] == $user_department_id || 
             $dep_join == $user_department_id) &&
            $transfer_valid) {
            
            $has_permission = true;
            $admins_data = []; // ไม่มีผู้ดูแลหลัก

            // ถ้ามี dep_join ให้ดึงชื่อแผนก
            if ($dep_join) {
                $deptSQL = "SELECT department_name FROM departments WHERE department_id = :dept_id";
                $deptStmt = $dbh->prepare($deptSQL);
                $deptStmt->bindValue(':dept_id', $dep_join, PDO::PARAM_INT);
                $deptStmt->execute();
                $dep_join_name = $deptStmt->fetchColumn();
            }
        }
    }

    // ถ้าไม่มีสิทธิ์เข้าถึง
    if (!$has_permission) {
        throw new Exception("You do not have permission to view this equipment");
    }

    // จัดรูปแบบข้อมูลส่งกลับ
    $result = [
        "equipment_id" => (int) $equipment['equipment_id'],
        "asset_code" => $equipment['asset_code'],
        "equipment_name" => $equipment['equipment_name'],
        "subcategory_id" => (int) $equipment['subcategory_id'],
        "subcategory_name" => $equipment['subcategory_name'],
        "category_id" => (int) $equipment['category_id'],
        "location_department_id" => $equipment['location_department_id'] ? (int) $equipment['location_department_id'] : null,
        "location_department_name" => $equipment['location_department_name'],
        "location_details" => $equipment['location_details'],
        "status" => $equipment['status'],
        "updated_at" => $equipment['updated_at'],
        "admins" => $admins_data,
        "has_admin" => $admin_count > 0,
        "dep_join" => $dep_join ? (int) $dep_join : null,
        "dep_join_name" => $dep_join_name
    ];

    echo json_encode([
        "status" => "success",
        "data" => [$result]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>