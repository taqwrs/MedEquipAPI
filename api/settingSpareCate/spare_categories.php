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
    if ($method === 'GET') {
        $stmt = $dbh->prepare("
            SELECT 
                c.spare_category_id,
                c.name AS category_name,
                s.spare_subcategory_id,
                s.name AS subcategory_name
            FROM spare_categories c
            LEFT JOIN spare_subcategories s 
                ON c.spare_category_id = s.spare_category_id
            ORDER BY c.spare_category_id DESC, s.spare_subcategory_id
        ");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["status" => "ok", "data" => $results], JSON_UNESCAPED_UNICODE);
    } elseif ($method === 'POST' && isset($input['check_duplicate'])) {
        // ตรวจสอบชื่อซ้ำ
        if (empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอกชื่อหมวดหมู่อะไหล่"]);
            exit;
        }

        $sql = "SELECT spare_category_id 
                FROM spare_categories 
                WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name))";

        if (!empty($input['spare_category_id'])) {
            // ถ้าเป็น edit ให้ exclude ตัวเอง
            $sql .= " AND spare_category_id != :id";
        }

        $stmt = $dbh->prepare($sql);
        $stmt->bindParam(":name", $input['name']);
        if (!empty($input['spare_category_id'])) {
            $stmt->bindParam(":id", $input['spare_category_id']);
        }
        $stmt->execute();
        $exists = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            "status" => "ok",
            "duplicate" => $exists ? true : false
        ]);
        exit;
    } elseif ($method === 'POST') {
        // CREATE
        if (empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก name"]);
            exit;
        }

        // ตรวจสอบชื่อซ้ำก่อนเพิ่ม
        $stmtCheck = $dbh->prepare("SELECT spare_category_id FROM spare_categories WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name))");
        $stmtCheck->bindParam(":name", $input['name']);
        $stmtCheck->execute();
        if ($stmtCheck->fetch(PDO::FETCH_ASSOC)) {
            echo json_encode(["status" => "error", "message" => "ชื่อหมวดหมู่อะไหล่นี้มีอยู่แล้ว"]);
            exit;
        }

        $stmt = $dbh->prepare("INSERT INTO spare_categories (name) VALUES (:name)");
        $stmt->bindParam(":name", $input['name']);
        $stmt->execute();

        $newId = $dbh->lastInsertId();
        $logData = ['spare_category_id' => $newId, 'name' => $input['name']];

        $logModel->insertLog($u_id, 'spare_categories', 'INSERT', null, $logData);

        echo json_encode(["status" => "ok", "message" => "เพิ่มข้อมูลเรียบร้อย", "id" => $newId]);
    } elseif ($method === 'PUT') {
        // UPDATE
        if (empty($input['spare_category_id']) || empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก spare_category_id และ name"]);
            exit;
        }

        // ตรวจสอบชื่อซ้ำก่อนแก้ไข (ยกเว้นตัวเอง)
        $stmtCheck = $dbh->prepare("SELECT spare_category_id FROM spare_categories WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name)) AND spare_category_id != :id");
        $stmtCheck->bindParam(":name", $input['name']);
        $stmtCheck->bindParam(":id", $input['spare_category_id']);
        $stmtCheck->execute();
        if ($stmtCheck->fetch(PDO::FETCH_ASSOC)) {
            echo json_encode(["status" => "error", "message" => "ชื่อหมวดหมู่อะไหล่นี้มีอยู่แล้ว"]);
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

        $logData = ['spare_category_id' => $input['spare_category_id'], 'name' => $input['name']];
        $logModel->insertLog($u_id, 'spare_categories', 'UPDATE', $oldData, $logData);

        echo json_encode(["status" => "ok", "message" => "แก้ไขข้อมูลเรียบร้อย"]);
    } elseif ($method === 'DELETE') {
        // DELETE
        if (empty($input['spare_category_id'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก spare_category_id"]);
            exit;
        }

        // 🔹 ตรวจสอบว่าหมวดหมู่นี้ถูกใช้ใน spare_subcategories หรือไม่
        $stmtCheckUse = $dbh->prepare("
            SELECT COUNT(*) AS cnt 
            FROM spare_subcategories 
            WHERE spare_category_id = :id
        ");
        $stmtCheckUse->bindParam(":id", $input['spare_category_id']);
        $stmtCheckUse->execute();
        $useCheck = $stmtCheckUse->fetch(PDO::FETCH_ASSOC);
        $count = $useCheck['cnt'] ?? 0;

        if ($count > 0) {
            echo json_encode([
                "status" => "error",
                "message" => "ไม่สามารถลบหมวดหมู่เครื่องมือได้เนื่องจากถูกใช้กับชนิดเครื่องมือจำนวน {$count} รายการ"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ดึงข้อมูลเดิมก่อนลบ
        $stmtOld = $dbh->prepare("SELECT * FROM spare_categories WHERE spare_category_id = :id");
        $stmtOld->bindParam(":id", $input['spare_category_id']);
        $stmtOld->execute();
        $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

        // ลบข้อมูล
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
