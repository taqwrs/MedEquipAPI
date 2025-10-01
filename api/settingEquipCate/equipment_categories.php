<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents("php://input"), true);

if ($method === 'POST' && isset($input['_method'])) {
    $method = strtoupper($input['_method']);
}

try {
    if ($method === 'GET') {
        // ดึงข้อมูลทั้งหมด
        $dataSql = "
            SELECT 
                c.category_id,
                c.name as category_name,
                s.subcategory_id,
                s.name as subcategory_name,
                s.type
            FROM equipment_categories c
            LEFT JOIN equipment_subcategories s ON c.category_id = s.category_id
            ORDER BY c.category_id DESC, s.subcategory_id
        ";
        $stmt = $dbh->prepare($dataSql);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "status" => "ok",
            "data" => $results
        ], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'POST') {
        // ==== CREATE ====
        if (empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก name"]);
            exit;
        }

        $stmt = $dbh->prepare("INSERT INTO equipment_categories (name) VALUES (:name)");
        $stmt->bindParam(":name", $input['name']);
        $stmt->execute();

        echo json_encode(["status" => "ok", "message" => "เพิ่มข้อมูลเรียบร้อย"]);

    } elseif ($method === 'PUT') {
        // ==== UPDATE ====
        if (empty($input['category_id']) || empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก category_id และ name"]);
            exit;
        }

        $stmt = $dbh->prepare("UPDATE equipment_categories SET name = :name WHERE category_id = :id");
        $stmt->bindParam(":name", $input['name']);
        $stmt->bindParam(":id", $input['category_id']);
        $stmt->execute();

        echo json_encode(["status" => "ok", "message" => "แก้ไขข้อมูลเรียบร้อย"]);

    } elseif ($method === 'DELETE') {
        // ==== DELETE ====
        if (empty($input['category_id'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก category_id"]);
            exit;
        }

        $stmt = $dbh->prepare("DELETE FROM equipment_categories WHERE category_id = :id");
        $stmt->bindParam(":id", $input['category_id']);
        $stmt->execute();

        echo json_encode(["status" => "ok", "message" => "ลบข้อมูลเรียบร้อย"]);

    } else {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    }

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
