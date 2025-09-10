<?php
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

    $transfer_id = isset($_GET['transfer_id']) ? $_GET['transfer_id'] : null;
    
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
    
    if ($transfer_id) {
        $sql .= " WHERE et.transfer_id = :transfer_id";
    }
    
    $sql .= " ORDER BY et.transfer_id DESC";
    
    $stmt = $dbh->prepare($sql);
    
    if ($transfer_id) {
        $stmt->bindParam(':transfer_id', $transfer_id, PDO::PARAM_INT);
    }
    
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // จัดรูปแบบข้อมูลตามที่ต้องการ
    $formattedResults = [];
    foreach ($results as $row) {
        // ดึงข้อมูล group สำหรับ transfer_user
        $transferUserGroups = getUserGroups($row['transfer_user_id'], $row['subcategory_id']);
        
        // ดึงข้อมูล group สำหรับ recipient_user
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
            'transfer_user' => [
                'transfer_user_id' => $row['transfer_user_id'] ? (int)$row['transfer_user_id'] : null,
                'transfer_user_name' => $row['transfer_user_name'],
                'group_user_id' => $transferUserGroups['group_user_id'],
                'group_name' => $transferUserGroups['group_name'],
                'group_type' => $transferUserGroups['group_type']
            ],
            'recipient_user' => [
                'recipient_user_id' => $row['recipient_user_id'] ? (int)$row['recipient_user_id'] : null,
                'recipient_user_name' => $row['recipient_user_name'],
                'group_user_id' => $recipientUserGroups['group_user_id'],
                'group_name' => $recipientUserGroups['group_name'],
                'group_type' => $recipientUserGroups['group_type']
            ]
        ];
        
        $formattedResults[] = $formattedRow;
    }
    
    if ($transfer_id && empty($formattedResults)) {
        echo json_encode(["status" => "error", "message" => "Transfer not found"]);
        exit;
    }
    
    echo json_encode([
        "status" => "ok",
        "data" => $transfer_id ? $formattedResults[0] : $formattedResults
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}

// ฟังก์ชันช่วยดึงข้อมูล group ของ user ที่เกี่ยวข้องกับ subcategory
function getUserGroups($user_id, $subcategory_id) {
    global $dbh;
    
    if (!$user_id || !$subcategory_id) {
        return [
            'group_user_id' => null,
            'group_name' => null,
            'group_type' => null
        ];
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
        LIMIT 1
    ";
    
    $stmt = $dbh->prepare($sql);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindParam(':subcategory_id', $subcategory_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        return [
            'group_user_id' => (int)$result['group_user_id'],
            'group_name' => $result['group_name'],
            'group_type' => $result['group_type']
        ];
    }
    
    return [
        'group_user_id' => null,
        'group_name' => null,
        'group_type' => null
    ];
}
?>