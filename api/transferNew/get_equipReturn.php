<?php
// API สำหรับดึงข้อมูลเครื่องมือที่จะโอนคืน
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
    
    // ตรวจสอบว่า u_id มีอยู่จริงและดึง department_id
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
    $user_department_id = $userData['department_id'];
    
    // รับ input สำหรับค้นหาและแบ่งหน้า
    $input = json_decode(file_get_contents('php://input'), true);
    $search = trim($input['search'] ?? '');
    $page = max(1, (int)($input['page'] ?? 1));
    $limit = max(1, (int)($input['limit'] ?? 5));
    $offset = ($page - 1) * $limit;

    // Query หลัก: ดึงเครื่องมือที่จะโอนคืน
    $sql = "
        SELECT 
            e.equipment_id,
            e.name,
            e.asset_code,
            e.brand,
            e.model,
            et.transfer_id,
            et.transfer_type,
            et.transfer_date,
            et.status,
            et.from_department_id,
            et.to_department_id,
            et.location_department_id,
            et.location_details,
            et.old_equip_location_details,
            et.transfer_user_id,
            et.recipient_user_id,
            et.reason,
            et.now_subcategory_id,
            from_dept.department_name AS from_department_name,
            to_dept.department_name AS to_department_name,
            loc_dept.department_name AS location_department_name,
            tu.full_name AS transfer_user_name,
            recu.full_name AS recipient_user_name,
            es.name AS subcategory_name
        FROM equipment_transfers et
        INNER JOIN equipments e ON et.equipment_id = e.equipment_id
        INNER JOIN users u ON u.department_id = et.to_department_id
        LEFT JOIN equipment_subcategories es ON et.now_subcategory_id = es.subcategory_id
        LEFT JOIN departments from_dept ON et.from_department_id = from_dept.department_id
        LEFT JOIN departments to_dept ON et.to_department_id = to_dept.department_id
        LEFT JOIN departments loc_dept ON et.location_department_id = loc_dept.department_id
        LEFT JOIN users tu ON et.transfer_user_id = tu.ID
        LEFT JOIN users recu ON et.recipient_user_id = recu.ID
        WHERE et.transfer_type = 'โอนย้ายชั่วคราว'
          AND et.to_department_id = :user_department_id
          AND et.status = 0
          AND u.ID = :u_id
    ";

    // เพิ่มเงื่อนไขค้นหา
    if (!empty($search)) {
        $sql .= " AND (
            e.name LIKE :search OR 
            e.asset_code LIKE :search OR 
            from_dept.department_name LIKE :search OR 
            to_dept.department_name LIKE :search OR 
            loc_dept.department_name LIKE :search OR
            e.brand LIKE :search OR
            e.model LIKE :search
        )";
    }
    
    $sql .= " ORDER BY et.transfer_date DESC";

    // นับจำนวนทั้งหมด
    $countSql = "SELECT COUNT(*) FROM ($sql) AS total";
    $countStmt = $dbh->prepare($countSql);
    $countStmt->bindParam(':u_id', $u_id, PDO::PARAM_INT);
    $countStmt->bindParam(':user_department_id', $user_department_id, PDO::PARAM_INT);
    if (!empty($search)) {
        $likeSearch = "%$search%";
        $countStmt->bindParam(':search', $likeSearch, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $totalItems = (int)$countStmt->fetchColumn();

    // ดึงข้อมูลพร้อม pagination
    $sql .= " LIMIT :limit OFFSET :offset";
    $stmt = $dbh->prepare($sql);
    $stmt->bindParam(':u_id', $u_id, PDO::PARAM_INT);
    $stmt->bindParam(':user_department_id', $user_department_id, PDO::PARAM_INT);
    if (!empty($search)) {
        $stmt->bindParam(':search', $likeSearch, PDO::PARAM_STR);
    }
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $equipment_list = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $equipment_list[] = [
            'equipment_id' => (int)$row['equipment_id'],
            'name' => $row['name'],
            'asset_code' => $row['asset_code'],
            'brand' => $row['brand'],
            'model' => $row['model'],
            'transfer_id' => (int)$row['transfer_id'],
            'transfer_type' => $row['transfer_type'],
            'transfer_date' => $row['transfer_date'],
            'status' => (int)$row['status'],
            'from_department_id' => $row['from_department_id'] ? (int)$row['from_department_id'] : null,
            'from_department_name' => $row['from_department_name'],
            'to_department_id' => $row['to_department_id'] ? (int)$row['to_department_id'] : null,
            'to_department_name' => $row['to_department_name'],
            'location_department_id' => $row['location_department_id'] ? (int)$row['location_department_id'] : null,
            'location_department_name' => $row['location_department_name'],
            'location_details' => $row['location_details'],
            'old_equip_location_details' => $row['old_equip_location_details'],
            'transfer_user_id' => $row['transfer_user_id'] ? (int)$row['transfer_user_id'] : null,
            'transfer_user_name' => $row['transfer_user_name'] ?? 'ไม่ระบุ',
            'recipient_user_id' => $row['recipient_user_id'] ? (int)$row['recipient_user_id'] : null,
            'recipient_user_name' => $row['recipient_user_name'] ?? 'ไม่ระบุ',
            'reason' => $row['reason'],
            'now_subcategory_id' => $row['now_subcategory_id'] ? (int)$row['now_subcategory_id'] : null,
            'subcategory_name' => $row['subcategory_name']
        ];
    }
        
    // Summary (จำนวนเครื่องมือที่รอโอนคืนในแผนกนี้)
    $sql_summary = "
        SELECT COUNT(et.equipment_id) AS total_waiting_return
        FROM equipment_transfers et
        INNER JOIN users u ON u.department_id = et.to_department_id
        WHERE et.transfer_type = 'โอนย้ายชั่วคราว'
          AND et.to_department_id = :user_department_id
          AND et.status = 0
          AND u.ID = :u_id
    ";
    $stmtSummary = $dbh->prepare($sql_summary);
    $stmtSummary->bindParam(':u_id', $u_id, PDO::PARAM_INT);
    $stmtSummary->bindParam(':user_department_id', $user_department_id, PDO::PARAM_INT);
    $stmtSummary->execute();
    $resSummary = $stmtSummary->fetch(PDO::FETCH_ASSOC);

    // ส่งผลลัพธ์
    $response = [
        'status' => 'success',
        'data' => [
            'u_id' => (int)$userData['ID'],
            'user_id' => $userData['user_id'],
            'user_name' => $userData['full_name'],
            'department_id' => $user_department_id ? (int)$user_department_id : null,
            'equipment_list' => $equipment_list,
            'summary' => [
                'total_waiting_return' => (int)$resSummary['total_waiting_return']
            ]
        ],
        'pagination' => [
            'totalItems' => $totalItems,
            'totalPages' => (int)ceil($totalItems / $limit),
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