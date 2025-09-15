<?php
include "../config/jwt.php";
//get-equipment-auth-transfer.php

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
    
    $user = $checkUser->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(["status" => "error", "message" => "User not found"]);
        exit;
    }
    
    // Query หาเครื่องมือที่ user มีสิทธิ์โอนย้าย
    // โดยต้องเป็น ผู้ดูแลหลัก และ equipment ต้อง active = 1
    $sql = "
        SELECT DISTINCT
            e.equipment_id,
            e.name,
            e.asset_code,
            e.subcategory_id,
            es.name as subcategory_name,
            e.location_department_id,
            e.location_details,
            e.brand,
            e.model,
            e.serial_number,
            e.status,
            d.department_name as location_department_name
        FROM equipments e
        INNER JOIN equipment_subcategories es ON e.subcategory_id = es.subcategory_id
        INNER JOIN relation_group rg ON es.subcategory_id = rg.subcategory_id
        INNER JOIN group_user gu ON rg.group_user_id = gu.group_user_id
        INNER JOIN relation_user ru ON gu.group_user_id = ru.group_user_id
        LEFT JOIN departments d ON e.location_department_id = d.department_id
        WHERE ru.u_id = :u_id 
        AND gu.type = 'ผู้ดูแลหลัก'
        AND e.active = 1
        ORDER BY e.asset_code ASC
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
            "subcategory_id" => (int)$equipment['subcategory_id'],
            "subcategory_name" => $equipment['subcategory_name'],
            "location_department_id" => $equipment['location_department_id'] ? (int)$equipment['location_department_id'] : null,
            "location_department_name" => $equipment['location_department_name'],
            "location_details" => $equipment['location_details'],
            "brand" => $equipment['brand'],
            "model" => $equipment['model'],
            "serial_number" => $equipment['serial_number'],
            "status" => $equipment['status']
        ];
    }
    
    // Response ตาม format ที่ต้องการ
    echo json_encode([
        "status" => "ok",
        "message" => "Equipment list retrieved successfully",
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