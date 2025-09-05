<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // Query ดึงทุก subcategory พร้อมนับ equipments
        $stmt = $dbh->prepare("
            SELECT 
                s.subcategory_id,
                s.name AS subcategory_name,
                s.type,
                COUNT(e.equipment_id) AS equip_total_count
            FROM equipment_subcategories s
            LEFT JOIN equipments e 
              ON s.subcategory_id = e.subcategory_id
              AND e.record_status != 'deleted'
            GROUP BY 
                s.subcategory_id, 
                s.name, 
                s.type
        ");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "status" => "success",
            "data" => $results
        ], JSON_UNESCAPED_UNICODE);

    } else {
        echo json_encode([
            "status" => "error", 
            "message" => "Method not allowed"
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        "status" => "error", 
        "message" => $e->getMessage()
    ]);
}
