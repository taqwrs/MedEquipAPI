<?php
include "../config/jwt.php"; 
// get-allTransfer การโอนย้ายทั้งหมด

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
            et.old_subcategory_id,
            et.new_subcategory_id,
            et.now_subcategory_id,
            et.status,
            et.returned_date,
            et.old_location_department_id,
            
            -- Equipment Information
            e.name AS equipment_name,
            e.asset_code,
            e.location_department_id AS now_location_department_id,
            e.location_details AS now_location_details,
            
            -- Old Subcategory Information
            esc_old.name AS old_subcategory_name,
            esc_old.type AS old_subcategory_type,
            esc_old.category_id,
            
            -- New Subcategory Information
            esc_new.name AS new_subcategory_name,
            esc_new.type AS new_subcategory_type,
            
            -- Now Subcategory Information
            esc_now.name AS now_subcategory_name,
            esc_now.type AS now_subcategory_type,
            
            -- From Department
            d_from.department_name AS from_department_name,
            
            -- To Department  
            d_to.department_name AS to_department_name,
            
            -- Transfer New Location Department
            d_transfer_loc.department_name AS transfer_newlocation_department,
            
            -- Old Location Department (before transfer)
            d_old_loc.department_name AS old_location_department_name,
            
            -- Now Location Department (current location in equipment table)
            d_now_loc.department_name AS now_location_department_name,
            
            -- Transfer User Info
            u_transfer.full_name AS transfer_user_name,
            
            -- Recipient User Info
            u_recipient.full_name AS recipient_user_name
            
        FROM equipment_transfers et
        
        -- JOIN Equipment
        INNER JOIN equipments e ON et.equipment_id = e.equipment_id
        
        -- JOIN Old Subcategory
        LEFT JOIN equipment_subcategories esc_old ON et.old_subcategory_id = esc_old.subcategory_id
        
        -- JOIN New Subcategory
        LEFT JOIN equipment_subcategories esc_new ON et.new_subcategory_id = esc_new.subcategory_id
        
        -- JOIN Now Subcategory
        LEFT JOIN equipment_subcategories esc_now ON et.now_subcategory_id = esc_now.subcategory_id
        
        -- JOIN Departments
        LEFT JOIN departments d_from ON et.from_department_id = d_from.department_id
        LEFT JOIN departments d_to ON et.to_department_id = d_to.department_id
        LEFT JOIN departments d_transfer_loc ON et.location_department_id = d_transfer_loc.department_id
        LEFT JOIN departments d_old_loc ON et.old_location_department_id = d_old_loc.department_id
        LEFT JOIN departments d_now_loc ON e.location_department_id = d_now_loc.department_id
        
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
        // สร้าง status text description
        $statusText = '';
        if ($row['transfer_type'] === 'โอนย้ายชั่วคราว') {
            $statusText = $row['status'] == 0 ? '0 ยังไม่คืน' : '1 คืนแล้ว';
        } else {
            $statusText = '1 ไม่ต้องคืน';
        }
        
        // ดึง group ของ transfer_user (หลายกลุ่ม) - ใช้ old_subcategory_id
        $transferUserGroups = getUserGroups($row['transfer_user_id'], $row['old_subcategory_id']);
        
        // ดึง group ของ recipient_user (หลายกลุ่ม) - ใช้ new_subcategory_id
        $recipientUserGroups = getUserGroups($row['recipient_user_id'], $row['new_subcategory_id']);
        
        $formattedRow = [
            'transfer_id' => (int)$row['transfer_id'],
            'transfer_type' => $row['transfer_type'],
            'transfer_date' => $row['transfer_date'],
            'status' => $statusText,
            'equipment_id' => (int)$row['equipment_id'],
            'equipment_name' => $row['equipment_name'],
            'asset_code' => $row['asset_code'],
            
            // Old Subcategory (ก่อนโอน)
            'old_subcategory_id' => $row['old_subcategory_id'] ? (int)$row['old_subcategory_id'] : null,
            'old_subcategory_name' => $row['old_subcategory_name'],
            'old_subcategory_type' => $row['old_subcategory_type'],
            
            // New Subcategory (หลังโอน)
            'new_subcategory_id' => $row['new_subcategory_id'] ? (int)$row['new_subcategory_id'] : null,
            'new_subcategory_name' => $row['new_subcategory_name'],
            'new_subcategory_type' => $row['new_subcategory_type'],
            
            // Now Subcategory (ปัจจุบัน)
            'now_subcategory_id' => $row['now_subcategory_id'] ? (int)$row['now_subcategory_id'] : null,
            'now_subcategory_name' => $row['now_subcategory_name'],
            'now_subcategory_type' => $row['now_subcategory_type'],
            
            'category_id' => (int)$row['category_id'],
            
            // Department Information
            'from_department_id' => $row['from_department_id'] ? (int)$row['from_department_id'] : null,
            'from_department_name' => $row['from_department_name'],
            'to_department_id' => $row['to_department_id'] ? (int)$row['to_department_id'] : null,
            'to_department_name' => $row['to_department_name'],
            
            // Old Location (ก่อนโอน - จากตาราง equipment_transfers)
            'old_location_department_id' => $row['old_location_department_id'] ? (int)$row['old_location_department_id'] : null,
            'old_location_department_name' => $row['old_location_department_name'],
            'old_location_details' => $row['location_details'], // location_details จาก equipment เดิม
            
            // Transfer New Location (สถานที่ที่โอนไป - จากตาราง equipment_transfers)
            'transfer_newlocation_department_id' => $row['location_department_id'] ? (int)$row['location_department_id'] : null,
            'transfer_newlocation_department' => $row['transfer_newlocation_department'],
            'transfer_newlocation_detail' => $row['location_details'], // location_details ที่โอนไป
            
            // Now Location (สถานที่ปัจจุบัน - จากตาราง equipments)
            'now_location_department_id' => $row['now_location_department_id'] ? (int)$row['now_location_department_id'] : null,
            'now_location_department_name' => $row['now_location_department_name'],
            'now_location_details' => $row['now_location_details'],
            
            'reason' => $row['reason'],
            'returned_date' => $row['returned_date'],
            
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