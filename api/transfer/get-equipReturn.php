<?php
// API สำหรับดึงข้อมูลเครื่องมือที่โอนย้ายชั่วคราว ที่ต้องคืน + สรุปจำนวน
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

    $u_id = $decoded->data->ID ?? null;
    if (!$u_id) {
        echo json_encode(["status" => "error", "message" => "User ID not found in token"]);
        exit;
    }
    
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
    
    // ส่วนดึงข้อมูลรายการเครื่องมือ พร้อมชื่อแผนก
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
            et.status,
            et.from_department_id,
            from_dept.department_name AS from_department_name,
            et.to_department_id,
            to_dept.department_name AS to_department_name,
            et.location_department_id,
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
        LEFT JOIN users transfer_user ON et.transfer_user_id = transfer_user.ID
        LEFT JOIN departments from_dept ON et.from_department_id = from_dept.department_id
        LEFT JOIN departments to_dept ON et.to_department_id = to_dept.department_id
        LEFT JOIN departments loc_dept ON et.location_department_id = loc_dept.department_id
        WHERE u.ID = :u_id
            AND et.status = 'active'
        ORDER BY e.equipment_id
    ";
    
    $stmt = $dbh->prepare($sql);
    $stmt->bindParam(':u_id', $u_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $equipment_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
            'status' => $equipment['status'],
            'from_department_id' => $equipment['from_department_id'] ? (int)$equipment['from_department_id'] : null,
            'from_department_name' => $equipment['from_department_name'],
            'to_department_id' => $equipment['to_department_id'] ? (int)$equipment['to_department_id'] : null,
            'to_department_name' => $equipment['to_department_name'],
            'location_department_id' => $equipment['location_department_id'] ? (int)$equipment['location_department_id'] : null,
            'location_department_name' => $equipment['location_department_name']
        ];
    }
    
    // -------------------------------------------
    // 1.1 ยังไม่ได้โอนคืน (status = 0)
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
    
    // 1.2 โอนคืนแล้ว (status = 1)
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
    
    // 1.3 รวมทั้งหมดที่เคยรับโอน (ทุก status)
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
                'total_temp_transfer' => (int)$res3['total_temp_transfer'], // 1.3
                'not_returned' => (int)$res1['total_not_returned'],         // 1.1
                'returned' => (int)$res2['total_returned']                 // 1.2
            ]
        ],
        'total_equipment' => count($equipment_list) //จำนวนรับโอนย้ายชั่วคราว
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
