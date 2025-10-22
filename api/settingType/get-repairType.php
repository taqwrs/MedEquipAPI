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
$repair_type_id = $input['repair_type_id'] ?? $_GET['repair_type_id'] ?? null;

try {
    $dbh->beginTransaction();

    if ($method === 'POST' && isset($input['check_duplicate'])) {
        $name_type = trim($input['name_type']);
        $edit_id = $input['repair_type_id'] ?? null;

        $sql = "SELECT COUNT(*) AS count FROM repair_type WHERE LOWER(name_type) = LOWER(:name_type)";
        if ($edit_id) {
            $sql .= " AND repair_type_id != :edit_id";
        }
        $stmt = $dbh->prepare($sql);
        $stmt->bindParam(":name_type", $name_type);
        if ($edit_id) $stmt->bindParam(":edit_id", $edit_id);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            "status" => "ok",
            "duplicate" => $row['count'] > 0
        ], JSON_UNESCAPED_UNICODE);
        $dbh->rollBack(); 
        exit;
    }

    // ✅ GET ทั้งหมด หรือเฉพาะ ID
    if ($method === 'GET') {
        if ($repair_type_id) {
            $stmt = $dbh->prepare("
                SELECT rt.repair_type_id, rt.name_type,
                       gu.group_user_id, gu.group_name, gu.type AS group_type
                FROM repair_type rt
                LEFT JOIN group_user gu ON rt.group_user_id = gu.group_user_id
                WHERE rt.repair_type_id = :repair_type_id
            ");
            $stmt->bindParam(":repair_type_id", $repair_type_id);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$row) {
                throw new Exception("ไม่พบข้อมูล repair_type_id นี้");
            }
            
            echo json_encode(["status" => "ok", "data" => $row], JSON_UNESCAPED_UNICODE);
        } else {
            $stmt = $dbh->prepare("
                SELECT rt.repair_type_id, rt.name_type,
                       gu.group_user_id, gu.group_name, gu.type AS group_type
                FROM repair_type rt
                LEFT JOIN group_user gu ON rt.group_user_id = gu.group_user_id
                ORDER BY rt.repair_type_id DESC
            ");
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["status" => "ok", "data" => $results], JSON_UNESCAPED_UNICODE);
        }

    } elseif ($method === 'POST') {
        if (empty($input['name_type']) || empty($input['group_user_id'])) {
            throw new Exception("กรุณากรอก name_type และ group_user_id");
        }

        $stmt = $dbh->prepare("
            INSERT INTO repair_type (name_type, group_user_id)
            VALUES (:name_type, :group_user_id)
        ");
        $stmt->bindParam(":name_type", $input['name_type']);
        $stmt->bindParam(":group_user_id", $input['group_user_id']);
        $stmt->execute();

        $newId = $dbh->lastInsertId();

        $logModel->insertLog($u_id, 'repair_type', 'INSERT', null, [
            'repair_type_id' => $newId,
            'name_type' => $input['name_type'],
            'group_user_id' => $input['group_user_id']
        ]);

        echo json_encode(["status" => "ok", "message" => "เพิ่มประเภทงานซ่อมเรียบร้อย", "repair_type_id" => $newId], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'PUT') {
        if (!$repair_type_id || empty($input['name_type']) || empty($input['group_user_id'])) {
            throw new Exception("กรุณากรอก repair_type_id, name_type และ group_user_id");
        }

        $stmtOld = $dbh->prepare("SELECT * FROM repair_type WHERE repair_type_id = :id");
        $stmtOld->bindParam(":id", $repair_type_id);
        $stmtOld->execute();
        $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

        if (!$oldData) {
            throw new Exception("ไม่พบข้อมูลที่จะแก้ไข");
        }

        $stmt = $dbh->prepare("
            UPDATE repair_type
            SET name_type = :name_type, group_user_id = :group_user_id
            WHERE repair_type_id = :id
        ");
        $stmt->bindParam(":name_type", $input['name_type']);
        $stmt->bindParam(":group_user_id", $input['group_user_id']);
        $stmt->bindParam(":id", $repair_type_id);
        $stmt->execute();

        $logModel->insertLog($u_id, 'repair_type', 'UPDATE', $oldData, $input);

        echo json_encode(["status" => "ok", "message" => "แก้ไขประเภทงานซ่อมเรียบร้อย"], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'DELETE') {
        if (!$repair_type_id) {
            throw new Exception("กรุณากรอก repair_type_id");
        }

        $stmtOld = $dbh->prepare("SELECT * FROM repair_type WHERE repair_type_id = :id");
        $stmtOld->bindParam(":id", $repair_type_id);
        $stmtOld->execute();
        $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

        if (!$oldData) {
            throw new Exception("ไม่พบข้อมูลที่จะลบ");
        }

        $stmt = $dbh->prepare("DELETE FROM repair_type WHERE repair_type_id = :id");
        $stmt->bindParam(":id", $repair_type_id);
        $stmt->execute();

        $logModel->insertLog($u_id, 'repair_type', 'DELETE', $oldData, null);

        echo json_encode(["status" => "ok", "message" => "ลบประเภทงานซ่อมเรียบร้อย"], JSON_UNESCAPED_UNICODE);

    } else {
        throw new Exception("Method not allowed");
    }

    $dbh->commit();

} catch (Exception $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack(); 
    }
    echo json_encode(["status" => "error", "message" => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
?>
