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
        // ==== READ ====
        $dataSql = "
            SELECT 
                c.category_id,
                c.name AS category_name,
                s.subcategory_id,
                s.name AS subcategory_name,
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
            echo json_encode(["status" => "error", "message" => "กรุณากรอกชื่อหมวดหมู่"]);
            exit;
        }

        // ✅ ตรวจสอบชื่อซ้ำ
        $stmtCheck = $dbh->prepare("SELECT COUNT(*) FROM equipment_categories WHERE LOWER(name) = LOWER(:name)");
        $stmtCheck->bindParam(":name", $input['name']);
        $stmtCheck->execute();

        if ($stmtCheck->fetchColumn() > 0) {
            echo json_encode(["status" => "error", "message" => "ชื่อหมวดหมู่นี้มีอยู่แล้ว"]);
            exit;
        }

        // เพิ่มข้อมูล
        $stmt = $dbh->prepare("INSERT INTO equipment_categories (name) VALUES (:name)");
        $stmt->bindParam(":name", $input['name']);
        $stmt->execute();

        $newCategoryId = $dbh->lastInsertId();

        // Log
        $logModel->insertLog($u_id, 'equipment_categories', 'INSERT', null, [
            'category_id' => $newCategoryId,
            'name' => $input['name']
        ]);

        echo json_encode(["status" => "ok", "message" => "เพิ่มข้อมูลเรียบร้อย"]);

    } elseif ($method === 'PUT') {
        // ==== UPDATE ====
        if (empty($input['category_id']) || empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก category_id และ name"]);
            exit;
        }

        // ✅ ตรวจสอบชื่อซ้ำ (ยกเว้นตัวเอง)
        $stmtCheck = $dbh->prepare("
            SELECT COUNT(*) FROM equipment_categories 
            WHERE LOWER(name) = LOWER(:name) AND category_id != :id
        ");
        $stmtCheck->execute([
            ":name" => $input['name'],
            ":id" => $input['category_id']
        ]);

        if ($stmtCheck->fetchColumn() > 0) {
            echo json_encode(["status" => "error", "message" => "ชื่อหมวดหมู่นี้มีอยู่แล้ว"]);
            exit;
        }

        // ดึงข้อมูลเดิม
        $stmtOld = $dbh->prepare("SELECT * FROM equipment_categories WHERE category_id = :id");
        $stmtOld->bindParam(":id", $input['category_id']);
        $stmtOld->execute();
        $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

        // อัปเดต
        $stmt = $dbh->prepare("UPDATE equipment_categories SET name = :name WHERE category_id = :id");
        $stmt->bindParam(":name", $input['name']);
        $stmt->bindParam(":id", $input['category_id']);
        $stmt->execute();

        // Log
        $logModel->insertLog($u_id, 'equipment_categories', 'UPDATE', $oldData, [
            'category_id' => $input['category_id'],
            'name' => $input['name']
        ]);

        echo json_encode(["status" => "ok", "message" => "แก้ไขข้อมูลเรียบร้อย"]);

    } elseif ($method === 'DELETE') {
        // ==== DELETE ====
        if (empty($input['category_id'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก category_id"]);
            exit;
        }

        $stmtOld = $dbh->prepare("SELECT * FROM equipment_categories WHERE category_id = :id");
        $stmtOld->bindParam(":id", $input['category_id']);
        $stmtOld->execute();
        $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

        $stmt = $dbh->prepare("DELETE FROM equipment_categories WHERE category_id = :id");
        $stmt->bindParam(":id", $input['category_id']);
        $stmt->execute();

        $logModel->insertLog($u_id, 'equipment_categories', 'DELETE', $oldData, null);

        echo json_encode(["status" => "ok", "message" => "ลบข้อมูลเรียบร้อย"]);

    } else {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    }

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
