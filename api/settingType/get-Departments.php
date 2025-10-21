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
        $stmt = $dbh->prepare("SELECT department_id, department_name FROM departments ORDER BY department_id DESC");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["status" => "ok", "data" => $results], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'POST') {
        // ถ้ามี check_name ให้เช็คชื่อซ้ำจากทั้งหมด
        if (isset($input['check_name'])) {
            $nameToCheck = trim($input['check_name']);
            if (!$nameToCheck) {
                echo json_encode(["status" => "error", "message" => "กรุณากรอกชื่อแผนก"]);
                exit;
            }

            $stmt = $dbh->prepare("SELECT department_id FROM departments WHERE LOWER(department_name) = LOWER(:name)");
            $stmt->bindParam(":name", $nameToCheck);
            $stmt->execute();
            $exists = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($exists) {
                echo json_encode(["status" => "error", "message" => "ชื่อแผนกนี้มีอยู่แล้ว"]);
            } else {
                echo json_encode(["status" => "ok", "message" => "สามารถใช้ชื่อได้"]);
            }
            exit;
        }

        // CREATE
        if (empty($input['department_name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก department_name"]);
            exit;
        }

        // เช็คชื่อซ้ำก่อน insert
        $stmtCheck = $dbh->prepare("SELECT department_id FROM departments WHERE LOWER(department_name) = LOWER(:name)");
        $stmtCheck->bindParam(":name", $input['department_name']);
        $stmtCheck->execute();
        if ($stmtCheck->fetch(PDO::FETCH_ASSOC)) {
            echo json_encode(["status" => "error", "message" => "ชื่อแผนกนี้มีอยู่แล้ว"]);
            exit;
        }

        $stmt = $dbh->prepare("INSERT INTO departments (department_name) VALUES (:department_name)");
        $stmt->bindParam(":department_name", $input['department_name']);
        $stmt->execute();

        $newId = $dbh->lastInsertId();

        $logData = [
            'department_id' => $newId,
            'department_name' => $input['department_name']
        ];

        $logModel->insertLog($u_id, 'departments', 'INSERT', null, $logData);

        echo json_encode(["status" => "ok", "message" => "เพิ่มข้อมูลเรียบร้อย"]);

    } elseif ($method === 'PUT') {
        // UPDATE
        if (empty($input['department_id']) || empty($input['department_name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก department_id และ department_name"]);
            exit;
        }

        // เช็คชื่อซ้ำ (ไม่รวมตัวเอง)
        $stmtCheck = $dbh->prepare("SELECT department_id FROM departments WHERE LOWER(department_name) = LOWER(:name) AND department_id != :id");
        $stmtCheck->bindParam(":name", $input['department_name']);
        $stmtCheck->bindParam(":id", $input['department_id']);
        $stmtCheck->execute();
        if ($stmtCheck->fetch(PDO::FETCH_ASSOC)) {
            echo json_encode(["status" => "error", "message" => "ชื่อแผนกนี้มีอยู่แล้ว"]);
            exit;
        }

        // ดึงข้อมูลเดิม
        $stmtOld = $dbh->prepare("SELECT * FROM departments WHERE department_id = :id");
        $stmtOld->bindParam(":id", $input['department_id']);
        $stmtOld->execute();
        $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

        // อัพเดทข้อมูล
        $stmt = $dbh->prepare("UPDATE departments SET department_name = :department_name WHERE department_id = :id");
        $stmt->bindParam(":department_name", $input['department_name']);
        $stmt->bindParam(":id", $input['department_id']);
        $stmt->execute();

        $logData = [
            'department_id' => $input['department_id'],
            'department_name' => $input['department_name']
        ];

        $logModel->insertLog($u_id, 'departments', 'UPDATE', $oldData, $logData);

        echo json_encode(["status" => "ok", "message" => "แก้ไขข้อมูลเรียบร้อย"]);

    } elseif ($method === 'DELETE') {
        // DELETE
        if (empty($input['department_id'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก department_id"]);
            exit;
        }

        // ดึงข้อมูลก่อนลบ
        $stmtOld = $dbh->prepare("SELECT * FROM departments WHERE department_id = :id");
        $stmtOld->bindParam(":id", $input['department_id']);
        $stmtOld->execute();
        $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

        // ลบข้อมูล
        $stmt = $dbh->prepare("DELETE FROM departments WHERE department_id = :id");
        $stmt->bindParam(":id", $input['department_id']);
        $stmt->execute();

        $logModel->insertLog($u_id, 'departments', 'DELETE', $oldData, null);

        echo json_encode(["status" => "ok", "message" => "ลบข้อมูลเรียบร้อย"]);

    } else {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    }

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
