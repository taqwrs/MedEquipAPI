<?php
include "../config/jwt.php";

// --- CORS headers ---
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
        // Query ดึงทุก subcategory พร้อมนับ spare_parts
        $stmt = $dbh->prepare("
            SELECT 
                s.spare_subcategory_id AS spare_subcate_id, 
                s.name AS spare_subcate_name,
                COUNT(p.spare_subcate_id) AS total_count
            FROM spare_subcategories s
            LEFT JOIN spare_parts p 
              ON s.spare_subcategory_id = p.spare_subcate_id 
              AND p.record_status != 'deleted'
            GROUP BY s.spare_subcategory_id, s.name
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
