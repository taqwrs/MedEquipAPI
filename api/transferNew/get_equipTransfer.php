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
    
    // ดึง department_id ของผู้ใช้ที่ login อยู่
    $getUserDept = $dbh->prepare("SELECT department_id FROM users WHERE ID = :u_id");
    $getUserDept->bindParam(':u_id', $u_id, PDO::PARAM_INT);
    $getUserDept->execute();
    $userDept = $getUserDept->fetch(PDO::FETCH_ASSOC);
    
    if (!$userDept) {
        throw new Exception("User not found");
    }
    
    $user_department_id = $userDept['department_id'];
    
    $input = json_decode(file_get_contents('php://input'), true);
    $search = trim($input['search'] ?? '');
    $page = max(1, (int)($input['page'] ?? 1));
    $limit = max(1, (int)($input['limit'] ?? 5));
    $offset = ($page - 1) * $limit;
    
    $searchWhere = '';
    $params = [
        ':u_id' => $u_id,
        ':user_department_id' => $user_department_id
    ];
    
    if ($search) {
        $searchWhere = " AND (
            e.name LIKE :search 
            OR e.asset_code LIKE :search 
            OR d.department_name LIKE :search 
            OR e.location_details LIKE :search
        )";
        $params[':search'] = '%' . $search . '%';
    }
    
    // Query หลัก: ดึงเครื่องมือที่มีสิทธิ์โอน (3 เงื่อนไข OR)
    $joinTables = "
        FROM equipments e
        INNER JOIN equipment_subcategories es ON e.subcategory_id = es.subcategory_id
        LEFT JOIN departments d ON e.location_department_id = d.department_id
        WHERE e.active = 1
        AND (
            -- เงื่อนไข 1: equipment อยู่ใน subcategory ที่มีผู้ดูแลหลัก 
            -- และผู้ใช้เป็นผู้ดูแลหลักของ subcategory นั้น 
            -- และ equipment_transfers.status ไม่เท่ากับ 0
            (
                EXISTS (
                    SELECT 1
                    FROM relation_group rg1
                    INNER JOIN group_user gu1 ON rg1.group_user_id = gu1.group_user_id
                    INNER JOIN relation_user ru1 ON gu1.group_user_id = ru1.group_user_id
                    WHERE rg1.subcategory_id = e.subcategory_id
                    AND ru1.u_id = :u_id 
                    AND gu1.type = 'ผู้ดูแลหลัก'
                )
                AND NOT EXISTS (
                    SELECT 1
                    FROM equipment_transfers et1
                    WHERE et1.equipment_id = e.equipment_id
                    AND et1.status = 0
                )
            )
            
            OR
            
            -- เงื่อนไข 2: location_department_id = user.department_id 
            -- และ subcategory_id ไม่มี group_user.type = 'ผู้ดูแลหลัก'
            -- และ equipment_transfers.status ไม่เท่ากับ 0
            (
                e.location_department_id = :user_department_id
                AND NOT EXISTS (
                    SELECT 1
                    FROM relation_group rg2
                    INNER JOIN group_user gu2 ON rg2.group_user_id = gu2.group_user_id
                    WHERE rg2.subcategory_id = e.subcategory_id
                    AND gu2.type = 'ผู้ดูแลหลัก'
                )
                AND NOT EXISTS (
                    SELECT 1
                    FROM equipment_transfers et2
                    WHERE et2.equipment_id = e.equipment_id
                    AND et2.status = 0
                )
            )
            
            OR
            
            -- เงื่อนไข 3: dep_join = user.department_id 
            -- และ equipment_transfers.status ไม่เท่ากับ 0
            (
                e.dep_join = :user_department_id
                AND NOT EXISTS (
                    SELECT 1
                    FROM equipment_transfers et3
                    WHERE et3.equipment_id = e.equipment_id
                    AND et3.status = 0
                )
            )
        )
        $searchWhere
    ";

    // นับจำนวนทั้งหมด
    $countStmt = $dbh->prepare("SELECT COUNT(DISTINCT e.equipment_id) as total $joinTables");
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalItems = (int)$countStmt->fetchColumn();

    // ดึงข้อมูล พร้อมระบุสาเหตุที่มีสิทธิ์
    $dataStmt = $dbh->prepare("
        SELECT DISTINCT
            e.equipment_id,
            e.name,
            e.asset_code,
            e.subcategory_id,
            es.name as subcategory_name,
            e.location_department_id,
            e.location_details,
            e.dep_join,
            d.department_name as location_department_name,
            e.updated_at,
            -- ตรวจสอบว่าเป็นผู้ดูแลหลัก + status ไม่เท่ากับ 0
            (
                EXISTS (
                    SELECT 1
                    FROM relation_group rg1
                    INNER JOIN group_user gu1 ON rg1.group_user_id = gu1.group_user_id
                    INNER JOIN relation_user ru1 ON gu1.group_user_id = ru1.group_user_id
                    WHERE rg1.subcategory_id = e.subcategory_id
                    AND ru1.u_id = :u_id 
                    AND gu1.type = 'ผู้ดูแลหลัก'
                )
                AND NOT EXISTS (
                    SELECT 1
                    FROM equipment_transfers et1
                    WHERE et1.equipment_id = e.equipment_id
                    AND et1.status = 0
                )
            ) as is_main_admin_with_status,
            -- ตรวจสอบว่าอยู่ในแผนกและไม่มีผู้ดูแลหลัก + status ไม่เท่ากับ 0
            (
                e.location_department_id = :user_department_id
                AND NOT EXISTS (
                    SELECT 1
                    FROM relation_group rg2
                    INNER JOIN group_user gu2 ON rg2.group_user_id = gu2.group_user_id
                    WHERE rg2.subcategory_id = e.subcategory_id
                    AND gu2.type = 'ผู้ดูแลหลัก'
                )
                AND NOT EXISTS (
                    SELECT 1
                    FROM equipment_transfers et2
                    WHERE et2.equipment_id = e.equipment_id
                    AND et2.status = 0
                )
            ) as is_department_no_admin,
            -- ตรวจสอบว่า dep_join ตรงกับแผนกผู้ใช้ + status ไม่เท่ากับ 0
            (
                e.dep_join = :user_department_id
                AND NOT EXISTS (
                    SELECT 1
                    FROM equipment_transfers et3
                    WHERE et3.equipment_id = e.equipment_id
                    AND et3.status = 0
                )
            ) as is_dep_join_match
        $joinTables
        ORDER BY e.updated_at DESC
        LIMIT :limit OFFSET :offset
    ");
    
    foreach ($params as $key => $value) {
        $dataStmt->bindValue($key, $value);
    }
    $dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $dataStmt->execute();
    
    $equipment_list = [];
    while ($row = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
        $is_main_admin_with_status = (bool)$row['is_main_admin_with_status'];
        $is_department_no_admin = (bool)$row['is_department_no_admin'];
        $is_dep_join_match = (bool)$row['is_dep_join_match'];
        
        // กำหนดเหตุผล
        $reasons = [];
        if ($is_main_admin_with_status) {
            $reasons[] = "เป็นผู้ดูแลหลักและสถานะการโอนไม่เท่ากับ 0";
        }
        if ($is_department_no_admin) {
            $reasons[] = "อยู่ในแผนกเดียวกัน ไม่มีผู้ดูแลหลัก และสถานะการโอนไม่เท่ากับ 0";
        }
        if ($is_dep_join_match) {
            $reasons[] = "dep_join ตรงกับแผนกและสถานะการโอนไม่เท่ากับ 0";
        }
        
        $reason = implode(" และ ", $reasons);
        
        $equipment_list[] = [
            "equipment_id" => (int)$row['equipment_id'],
            "name" => $row['name'],
            "asset_code" => $row['asset_code'],
            "subcategory_id" => (int)$row['subcategory_id'],
            "subcategory_name" => $row['subcategory_name'],
            "location_department_id" => $row['location_department_id'] ? (int)$row['location_department_id'] : null,
            "location_department_name" => $row['location_department_name'],
            "location_details" => $row['location_details'],
            "dep_join" => $row['dep_join'] ? (int)$row['dep_join'] : null,
            "is_main_admin_with_status" => $is_main_admin_with_status,
            "is_department_no_admin" => $is_department_no_admin,
            "is_dep_join_match" => $is_dep_join_match,
            "transfer_permission_reason" => $reason
        ];
    }
    
    echo json_encode([
        "status" => "success",
        "data" => $equipment_list,
        "pagination" => [
            "totalItems" => $totalItems,
            "totalPages" => (int)ceil($totalItems / $limit),
            "currentPage" => $page,
            "limit" => $limit
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error", 
        "message" => "Database error: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>