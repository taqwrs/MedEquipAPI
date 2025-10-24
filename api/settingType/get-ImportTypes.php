<?php
include "../config/jwt.php"; // DB + JWT
include "../config/LogModel.php"; // LogModel

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents("php://input"), true);

// ใช้ _method สำหรับ PUT/DELETE
if ($method === 'POST' && isset($input['_method'])) {
    $method = strtoupper($input['_method']);
}

// ดึง ID ผู้ใช้จาก JWT
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
        // READ: ดึงข้อมูลทั้งหมด
        $stmt = $dbh->prepare("SELECT import_type_id, name FROM import_types ORDER BY import_type_id DESC");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["status" => "ok", "data" => $results], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'POST') {
        // ตรวจสอบชื่อซ้ำ
        if (isset($input['check_duplicate']) && !empty($input['name'])) {
            $name = trim($input['name']);
            $editId = $input['import_type_id'] ?? null;

            $sql = "SELECT COUNT(*) AS count FROM import_types WHERE LOWER(name) = LOWER(:name)";
            if ($editId) $sql .= " AND import_type_id != :editId";
            $stmt = $dbh->prepare($sql);
            $stmt->bindParam(":name", $name);
            if ($editId) $stmt->bindParam(":editId", $editId);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                "status" => "ok",
                "duplicate" => $row['count'] > 0
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // CREATE
        if (empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอกชื่อ"]);
            exit;
        }

        $stmt = $dbh->prepare("INSERT INTO import_types (name) VALUES (:name)");
        $stmt->bindParam(":name", $input['name']);
        $stmt->execute();
        $newId = $dbh->lastInsertId();

        $logModel->insertLog($u_id, 'import_types', 'INSERT', null, [
            'import_type_id' => $newId,
            'name' => $input['name']
        ]);

        echo json_encode(["status" => "ok", "message" => "เพิ่มข้อมูลเรียบร้อย", "last_id" => $newId]);

    } elseif ($method === 'PUT') {
        // UPDATE
        if (empty($input['import_type_id']) || empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก import_type_id และ name"]);
            exit;
        }

        $stmtOld = $dbh->prepare("SELECT * FROM import_types WHERE import_type_id = :id");
        $stmtOld->bindParam(":id", $input['import_type_id']);
        $stmtOld->execute();
        $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

        $stmt = $dbh->prepare("UPDATE import_types SET name = :name WHERE import_type_id = :id");
        $stmt->bindParam(":name", $input['name']);
        $stmt->bindParam(":id", $input['import_type_id']);
        $stmt->execute();

        $logModel->insertLog($u_id, 'import_types', 'UPDATE', $oldData, [
            'import_type_id' => $input['import_type_id'],
            'name' => $input['name']
        ]);

        echo json_encode(["status" => "ok", "message" => "แก้ไขข้อมูลเรียบร้อย"]);

    } elseif ($method === 'DELETE') {
        // DELETE
        if (empty($input['import_type_id'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก import_type_id"]);
            exit;
        }
        // ตรวจสอบในตาราง equipments
        $stmtCheckEquipment = $dbh->prepare("
            SELECT COUNT(*) as count 
            FROM equipments 
            WHERE import_type_id = :import_type_id
        ");
        $stmtCheckEquipment->bindParam(":import_type_id", $input['import_type_id']);
        $stmtCheckEquipment->execute();
        $equipmentCount = $stmtCheckEquipment->fetch()['count'];

        // ตรวจสอบในตาราง spare_parts
        $stmtCheckSparePart = $dbh->prepare("
            SELECT COUNT(*) as count 
            FROM spare_parts 
            WHERE import_type_id = :import_type_id
        ");
        $stmtCheckSparePart->bindParam(":import_type_id", $input['import_type_id']);
        $stmtCheckSparePart->execute();
        $sparePartCount = $stmtCheckSparePart->fetch()['count'];

        $totalUsage = $equipmentCount + $sparePartCount;

        if ($totalUsage > 0) {
            echo json_encode([
                "status" => "error",
                "message" => "ไม่สามารถลบประเภทการนำเข้านี้ได้เนื่องจากถูกใช้งานอยู่ " . $totalUsage . " รายการ",
                "usage_count" => $totalUsage
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmtOld = $dbh->prepare("SELECT * FROM import_types WHERE import_type_id = :id");
        $stmtOld->bindParam(":id", $input['import_type_id']);
        $stmtOld->execute();
        $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

        $stmt = $dbh->prepare("DELETE FROM import_types WHERE import_type_id = :id");
        $stmt->bindParam(":id", $input['import_type_id']);
        $stmt->execute();

        $logModel->insertLog($u_id, 'import_types', 'DELETE', $oldData, null);

        echo json_encode(["status" => "ok", "message" => "ลบข้อมูลเรียบร้อย"]);

    } else {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    }

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
