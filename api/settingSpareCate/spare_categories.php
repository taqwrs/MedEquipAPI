<?php
include "../config/jwt.php";
include "../config/LogModel.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents("php://input"), true);

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
    if ($method === 'POST') {

        // เช็คชื่อซ้ำ
        if (!empty($input['check_duplicate']) && !empty($input['name'])) {
            $query = "SELECT spare_category_id FROM spare_categories WHERE LOWER(name) = LOWER(:name)";
            $params = [":name" => trim($input['name'])];

            if (!empty($input['spare_category_id'])) {
                // ถ้าแก้ไข ให้ยกเว้นตัวเอง
                $query .= " AND spare_category_id != :id";
                $params[":id"] = $input['spare_category_id'];
            }

            $stmt = $dbh->prepare($query);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->execute();
            $exists = $stmt->rowCount() > 0;

            echo json_encode(["status" => "ok", "duplicate" => $exists]);
            exit;
        }

        // CREATE
        if (empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก name"]);
            exit;
        }

        $stmt = $dbh->prepare("INSERT INTO spare_categories (name) VALUES (:name)");
        $stmt->bindParam(":name", $input['name']);
        $stmt->execute();

        $newId = $dbh->lastInsertId();
        $logData = ['spare_category_id' => $newId,'name' => $input['name']];
        $logModel->insertLog($u_id, 'spare_categories', 'INSERT', null, $logData);

        echo json_encode(["status" => "ok", "message" => "เพิ่มข้อมูลเรียบร้อย"]);

    } elseif ($method === 'PUT') {
        // UPDATE
        if (empty($input['spare_category_id']) || empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก spare_category_id และ name"]);
            exit;
        }

        // ดึงข้อมูลเดิม
        $stmtOld = $dbh->prepare("SELECT * FROM spare_categories WHERE spare_category_id = :id");
        $stmtOld->bindParam(":id", $input['spare_category_id']);
        $stmtOld->execute();
        $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

        // อัพเดทข้อมูล
        $stmt = $dbh->prepare("UPDATE spare_categories SET name = :name WHERE spare_category_id = :id");
        $stmt->bindParam(":name", $input['name']);
        $stmt->bindParam(":id", $input['spare_category_id']);
        $stmt->execute();

        $logData = ['spare_category_id' => $input['spare_category_id'],'name' => $input['name']];
        $logModel->insertLog($u_id, 'spare_categories', 'UPDATE', $oldData, $logData);

        echo json_encode(["status" => "ok", "message" => "แก้ไขข้อมูลเรียบร้อย"]);

    } elseif ($method === 'DELETE') {
        // DELETE
        if (empty($input['spare_category_id'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก spare_category_id"]);
            exit;
        }

        $stmtOld = $dbh->prepare("SELECT * FROM spare_categories WHERE spare_category_id = :id");
        $stmtOld->bindParam(":id", $input['spare_category_id']);
        $stmtOld->execute();
        $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

        $stmt = $dbh->prepare("DELETE FROM spare_categories WHERE spare_category_id = :id");
        $stmt->bindParam(":id", $input['spare_category_id']);
        $stmt->execute();

        $logModel->insertLog($u_id, 'spare_categories', 'DELETE', $oldData, null);

        echo json_encode(["status" => "ok", "message" => "ลบข้อมูลเรียบร้อย"]);

    } else {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    }

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
