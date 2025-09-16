<?php
include "../config/jwt.php";
//เครื่องมือที่สิทธิ์โอนย้ายจริงๆ ผู้ดูแลหลักตาม u_id ที่ login อยู่ และไม่ได้โอนย้ายชั่วคราวให้ใคร

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

    // แก้ไขส่วนนี้: ดึง u_id จาก JWT token แทน GET parameter
    //  jwt.php ได้ตั้งค่า $decoded และ validate token แล้ว
    // และมีตัวแปร global หรือ สามารถเข้าถึงข้อมูล user จาก JWT ได้
    
    // ดึง u_id จาก JWT token
    $u_id = $decoded->data->ID; // 
    
    if (!$u_id) {
        echo json_encode(["status" => "error", "message" => "User ID not found in token"]);
        exit;
    }
    
    // ส่วนที่เหลือเหมือนเดิม
    // ตรวจสอบว่า u_id มีอยู่ในระบบ
    $checkUser = $dbh->prepare("SELECT ID, user_id, full_name, department_id FROM users WHERE ID = :u_id");
    $checkUser->bindParam(':u_id', $u_id, PDO::PARAM_INT);
    $checkUser->execute();
    
    $user = $checkUser->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(["status" => "error", "message" => "User not found or inactive"]);
        exit;
    }
    
    // Query หาเครื่องมือที่ user มีสิทธิ์โอนย้าย
    // โดยต้องเป็น ผู้ดูแลหลัก และ equipment ต้อง active = 1
    // และตรวจสอบสถานะใน equipment_transfers ด้วย
    $sql = "
        SELECT DISTINCT
            e.equipment_id,
            e.name,
            e.asset_code,
            e.brand,
            e.model,
            e.serial_number,
            e.subcategory_id,
            es.name as subcategory_name,
            e.location_department_id,
            e.location_details,
            e.status,
            e.spec,
            e.production_year,
            e.price,
            d.department_name as location_department_name,
            CASE 
                WHEN et.equipment_id IS NULL THEN 'available'
                WHEN et.status = 0 THEN 'in_transfer'
                ELSE 'available'
            END as transfer_status
        FROM equipments e
        INNER JOIN equipment_subcategories es ON e.subcategory_id = es.subcategory_id
        INNER JOIN relation_group rg ON es.subcategory_id = rg.subcategory_id
        INNER JOIN group_user gu ON rg.group_user_id = gu.group_user_id
        INNER JOIN relation_user ru ON gu.group_user_id = ru.group_user_id
        LEFT JOIN departments d ON e.location_department_id = d.department_id
        LEFT JOIN (
            SELECT 
                equipment_id, 
                status,
                ROW_NUMBER() OVER (PARTITION BY equipment_id ORDER BY transfer_id DESC) as rn
            FROM equipment_transfers
        ) et ON e.equipment_id = et.equipment_id AND et.rn = 1
        WHERE ru.u_id = :u_id 
        AND gu.type = 'ผู้ดูแลหลัก'
        AND e.active = 1
        AND (
            et.equipment_id IS NULL 
            OR et.status != 0
        )
        ORDER BY e.equipment_id DESC
    ";
    
    $stmt = $dbh->prepare($sql);
    $stmt->bindParam(':u_id', $u_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $equipments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // จัดรูปแบบข้อมูลให้ตรงตาม format ที่ต้องการ
    $equipment_list = [];
    foreach ($equipments as $equipment) {
        $equipment_list[] = [
            "equipment_id" => (int)$equipment['equipment_id'],
            "name" => $equipment['name'],
            "asset_code" => $equipment['asset_code'],
            "brand" => $equipment['brand'],
            "model" => $equipment['model'],
            "serial_number" => $equipment['serial_number'],
            "subcategory_id" => (int)$equipment['subcategory_id'],
            "subcategory_name" => $equipment['subcategory_name'],
            "location_department_id" => $equipment['location_department_id'] ? (int)$equipment['location_department_id'] : null,
            "location_department_name" => $equipment['location_department_name'],
            "location_details" => $equipment['location_details'],
            "status" => $equipment['status'],
            "spec" => $equipment['spec'],
            "production_year" => $equipment['production_year'] ? (int)$equipment['production_year'] : null,
            "price" => $equipment['price'] ? (float)$equipment['price'] : null,
            "transfer_status" => $equipment['transfer_status']
        ];
    }
    
    // Response ตาม format ที่ต้องการ
    echo json_encode([
        "status" => "ok",
        "message" => "Equipment list for transfer retrieved successfully",
        "data" => [
            "u_id" => (int)$user['ID'],
            "user_id" => $user['user_id'],
            "user_name" => $user['full_name'],
            "department_id" => $user['department_id'] ? (int)$user['department_id'] : null,
            "total_equipment" => count($equipment_list),
            "equipment_authTransfer" => $equipment_list
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error", 
        "message" => "Database error: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>