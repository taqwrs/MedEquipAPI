<?php
include "../config/jwt.php"; // DB + JWT
include "../config/LogModel.php"; // LogModel

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents("php://input"), true);

// ถ้าเป็น POST และมี _method ให้ใช้ method นั้น
if ($method === 'POST' && isset($input['_method'])) {
    $method = strtoupper($input['_method']);
}

// ดึง ID ของผู้ใช้จาก JWT
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
        // READ
        $stmt = $dbh->prepare("SELECT writeoff_types_id, name FROM writeoff_types ORDER BY writeoff_types_id DESC");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["status" => "ok", "data" => $results], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'POST') {
        // CREATE
        if (empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก name"]);
            exit;
        }

        $stmt = $dbh->prepare("INSERT INTO writeoff_types (name) VALUES (:name)");
        $stmt->bindParam(":name", $input['name']);
        $stmt->execute();

        $newId = $dbh->lastInsertId();

        $logData = [
            'writeoff_types_id' => $newId,
            'name' => $input['name']
        ];

        $logModel->insertLog($u_id, 'writeoff_types', 'INSERT', null, $logData);

        echo json_encode(["status" => "ok", "message" => "เพิ่มข้อมูลเรียบร้อย"]);

    } elseif ($method === 'PUT') {
        // UPDATE
        if (empty($input['writeoff_types_id']) || empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก writeoff_types_id และ name"]);
            exit;
        }

        // ดึงข้อมูลเดิม
        $stmtOld = $dbh->prepare("SELECT * FROM writeoff_types WHERE writeoff_types_id = :id");
        $stmtOld->bindParam(":id", $input['writeoff_types_id']);
        $stmtOld->execute();
        $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

        // อัพเดทข้อมูล
        $stmt = $dbh->prepare("UPDATE writeoff_types SET name = :name WHERE writeoff_types_id = :id");
        $stmt->bindParam(":name", $input['name']);
        $stmt->bindParam(":id", $input['writeoff_types_id']);
        $stmt->execute();

        $logData = [
            'writeoff_types_id' => $input['writeoff_types_id'],
            'name' => $input['name']
        ];

        $logModel->insertLog($u_id, 'writeoff_types', 'UPDATE', $oldData, $logData);

        echo json_encode(["status" => "ok", "message" => "แก้ไขข้อมูลเรียบร้อย"]);

    } elseif ($method === 'DELETE') {
        // DELETE
        if (empty($input['writeoff_types_id'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก writeoff_types_id"]);
            exit;
        }

        // ดึงข้อมูลก่อนลบ
        $stmtOld = $dbh->prepare("SELECT * FROM writeoff_types WHERE writeoff_types_id = :id");
        $stmtOld->bindParam(":id", $input['writeoff_types_id']);
        $stmtOld->execute();
        $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

        // ลบข้อมูล
        $stmt = $dbh->prepare("DELETE FROM writeoff_types WHERE writeoff_types_id = :id");
        $stmt->bindParam(":id", $input['writeoff_types_id']);
        $stmt->execute();

        $logModel->insertLog($u_id, 'writeoff_types', 'DELETE', $oldData, null);

        echo json_encode(["status" => "ok", "message" => "ลบข้อมูลเรียบร้อย"]);

    } else {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    }

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>