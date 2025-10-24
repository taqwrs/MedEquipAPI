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

    } elseif ($method === 'POST' && isset($input['check_duplicate'])) {
        // ตรวจสอบชื่อซ้ำ
        if (empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอกชื่อชนิดอะไหล่"]);
            exit;
        }

        $sql = "SELECT spare_subcategory_id 
                FROM spare_subcategories 
                WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name))";
        
        if (!empty($input['spare_subcategory_id'])) {
            // ถ้าเป็น edit ให้ exclude ตัวเอง
            $sql .= " AND spare_subcategory_id != :id";
        }

        $stmt = $dbh->prepare($sql);
        $stmt->bindParam(":name", $input['name']);
        if (!empty($input['spare_subcategory_id'])) {
            $stmt->bindParam(":id", $input['spare_subcategory_id']);
        }
        $stmt->execute();
        $exists = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            "status" => "ok",
            "duplicate" => $exists ? true : false
        ]);
        exit;

    } elseif ($method === 'POST') {
        // INSERT
        if (empty($input['name']) || empty($input['spare_category_id'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอกข้อมูลให้ครบถ้วน"]);
            exit;
        }

        // ตรวจสอบชื่อซ้ำก่อนเพิ่ม
        $stmtCheck = $dbh->prepare("SELECT spare_subcategory_id FROM spare_subcategories WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name))");
        $stmtCheck->bindParam(":name", $input['name']);
        $stmtCheck->execute();
        if ($stmtCheck->fetch(PDO::FETCH_ASSOC)) {
            echo json_encode(["status" => "error", "message" => "ชื่อชนิดอะไหล่นี้มีอยู่แล้ว"]);
            exit;
        }

        $dbh->beginTransaction();
        try {
            $stmt = $dbh->prepare("
                INSERT INTO spare_subcategories (spare_category_id, name) 
                VALUES (:spare_category_id, :name)
            ");
            $stmt->bindParam(":spare_category_id", $input['spare_category_id']);
            $stmt->bindParam(":name", $input['name']);
            $stmt->execute();

            $newId = $dbh->lastInsertId();

            $logData = [
                'spare_subcategory_id' => $newId,
                'spare_category_id' => $input['spare_category_id'],
                'name' => $input['name']
            ];

            $logModel->insertLog($u_id, 'spare_subcategories', 'INSERT', null, $logData);

            $dbh->commit();

            echo json_encode(["status" => "ok", "message" => "เพิ่มข้อมูลเรียบร้อย", "id" => $newId]);

        } catch (Exception $e) {
            $dbh->rollBack();
            throw $e;
        }

    } elseif ($method === 'PUT') {
        // UPDATE
        if (empty($input['spare_subcategory_id']) || empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก spare_subcategory_id และ name"]);
            exit;
        }

        // ตรวจสอบชื่อซ้ำก่อนแก้ไข (ยกเว้นตัวเอง)
        $stmtCheck = $dbh->prepare("SELECT spare_subcategory_id FROM spare_subcategories WHERE LOWER(TRIM(name)) = LOWER(TRIM(:name)) AND spare_subcategory_id != :id");
        $stmtCheck->bindParam(":name", $input['name']);
        $stmtCheck->bindParam(":id", $input['spare_subcategory_id']);
        $stmtCheck->execute();
        if ($stmtCheck->fetch(PDO::FETCH_ASSOC)) {
            echo json_encode(["status" => "error", "message" => "ชื่อชนิดอะไหล่นี้มีอยู่แล้ว"]);
            exit;
        }

        $dbh->beginTransaction();
        try {
            // ดึงข้อมูลเดิม
            $stmtOld = $dbh->prepare("SELECT * FROM spare_subcategories WHERE spare_subcategory_id = :id");
            $stmtOld->bindParam(":id", $input['spare_subcategory_id']);
            $stmtOld->execute();
            $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

            if (!$oldData) {
                $dbh->rollBack();
                echo json_encode(["status" => "error", "message" => "ไม่พบข้อมูล spare subcategory"]);
                exit;
            }

            // อัพเดทข้อมูล
            $stmt = $dbh->prepare("
                UPDATE spare_subcategories 
                SET spare_category_id = :spare_category_id, name = :name
                WHERE spare_subcategory_id = :id
            ");
            $stmt->bindParam(":spare_category_id", $input['spare_category_id']);
            $stmt->bindParam(":name", $input['name']);
            $stmt->bindParam(":id", $input['spare_subcategory_id']);
            $stmt->execute();

            $logData = [
                'spare_subcategory_id' => $input['spare_subcategory_id'],
                'spare_category_id' => $input['spare_category_id'],
                'name' => $input['name']
            ];

            $logModel->insertLog($u_id, 'spare_subcategories', 'UPDATE', $oldData, $logData);

            $dbh->commit();

            echo json_encode(["status" => "ok", "message" => "แก้ไขข้อมูลเรียบร้อย"]);

        } catch (Exception $e) {
            $dbh->rollBack();
            throw $e;
        }

    } elseif ($method === 'DELETE') {
        // DELETE
        if (empty($input['spare_subcategory_id'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก spare_subcategory_id"]);
            exit;
        }

        $dbh->beginTransaction();

        try {
            // 🔹 ตรวจสอบว่ามีการใช้งานใน spare_parts หรือไม่
            $stmtCheckUse = $dbh->prepare("
                SELECT COUNT(*) AS cnt 
                FROM spare_parts 
                WHERE spare_subcategory_id = :id
            ");
            $stmtCheckUse->bindParam(":id", $input['spare_subcategory_id']);
            $stmtCheckUse->execute();
            $useCheck = $stmtCheckUse->fetch(PDO::FETCH_ASSOC);
            $count = $useCheck['cnt'] ?? 0;

            if ($count > 0) {
                echo json_encode([
                    "status" => "error",
                    "message" => "ไม่สามารถลบชนิดอะไหล่ได้เนื่องจากถูกใช้กับอะไหล่ {$count} รายการ"
                ], JSON_UNESCAPED_UNICODE);
                $dbh->rollBack();
                exit;
            }

            // 🔹 ดึงข้อมูลก่อนลบ
            $stmtOld = $dbh->prepare("SELECT * FROM spare_subcategories WHERE spare_subcategory_id = :id");
            $stmtOld->bindParam(":id", $input['spare_subcategory_id']);
            $stmtOld->execute();
            $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

            if (!$oldData) {
                $dbh->rollBack();
                echo json_encode(["status" => "error", "message" => "ไม่พบข้อมูล spare subcategory"]);
                exit;
            }

            // 🔹 ลบข้อมูล
            $stmt = $dbh->prepare("DELETE FROM spare_subcategories WHERE spare_subcategory_id = :id");
            $stmt->bindParam(":id", $input['spare_subcategory_id']);
            $stmt->execute();

            // 🔹 Log การลบ
            $logModel->insertLog($u_id, 'spare_subcategories', 'DELETE', $oldData, null);

            $dbh->commit();

            echo json_encode(["status" => "ok", "message" => "ลบข้อมูลเรียบร้อย"]);

        } catch (Exception $e) {
            $dbh->rollBack();
            throw $e;
        }


    } else {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    }

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>