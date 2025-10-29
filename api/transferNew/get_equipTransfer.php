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
    
    // Query หลัก: ดึงเครื่องมือที่มีสิทธิ์โอน (OR)
    $joinTables = "
        FROM equipments e
        INNER JOIN equipment_subcategories es ON e.subcategory_id = es.subcategory_id
        LEFT JOIN departments d ON e.location_department_id = d.department_id
        WHERE (
            -- เงื่อนไข 1: u_id อยู่ในกลุ่ม group_user.type = 'ผู้ดูแลหลัก'
            -- ผ่าน relation_user -> group_user -> relation_group -> subcategory_id
            EXISTS (
                SELECT 1
                FROM relation_group rg1
                INNER JOIN group_user gu1 ON rg1.group_user_id = gu1.group_user_id
                INNER JOIN relation_user ru1 ON gu1.group_user_id = ru1.group_user_id
                WHERE rg1.subcategory_id = e.subcategory_id
                AND ru1.u_id = :u_id 
                AND gu1.type = 'ผู้ดูแลหลัก'
            )
            
            OR
            
            -- เงื่อนไข 2: location_department_id = users.department_id 
            -- และ subcategory_id ไม่มี group_user.type = 'ผู้ดูแลหลัก'
            (
                e.location_department_id = :user_department_id
                AND NOT EXISTS (
                    SELECT 1
                    FROM relation_group rg2
                    INNER JOIN group_user gu2 ON rg2.group_user_id = gu2.group_user_id
                    WHERE rg2.subcategory_id = e.subcategory_id
                    AND gu2.type = 'ผู้ดูแลหลัก'
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
            d.department_name as location_department_name,
            e.updated_at,
            
            -- ตรวจสอบว่าเป็นผู้ดูแลหลักหรือไม่
            EXISTS (
                SELECT 1
                FROM relation_group rg1
                INNER JOIN group_user gu1 ON rg1.group_user_id = gu1.group_user_id
                INNER JOIN relation_user ru1 ON gu1.group_user_id = ru1.group_user_id
                WHERE rg1.subcategory_id = e.subcategory_id
                AND ru1.u_id = :u_id 
                AND gu1.type = 'ผู้ดูแลหลัก'
            ) as is_main_admin,

            -- ตรวจสอบว่าอยู่ในแผนกและไม่มีผู้ดูแลหรือไม่
            -- ต้องมี location_department_id = user.department_id ด้วย
            (
                e.location_department_id = :user_department_id
                AND NOT EXISTS (
                    SELECT 1
                    FROM relation_group rg2
                    INNER JOIN group_user gu2 ON rg2.group_user_id = gu2.group_user_id
                    WHERE rg2.subcategory_id = e.subcategory_id
                    AND gu2.type = 'ผู้ดูแลหลัก'
                )
            ) as is_department_no_admin
        $joinTables
        ORDER BY e.equipment_id DESC
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
        $is_main_admin = (bool)$row['is_main_admin'];
        $is_department_no_admin = (bool)$row['is_department_no_admin'];
        
        // กำหนดเหตุผล
        $reason = "";
        if ($is_main_admin && $is_department_no_admin) {
            $reason = "เป็นผู้ดูแลหลัก และ อยู่ในแผนกเดียวกันและไม่มีผู้ดูแลหลัก";
        } elseif ($is_main_admin) {
            $reason = "เป็นผู้ดูแลหลัก";
        } elseif ($is_department_no_admin) {
            $reason = "อยู่ในแผนกเดียวกันและไม่มีผู้ดูแลหลัก";
        }
        
        $equipment_list[] = [
            "equipment_id" => (int)$row['equipment_id'],
            "name" => $row['name'],
            "asset_code" => $row['asset_code'],
            "subcategory_id" => (int)$row['subcategory_id'],
            "subcategory_name" => $row['subcategory_name'],
            "location_department_id" => $row['location_department_id'] ? (int)$row['location_department_id'] : null,
            "location_department_name" => $row['location_department_name'],
            "location_details" => $row['location_details'],
            "is_main_admin" => $is_main_admin,
            "is_department_no_admin" => $is_department_no_admin,
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