<?php
include "../config/jwt.php"; // DB + JWT

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents("php://input"), true);

// ถ้าเป็น POST และมี _method ให้ใช้ method นั้น
if ($method === 'POST' && isset($input['_method'])) {
    $method = strtoupper($input['_method']);
}

try {
    if ($method === 'GET') {
        // READ
        $stmt = $dbh->prepare("
            SELECT 
                company_id,
                name,
                tax_number,
                address,
                phone,
                email,
                line_id,
                details
            FROM companies
            ORDER BY company_id DESC
        ");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["status" => "ok", "data" => $results], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'POST') {
        // CREATE
        if (empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก name"]);
            exit;
        }
        $stmt = $dbh->prepare("
            INSERT INTO companies (name, tax_number, address, phone, email, line_id, details) 
            VALUES (:name, :tax_number, :address, :phone, :email, :line_id, :details)
        ");
        $stmt->bindParam(":name", $input['name']);
        $stmt->bindParam(":tax_number", $input['tax_number']);
        $stmt->bindParam(":address", $input['address']);
        $stmt->bindParam(":phone", $input['phone']);
        $stmt->bindParam(":email", $input['email']);
        $stmt->bindParam(":line_id", $input['line_id']);
        $stmt->bindParam(":details", $input['details']);
        $stmt->execute();
        echo json_encode(["status" => "ok", "message" => "เพิ่มข้อมูลเรียบร้อย"]);

    } elseif ($method === 'PUT') {
        // UPDATE
        if (empty($input['company_id']) || empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก company_id และ name"]);
            exit;
        }
        $stmt = $dbh->prepare("
            UPDATE companies 
            SET name = :name, tax_number = :tax_number, address = :address, 
                phone = :phone, email = :email, line_id = :line_id, details = :details
            WHERE company_id = :id
        ");
        $stmt->bindParam(":name", $input['name']);
        $stmt->bindParam(":tax_number", $input['tax_number']);
        $stmt->bindParam(":address", $input['address']);
        $stmt->bindParam(":phone", $input['phone']);
        $stmt->bindParam(":email", $input['email']);
        $stmt->bindParam(":line_id", $input['line_id']);
        $stmt->bindParam(":details", $input['details']);
        $stmt->bindParam(":id", $input['company_id']);
        $stmt->execute();
        echo json_encode(["status" => "ok", "message" => "แก้ไขข้อมูลเรียบร้อย"]);

    } elseif ($method === 'DELETE') {
        // DELETE
        if (empty($input['company_id'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก company_id"]);
            exit;
        }
        $stmt = $dbh->prepare("DELETE FROM companies WHERE company_id = :id");
        $stmt->bindParam(":id", $input['company_id']);
        $stmt->execute();
        echo json_encode(["status" => "ok", "message" => "ลบข้อมูลเรียบร้อย"]);

    } else {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    }

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
