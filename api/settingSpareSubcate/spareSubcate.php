<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE,OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents("php://input"), true);


if ($method === 'POST' && isset($input['_method'])) {
    $method = strtoupper($input['_method']);
}

try {
    if ($method === 'GET') {
        $stmt = $dbh->prepare("
            SELECT 
                s.spare_subcategory_id,
                s.spare_category_id,
                s.name AS spare_subcategory_name,
                c.name AS spare_category_name
            FROM spare_subcategories s
            JOIN spare_categories c 
                ON s.spare_category_id = c.spare_category_id
            ORDER BY s.spare_subcategory_id DESC
        ");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["status" => "ok", "data" => $results], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'POST') {
        // CREATE
        if (empty($input['spare_category_id']) || empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก spare_category_id และ name"]);
            exit;
        }
        $stmt = $dbh->prepare("
            INSERT INTO spare_subcategories (spare_category_id, name) 
            VALUES (:spare_category_id, :name)
        ");
        $stmt->bindParam(":spare_category_id", $input['spare_category_id']);
        $stmt->bindParam(":name", $input['name']);
        $stmt->execute();
        echo json_encode(["status" => "ok", "message" => "เพิ่มข้อมูลเรียบร้อย"]);

    } elseif ($method === 'PUT') {
        // UPDATE
        if (empty($input['spare_subcategory_id']) || empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก spare_subcategory_id และ name"]);
            exit;
        }
        $stmt = $dbh->prepare("
            UPDATE spare_subcategories 
            SET spare_category_id = :spare_category_id, 
                name = :name
            WHERE spare_subcategory_id = :id
        ");
        $stmt->bindParam(":spare_category_id", $input['spare_category_id']);
        $stmt->bindParam(":name", $input['name']);
        $stmt->bindParam(":id", $input['spare_subcategory_id']);
        $stmt->execute();
        echo json_encode(["status" => "ok", "message" => "แก้ไขข้อมูลเรียบร้อย"]);

    } elseif ($method === 'DELETE') {
        // DELETE
        if (empty($input['spare_subcategory_id'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก spare_subcategory_id"]);
            exit;
        }
        $stmt = $dbh->prepare("DELETE FROM spare_subcategories WHERE spare_subcategory_id = :id");
        $stmt->bindParam(":id", $input['spare_subcategory_id']);
        $stmt->execute();
        echo json_encode(["status" => "ok", "message" => "ลบข้อมูลเรียบร้อย"]);

    } else {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    }

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
