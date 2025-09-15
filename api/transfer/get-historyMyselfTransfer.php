<?php
include "../config/jwt.php"; // มี $dbh จาก config.php

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

    // ตรวจสอบว่า User มีอยู่จริงหรือไม่
    $check_user_sql = "SELECT ID as u_id, user_id, full_name FROM users WHERE ID = :u_id";
    $check_stmt = $dbh->prepare($check_user_sql);
    $check_stmt->bindParam(":u_id", $u_id, PDO::PARAM_INT);
    $check_stmt->execute();
    $user_info = $check_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user_info) {
        echo json_encode(["status" => "error", "message" => "ไม่พบข้อมูลผู้ใช้ที่ระบุ"]);
        exit;
    }

    // Query หลักสำหรับดึงข้อมูลประวัติการโอนย้าย
    $sql = "
    SELECT 
        ht.history_transfer_id,
        ht.transfer_id,
        ht.transfer_type,
        ht.equipment_id,
        ht.transfer_date,
        ht.returned_date,
        ht.reason,
        ht.transfer_user_id,
        ht.recipient_user_id,
        ht.status_transfer,
        ht.from_department_id,
        ht.to_department_id,
        ht.old_subcategory_id,
        ht.new_subcategory_id,
        ht.now_subcategory_id,
        ht.old_equip_location_details,
        ht.trans_location_details,
        ht.now_equip_location_details,
        
        e.name as equipment_name,
        e.asset_code,
        e.brand,
        e.model,
        e.serial_number,
        
        u_transfer.full_name as transfer_user_name,
        u_transfer.user_id as transfer_user_code,
        
        u_recipient.full_name as recipient_user_name,
        u_recipient.user_id as recipient_user_code,
        
        d_from.department_name as from_department_name,
        d_to.department_name as to_department_name,
        
        sc_old.name as old_subcategory_name,
        sc_new.name as new_subcategory_name,
        sc_now.name as now_subcategory_name

    FROM history_transfer ht
    
    LEFT JOIN equipments e ON ht.equipment_id = e.equipment_id
    LEFT JOIN users u_transfer ON ht.transfer_user_id = u_transfer.ID
    LEFT JOIN users u_recipient ON ht.recipient_user_id = u_recipient.ID
    LEFT JOIN departments d_from ON ht.from_department_id = d_from.department_id
    LEFT JOIN departments d_to ON ht.to_department_id = d_to.department_id
    LEFT JOIN equipment_subcategories sc_old ON ht.old_subcategory_id = sc_old.subcategory_id
    LEFT JOIN equipment_subcategories sc_new ON ht.new_subcategory_id = sc_new.subcategory_id
    LEFT JOIN equipment_subcategories sc_now ON ht.now_subcategory_id = sc_now.subcategory_id
    
    WHERE (ht.transfer_user_id = :u_id OR ht.recipient_user_id = :u_id)
    ORDER BY ht.transfer_date DESC, ht.history_transfer_id DESC
    ";

    $stmt = $dbh->prepare($sql);
    $stmt->bindParam(":u_id", $u_id, PDO::PARAM_INT);
    $stmt->execute();
    $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($transfers)) {
        echo json_encode([
            "status" => "success",
            "data" => [
                "u_id" => (int)$user_info['u_id'],
                "user_id" => $user_info['user_id'],
                "user_name" => $user_info['full_name'],
                "โอนย้ายชั่วคราว" => [],
                "โอนย้ายถาวร" => []
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $temporary_transfers = [];
    $permanent_transfers = [];

    foreach ($transfers as $transfer) {
        $is_sender = ($transfer['transfer_user_id'] == $u_id);
        
        $transfer_item = [
            "equipment_id" => (int)$transfer['equipment_id'],
            "name" => $transfer['equipment_name'],
            "asset_code" => $transfer['asset_code'],
            "brand" => $transfer['brand'],
            "model" => $transfer['model'],
            "serial_number" => $transfer['serial_number'],
            "transfer_user_id" => (int)$transfer['transfer_user_id'],
            "transfer_user_name" => $transfer['transfer_user_name'],
            "recipient_user_id" => (int)$transfer['recipient_user_id'],
            "recipient_user_name" => $transfer['recipient_user_name'],
            "subcategory_id" => (int)$transfer['now_subcategory_id'],
            "subcategory_name" => $transfer['now_subcategory_name'],
            "transfer_date" => $transfer['transfer_date'],
            "returned_date" => $transfer['returned_date'],
            "status" => $transfer['status_transfer'],
            "reason" => $transfer['reason'],
            "user_role" => $is_sender ? "ผู้โอน" : "ผู้รับ",
            "from_department" => $transfer['from_department_name'],
            "to_department" => $transfer['to_department_name'],
            "old_subcategory_name" => $transfer['old_subcategory_name'],
            "new_subcategory_name" => $transfer['new_subcategory_name'],
            "location_details" => [
                "old" => $transfer['old_equip_location_details'],
                "transfer" => $transfer['trans_location_details'],
                "current" => $transfer['now_equip_location_details']
            ]
        ];

        if ($transfer['transfer_type'] == 'โอนย้ายชั่วคราว') {
            $temporary_transfers[] = $transfer_item;
        } else if ($transfer['transfer_type'] == 'โอนย้ายถาวร') {
            $permanent_transfers[] = $transfer_item;
        }
    }

    $response_data = [
        "u_id" => (int)$user_info['u_id'],
        "user_id" => $user_info['user_id'],
        "user_name" => $user_info['full_name'],
        "โอนย้ายชั่วคราว" => $temporary_transfers,
        "โอนย้ายถาวร" => $permanent_transfers
    ];

    echo json_encode([
        "status" => "success",
        "data" => $response_data
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error", 
        "message" => "เกิดข้อผิดพลาด: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
