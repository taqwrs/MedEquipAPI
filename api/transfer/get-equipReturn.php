<?php
// API สำหรับดึงข้อมูลเครื่องมือที่โอนย้ายชั่วคราว + ค้นหา + สรุปจำนวน
include "../config/jwt.php"; 
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
try {
    if ($method !== 'POST') {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
        exit;
    }

    $u_id = $decoded->data->ID ?? null;
    if (!$u_id) {
        echo json_encode(["status" => "error", "message" => "User ID not found in token"]);
        exit;
    }
    
    // รับ input สำหรับค้นหาและแบ่งหน้า
    $input = json_decode(file_get_contents('php://input'), true);
    $search = trim($input['search'] ?? '');
    $page = (int) ($input['page'] ?? 1);
    $limit = (int) ($input['limit'] ?? 5);
    $offset = ($page - 1) * $limit;
    $useLimit = $limit > 0;

    // ตรวจสอบว่า u_id มีอยู่จริง
    $checkUser = $dbh->prepare("SELECT ID, user_id, full_name, department_id FROM users WHERE ID = :u_id");
    $checkUser->bindParam(':u_id', $u_id, PDO::PARAM_INT);
    $checkUser->execute();
    if ($checkUser->rowCount() === 0) {
        echo json_encode([
            "status" => "error", 
            "message" => "User not found",
            "u_id" => $u_id
        ]);
        exit;
    }
    $userData = $checkUser->fetch(PDO::FETCH_ASSOC);

    // Query หลัก
    $sql = "
        SELECT 
            e.equipment_id,
            e.name,
            e.asset_code,
            e.brand,
            e.model,
            et.transfer_id,
            et.transfer_date,
            from_dept.department_name AS from_department_name,
            to_dept.department_name AS to_department_name,
            loc_dept.department_name AS location_department_name
        FROM users u
        INNER JOIN relation_user ru ON u.ID = ru.u_id
        INNER JOIN group_user gu ON ru.group_user_id = gu.group_user_id 
            AND gu.type = 'ผู้ใช้งาน'
        INNER JOIN relation_group rg ON gu.group_user_id = rg.group_user_id
        INNER JOIN equipment_transfers et ON et.recipient_user_id = u.ID 
            AND et.transfer_type = 'โอนย้ายชั่วคราว'
            AND et.now_subcategory_id = rg.subcategory_id
        INNER JOIN equipments e ON et.equipment_id = e.equipment_id
        INNER JOIN equipment_subcategories es ON et.now_subcategory_id = es.subcategory_id
        LEFT JOIN departments from_dept ON et.from_department_id = from_dept.department_id
        LEFT JOIN departments to_dept ON et.to_department_id = to_dept.department_id
        LEFT JOIN departments loc_dept ON et.location_department_id = loc_dept.department_id
        WHERE u.ID = :u_id
          AND et.status = 'active'
    ";

    // ✅ เพิ่มเงื่อนไขค้นหา
    if (!empty($search)) {
        $sql .= " AND (
            e.name LIKE :search OR 
            e.asset_code LIKE :search OR 
            from_dept.department_name LIKE :search OR 
            to_dept.department_name LIKE :search OR 
            loc_dept.department_name LIKE :search
        )";
    }
    $sql .= " ORDER BY e.equipment_id ASC";

    // ✅ นับจำนวนทั้งหมด
    $countSql = "SELECT COUNT(*) FROM ($sql) AS total";
    $countStmt = $dbh->prepare($countSql);
    $countStmt->bindParam(':u_id', $u_id, PDO::PARAM_INT);
    if (!empty($search)) {
        $likeSearch = "%$search%";
        $countStmt->bindParam(':search', $likeSearch, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $totalItems = (int)$countStmt->fetchColumn();

    // ✅ Query พร้อมแบ่งหน้า
    if ($useLimit) {
        $sql .= " LIMIT :limit OFFSET :offset";
    }
    $stmt = $dbh->prepare($sql);
    $stmt->bindParam(':u_id', $u_id, PDO::PARAM_INT);
    if (!empty($search)) {
        $stmt->bindParam(':search', $likeSearch, PDO::PARAM_STR);
    }
    if ($useLimit) {
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    $equipment_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // -------------------------------------------
    // Summary (ยังไม่ได้โอนคืน / โอนคืนแล้ว / รวมทั้งหมด)
    $sql_not_returned = "
        SELECT COUNT(equipment_id) AS total_not_returned
        FROM equipment_transfers
        WHERE transfer_type = 'โอนย้ายชั่วคราว'
          AND recipient_user_id = :u_id
          AND status = 0
    ";
    $stmt1 = $dbh->prepare($sql_not_returned);
    $stmt1->bindParam(':u_id', $u_id, PDO::PARAM_INT);
    $stmt1->execute();
    $res1 = $stmt1->fetch(PDO::FETCH_ASSOC);

    $sql_returned = "
        SELECT COUNT(equipment_id) AS total_returned
        FROM equipment_transfers
        WHERE transfer_type = 'โอนย้ายชั่วคราว'
          AND recipient_user_id = :u_id
          AND status = 1
    ";
    $stmt2 = $dbh->prepare($sql_returned);
    $stmt2->bindParam(':u_id', $u_id, PDO::PARAM_INT);
    $stmt2->execute();
    $res2 = $stmt2->fetch(PDO::FETCH_ASSOC);

    $sql_total = "
        SELECT COUNT(equipment_id) AS total_temp_transfer
        FROM equipment_transfers
        WHERE transfer_type = 'โอนย้ายชั่วคราว'
          AND recipient_user_id = :u_id
    ";
    $stmt3 = $dbh->prepare($sql_total);
    $stmt3->bindParam(':u_id', $u_id, PDO::PARAM_INT);
    $stmt3->execute();
    $res3 = $stmt3->fetch(PDO::FETCH_ASSOC);

    // -------------------------------------------
    // ส่งผลลัพธ์รวม
    $response = [
        'status' => 'success',
        'data' => [
            'u_id' => (int)$userData['ID'],
            'user_id' => $userData['user_id'],
            'user_name' => $userData['full_name'],
            'department_id' => $userData['department_id'] ? (int)$userData['department_id'] : null,
            'transfer_type' => 'โอนย้ายชั่วคราว',
            'equipment_list' => $equipment_list,
            'summary' => [
                'total_temp_transfer' => (int)$res3['total_temp_transfer'],
                'not_returned' => (int)$res1['total_not_returned'],
                'returned' => (int)$res2['total_returned']
            ]
        ],
        'pagination' => [
            'totalItems' => $totalItems,
            'totalPages' => $limit > 0 ? ceil($totalItems / $limit) : 1,
            'currentPage' => $page,
            'limit' => $limit
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Server error: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
