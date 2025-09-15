<?php
// API สำหรับดึงข้อมูลเครื่องมือที่โอนย้ายชั่วคราว
include "../config/jwt.php";
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method !== 'GET') {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
        exit;
    }
    
    // รับ parameter จาก URL
    $u_id = isset($_GET['u_id']) ? (int)$_GET['u_id'] : null;
    
    if (!$u_id) {
        echo json_encode(["status" => "error", "message" => "u_id parameter is required"]);
        exit;
    }
    
    // ตรวจสอบว่า u_id มีอยู่ในระบบ
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
    
    // ดึงข้อมูลเครื่องมือที่โอนย้ายชั่วคราวสำหรับ user นี้
    $sql = "
        SELECT DISTINCT
            e.equipment_id,
            e.name,
            e.asset_code,
            et.transfer_user_id,
            transfer_user.full_name as transfer_user_name,
            es.subcategory_id,
            es.name as subcategory_name,
            et.transfer_id,
            et.transfer_date,
            et.reason,
            et.status
        FROM users u
        -- เชื่อมกับ relation_user เพื่อหา group_user_id
        INNER JOIN relation_user ru ON u.ID = ru.u_id
        -- เชื่อมกับ group_user ที่มี type = 'ผู้ใช้งาน'
        INNER JOIN group_user gu ON ru.group_user_id = gu.group_user_id 
            AND gu.type = 'ผู้ใช้งาน'
        -- เชื่อมกับ relation_group เพื่อหา subcategory ที่สามารถเข้าถึงได้
        INNER JOIN relation_group rg ON gu.group_user_id = rg.group_user_id
        -- เชื่อมกับ equipment_transfers ที่เป็นการโอนย้ายชั่วคราว
        INNER JOIN equipment_transfers et ON et.recipient_user_id = u.ID 
            AND et.transfer_type = 'โอนย้ายชั่วคราว'
            AND et.now_subcategory_id = rg.subcategory_id
        -- เชื่อมกับ equipments
        INNER JOIN equipments e ON et.equipment_id = e.equipment_id
        -- เชื่อมกับ equipment_subcategories
        INNER JOIN equipment_subcategories es ON et.now_subcategory_id = es.subcategory_id
        -- เชื่อมกับ users ที่เป็นผู้โอน (transfer_user_id)
        LEFT JOIN users transfer_user ON et.transfer_user_id = transfer_user.ID
        WHERE u.ID = :u_id
            AND et.status = 'active'
        ORDER BY e.equipment_id
    ";
    
    $stmt = $dbh->prepare($sql);
    $stmt->bindParam(':u_id', $u_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $equipment_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // จัดรูปแบบข้อมูลเครื่องมือ
    $equipment_list = [];
    foreach ($equipment_results as $equipment) {
        $equipment_list[] = [
            'equipment_id' => (int)$equipment['equipment_id'],
            'name' => $equipment['name'],
            'asset_code' => $equipment['asset_code'],
            'transfer_user_id' => $equipment['transfer_user_id'] ? (int)$equipment['transfer_user_id'] : null,
            'transfer_user_name' => $equipment['transfer_user_name'],
            'subcategory_id' => (int)$equipment['subcategory_id'],
            'subcategory_name' => $equipment['subcategory_name'],
            'transfer_id' => (int)$equipment['transfer_id'],
            'transfer_date' => $equipment['transfer_date'],
            'reason' => $equipment['reason'],
            'status' => $equipment['status']
        ];
    }
    
    // ส่งผลลัพธ์
    if (!empty($equipment_list)) {
        $response = [
            'status' => 'success',
            'data' => [
                'u_id' => (int)$userData['ID'],
                'user_id' => $userData['user_id'],
                'user_name' => $userData['full_name'],
                'transfer_type' => 'โอนย้ายชั่วคราว',
                'equipment_list' => $equipment_list
            ],
            'total_equipment' => count($equipment_list)
        ];
    } else {
        $response = [
            'status' => 'success',
            'message' => 'No temporary transfer equipment found for this user',
            'data' => [
                'u_id' => (int)$userData['ID'],
                'user_id' => $userData['user_id'],
                'user_name' => $userData['full_name'],
                'transfer_type' => 'โอนย้ายชั่วคราว',
                'equipment_list' => []
            ],
            'total_equipment' => 0
        ];
    }
    
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