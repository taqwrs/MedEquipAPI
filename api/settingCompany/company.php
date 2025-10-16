<?php
include "../config/jwt.php";
include "../config/LogModel.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents("php://input"), true);

if ($method === 'POST' && isset($input['_method'])) {
    $method = strtoupper($input['_method']);
}

//ดึง ID จากตาราง users โดยใช้ user_id จาก JWT
$stmtUser = $dbh->prepare("SELECT ID FROM users WHERE user_id = :user_id LIMIT 1");
$stmtUser->bindParam(":user_id", $user_id);
$stmtUser->execute();
$userData = $stmtUser->fetch(PDO::FETCH_ASSOC);
$u_id = $userData['ID'] ?? null;

if (!$u_id) {
    echo json_encode(["status" => "error", "message" => "ไม่พบข้อมูลผู้ใช้"]);
    exit;
}

$logModel = new LogModel($dbh);

try {
    if ($method === 'GET') {
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

        $newCompanyId = $dbh->lastInsertId();

        $logData = [
            'company_id' => $newCompanyId,
            'name' => $input['name'],
            'tax_number' => $input['tax_number'] ?? null,
            'address' => $input['address'] ?? null,
            'phone' => $input['phone'] ?? null,
            'email' => $input['email'] ?? null,
            'line_id' => $input['line_id'] ?? null,
            'details' => $input['details'] ?? null
        ];

        $logModel->insertLog($u_id, 'companies', 'INSERT', null, $logData);

        echo json_encode(["status" => "ok", "message" => "เพิ่มข้อมูลเรียบร้อย"]);

    } elseif ($method === 'PUT') {
        // UPDATE
        if (empty($input['company_id']) || empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก company_id และ name"]);
            exit;
        }

        // ดึงข้อมูลเดิมก่อน update
        $stmtOld = $dbh->prepare("SELECT * FROM companies WHERE company_id = :id");
        $stmtOld->bindParam(":id", $input['company_id']);
        $stmtOld->execute();
        $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

        // อัพเดทข้อมูล
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

        $logData = [
            'company_id' => $input['company_id'],
            'name' => $input['name'],
            'tax_number' => $input['tax_number'] ?? null,
            'address' => $input['address'] ?? null,
            'phone' => $input['phone'] ?? null,
            'email' => $input['email'] ?? null,
            'line_id' => $input['line_id'] ?? null,
            'details' => $input['details'] ?? null
        ];

        $logModel->insertLog($u_id, 'companies', 'UPDATE', $oldData, $logData);

        echo json_encode(["status" => "ok", "message" => "แก้ไขข้อมูลเรียบร้อย"]);

    } elseif ($method === 'DELETE') {
        // DELETE
        if (empty($input['company_id'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก company_id"]);
            exit;
        }

        // ดึงข้อมูลก่อนลบ
        $stmtOld = $dbh->prepare("SELECT * FROM companies WHERE company_id = :id");
        $stmtOld->bindParam(":id", $input['company_id']);
        $stmtOld->execute();
        $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

        // ลบข้อมูล
        $stmt = $dbh->prepare("DELETE FROM companies WHERE company_id = :id");
        $stmt->bindParam(":id", $input['company_id']);
        $stmt->execute();

        $logModel->insertLog($u_id, 'companies', 'DELETE', $oldData, null);

        echo json_encode(["status" => "ok", "message" => "ลบข้อมูลเรียบร้อย"]);

    } else {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    }

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>