<?php
include "../config/jwt.php"; 
// get-allTransfer.php

// กำหนด header สำหรับ response
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];

try {
    // อนุญาตเฉพาะ method GET
    if ($method !== 'GET') {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
        exit;
    }

    // รับค่า transfer_id จาก query string (ถ้ามี)
    $transfer_id = isset($_GET['transfer_id']) ? $_GET['transfer_id'] : null;

    // ------------------------
    // ดึง ENUM values ของ transfer_type จากตาราง
    // ------------------------
    $enumSql = "SHOW COLUMNS FROM equipment_transfers LIKE 'transfer_type'";
    $enumStmt = $dbh->prepare($enumSql);
    $enumStmt->execute();
    $enumRow = $enumStmt->fetch(PDO::FETCH_ASSOC);

    // ใช้ regex แยกค่าที่อยู่ใน ENUM('value1','value2',...)
    preg_match("/^enum\((.*)\)$/", $enumRow['Type'], $matches);
    $enumValues = [];
    if (!empty($matches[1])) {
        $enumValues = str_getcsv($matches[1], ',', "'");
    }

    // ------------------------
    // SQL หลักสำหรับดึงข้อมูล transfer
    // ------------------------
    $sql = "
        SELECT 
            et.transfer_id,
            et.transfer_type,
            et.transfer_date,
            et.reason,
            et.equipment_id,
            et.from_department_id,
            et.to_department_id,
            et.transfer_user_id,
            et.recipient_user_id,
            et.location_department_id,
            et.location_details,
            
            -- Equipment Information
            e.name AS equipment_name,
            e.asset_code,
            e.subcategory_id,
            
            -- Equipment Subcategory
            esc.name AS subcategory_name,
            esc.type AS subcategory_type,
            esc.category_id,
            
            -- Equipment Location Department
            d_equip_loc.department_name AS location_department_name,
            
            -- From Department
            d_from.department_name AS from_department_name,
            
            -- To Department  
            d_to.department_name AS to_department_name,
            
            -- Transfer Location Department
            d_transfer_loc.department_name AS transfer_location_department,
            
            -- Transfer User Info
            u_transfer.full_name AS transfer_user_name,
            
            -- Recipient User Info
            u_recipient.full_name AS recipient_user_name
            
        FROM equipment_transfers et
        
        -- JOIN Equipment
        INNER JOIN equipments e ON et.equipment_id = e.equipment_id
        
        -- JOIN Equipment Subcategory
        INNER JOIN equipment_subcategories esc ON e.subcategory_id = esc.subcategory_id
        
        -- JOIN Departments
        LEFT JOIN departments d_equip_loc ON e.location_department_id = d_equip_loc.department_id
        LEFT JOIN departments d_from ON et.from_department_id = d_from.department_id
        LEFT JOIN departments d_to ON et.to_department_id = d_to.department_id
        LEFT JOIN departments d_transfer_loc ON et.location_department_id = d_transfer_loc.department_id
        
        -- JOIN Users
        LEFT JOIN users u_transfer ON et.transfer_user_id = u_transfer.ID
        LEFT JOIN users u_recipient ON et.recipient_user_id = u_recipient.ID
    ";
    
    // ถ้ามีการส่ง transfer_id เข้ามา กรองด้วย WHERE
    if ($transfer_id) {
        $sql .= " WHERE et.transfer_id = :transfer_id";
    }
    
    // เรียงตาม transfer_id ล่าสุดก่อน
    $sql .= " ORDER BY et.transfer_id DESC";
    
    $stmt = $dbh->prepare($sql);
    
    if ($transfer_id) {
        $stmt->bindParam(':transfer_id', $transfer_id, PDO::PARAM_INT);
    }
    
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ------------------------
    // จัดรูปแบบข้อมูลให้อ่านง่าย + ดึง group ของ user
    // ------------------------
    $formattedResults = [];
    foreach ($results as $row) {
        // ดึง group ของ transfer_user (หลายกลุ่ม)
        $transferUserGroups = getUserGroups($row['transfer_user_id'], $row['subcategory_id']);
        
        // ดึง group ของ recipient_user (หลายกลุ่ม)
        $recipientUserGroups = getUserGroups($row['recipient_user_id'], $row['subcategory_id']);
        
        $formattedRow = [
            'transfer_id' => (int)$row['transfer_id'],
            'transfer_type' => $row['transfer_type'],
            'transfer_date' => $row['transfer_date'],
            'reason' => $row['reason'],
            'equipment_id' => (int)$row['equipment_id'],
            'equipment_name' => $row['equipment_name'],
            'asset_code' => $row['asset_code'],
            'subcategory_id' => (int)$row['subcategory_id'],
            'subcategory_name' => $row['subcategory_name'],
            'subcategory_type' => $row['subcategory_type'],
            'category_id' => (int)$row['category_id'],
            'location_department_id' => $row['location_department_id'] ? (int)$row['location_department_id'] : null,
            'location_department_name' => $row['location_department_name'],
            'location_details' => $row['location_details'],
            'from_department_id' => $row['from_department_id'] ? (int)$row['from_department_id'] : null,
            'from_department_name' => $row['from_department_name'],
            'to_department_id' => $row['to_department_id'] ? (int)$row['to_department_id'] : null,
            'to_department_name' => $row['to_department_name'],
            'transfer_location_department_id' => $row['location_department_id'] ? (int)$row['location_department_id'] : null,
            'transfer_location_department' => $row['transfer_location_department'],
            // ข้อมูล user ที่โอน
            'transfer_user' => [
                'transfer_user_id' => $row['transfer_user_id'] ? (int)$row['transfer_user_id'] : null,
                'transfer_user_name' => $row['transfer_user_name'],
                'groups' => $transferUserGroups // คืนเป็น array ของกลุ่ม
            ],
            // ข้อมูล user ที่รับ
            'recipient_user' => [
                'recipient_user_id' => $row['recipient_user_id'] ? (int)$row['recipient_user_id'] : null,
                'recipient_user_name' => $row['recipient_user_name'],
                'groups' => $recipientUserGroups // คืนเป็น array ของกลุ่ม
            ]
        ];
        
        $formattedResults[] = $formattedRow;
    }
    
    // ถ้ามีการส่ง transfer_id แต่ไม่เจอข้อมูล
    if ($transfer_id && empty($formattedResults)) {
        echo json_encode(["status" => "error", "message" => "Transfer not found"]);
        exit;
    }
    
    // ส่ง response กลับไป พร้อม enum values ของ transfer_type
    echo json_encode([
        "status" => "ok",
        "transfer_type_enum" => $enumValues, // ENUM values ของ transfer_type
        "data" => $transfer_id ? $formattedResults[0] : $formattedResults
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}

// ------------------------
// ฟังก์ชันดึง group ของ user ตาม subcategory
// ------------------------
function getUserGroups($user_id, $subcategory_id) {
    global $dbh;
    
    if (!$user_id || !$subcategory_id) {
        return [];
    }
    
    $sql = "
        SELECT 
            gu.group_user_id,
            gu.group_name,
            gu.type AS group_type
        FROM users u
        INNER JOIN relation_user ru ON u.ID = ru.u_id
        INNER JOIN group_user gu ON ru.group_user_id = gu.group_user_id
        INNER JOIN relation_group rg ON gu.group_user_id = rg.group_user_id
        WHERE u.ID = :user_id AND rg.subcategory_id = :subcategory_id
        ORDER BY gu.group_user_id ASC
    ";
    
    $stmt = $dbh->prepare($sql);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindParam(':subcategory_id', $subcategory_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // คืน array ของกลุ่มทั้งหมด (ไม่จำกัดแค่ 1 กลุ่ม)
    $groups = [];
    foreach ($results as $row) {
        $groups[] = [
            'group_user_id' => (int)$row['group_user_id'],
            'group_name' => $row['group_name'],
            'group_type' => $row['group_type']
        ];
    }
    
    return $groups;
}
?>
