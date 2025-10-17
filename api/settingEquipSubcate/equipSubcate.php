<?php
include "../config/jwt.php"; 
include "../config/LogModel.php"; 

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents("php://input"), true);

if ($method === "POST" && isset($input["_method"])) {
    $method = strtoupper($input["_method"]);
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

try {
    // GET ENUM types
    if ($method === "GET" && isset($_GET["action"]) && $_GET["action"] === "types") {
        $stmt = $dbh->query("SHOW COLUMNS FROM equipment_subcategories LIKE 'type'");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        preg_match("/^enum\((.*)\)$/", $row['Type'], $matches);
        $enumValues = [];
        if (!empty($matches[1])) {
            $vals = explode(",", $matches[1]);
            foreach ($vals as $val) {
                $enumValues[] = trim($val, " '");
            }
        }

        echo json_encode(["status" => "ok", "data" => $enumValues], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // GET รายการ subcategories
    if ($method === "GET") {
        $stmt = $dbh->prepare("
            SELECT 
                es.subcategory_id,
                es.category_id,
                es.name AS subcategory_name,
                es.type AS subcategory_type,
                ec.name AS category_name,
                rg.relation_group_id,
                rg.group_user_id,
                gu.group_name,
                gu.type AS group_type
            FROM equipment_subcategories es
            JOIN equipment_categories ec 
                ON es.category_id = ec.category_id
            LEFT JOIN relation_group rg 
                ON es.subcategory_id = rg.subcategory_id
            LEFT JOIN group_user gu 
                ON rg.group_user_id = gu.group_user_id
            ORDER BY es.subcategory_id DESC
        ");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["status" => "ok", "data" => $results], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // POST (create)
    if ($method === "POST") {
        if (empty($input["category_id"]) || empty($input["name"]) || empty($input["type"])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก category_id, name, type"]);
            exit;
        }
        $dbh->beginTransaction();
        try {
            // เพิ่ม subcategory
            $stmt = $dbh->prepare("
                INSERT INTO equipment_subcategories (category_id, name, type)
                VALUES (:category_id, :name, :type)
            ");
            $stmt->bindParam(":category_id", $input["category_id"]);
            $stmt->bindParam(":name", $input["name"]);
            $stmt->bindParam(":type", $input["type"]);
            $stmt->execute();

            $newId = $dbh->lastInsertId();

            // ผูก relation_group หลายค่า (many-to-many)
            $group_user_ids = $input["group_user_ids"] ?? [];
            if (!empty($group_user_ids)) {
                $stmtRG = $dbh->prepare("
                    INSERT INTO relation_group (group_user_id, subcategory_id)
                    VALUES (:group_user_id, :subcategory_id)
                ");
                foreach ($group_user_ids as $gid) {
                    $stmtRG->bindParam(":group_user_id", $gid);
                    $stmtRG->bindParam(":subcategory_id", $newId);
                    $stmtRG->execute();
                }
            }

            $logData = [
                'subcategory_id' => $newId,
                'category_id' => $input["category_id"],
                'name' => $input["name"],
                'type' => $input["type"],
                'group_user_ids' => $group_user_ids
            ];

            $logModel->insertLog($u_id, 'equipment_subcategories', 'INSERT', null, $logData);

            $dbh->commit();

            echo json_encode(["status" => "ok", "message" => "เพิ่มข้อมูลเรียบร้อย", "subcategory_id" => $newId]);
            exit;

        } catch (Exception $e) {
            $dbh->rollBack();
            throw $e;
        }
    }

    // PUT (update)
    if ($method === "PUT") {
        if (empty($input["subcategory_id"]) || empty($input["name"]) || empty($input["type"])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก subcategory_id, name, type"]);
            exit;
        }
        $dbh->beginTransaction();
        try {
            // ดึงข้อมูลเดิม
            $stmtOld = $dbh->prepare("SELECT * FROM equipment_subcategories WHERE subcategory_id = :id");
            $stmtOld->bindParam(":id", $input["subcategory_id"]);
            $stmtOld->execute();
            $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

            if (!$oldData) {
                $dbh->rollBack();
                echo json_encode(["status" => "error", "message" => "ไม่พบข้อมูล subcategory"]);
                exit;
            }

            // อัปเดต subcategory
            $stmt = $dbh->prepare("
                UPDATE equipment_subcategories
                SET category_id = :category_id,
                    name = :name,
                    type = :type
                WHERE subcategory_id = :id
            ");
            $stmt->bindParam(":category_id", $input["category_id"]);
            $stmt->bindParam(":name", $input["name"]);
            $stmt->bindParam(":type", $input["type"]);
            $stmt->bindParam(":id", $input["subcategory_id"]);
            $stmt->execute();

            // อัปเดต relation_group (many-to-many)
            $stmtDel = $dbh->prepare("DELETE FROM relation_group WHERE subcategory_id = :subcategory_id");
            $stmtDel->bindParam(":subcategory_id", $input["subcategory_id"]);
            $stmtDel->execute();

            $group_user_ids = $input["group_user_ids"] ?? [];
            if (!empty($group_user_ids)) {
                $stmtRG = $dbh->prepare("
                    INSERT INTO relation_group (group_user_id, subcategory_id)
                    VALUES (:group_user_id, :subcategory_id)
                ");
                foreach ($group_user_ids as $gid) {
                    $stmtRG->bindParam(":group_user_id", $gid);
                    $stmtRG->bindParam(":subcategory_id", $input["subcategory_id"]);
                    $stmtRG->execute();
                }
            }

            $logData = [
                'subcategory_id' => $input["subcategory_id"],
                'category_id' => $input["category_id"],
                'name' => $input["name"],
                'type' => $input["type"],
                'group_user_ids' => $group_user_ids
            ];

            $logModel->insertLog($u_id, 'equipment_subcategories', 'UPDATE', $oldData, $logData);

            $dbh->commit();

            echo json_encode(["status" => "ok", "message" => "แก้ไขข้อมูลเรียบร้อย"]);
            exit;

        } catch (Exception $e) {
            $dbh->rollBack();
            throw $e;
        }
    }

    // DELETE
    if ($method === "DELETE") {
        if (empty($input["subcategory_id"])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก subcategory_id"]);
            exit;
        }
        $dbh->beginTransaction();

        try {
            // ดึงข้อมูล subcategory ก่อนลบ
            $stmtOld = $dbh->prepare("SELECT * FROM equipment_subcategories WHERE subcategory_id = :id");
            $stmtOld->bindParam(":id", $input["subcategory_id"]);
            $stmtOld->execute();
            $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

            if (!$oldData) {
                $dbh->rollBack();
                echo json_encode(["status" => "error", "message" => "ไม่พบข้อมูล subcategory"]);
                exit;
            }

            // ดึงข้อมูล relation_group ที่เกี่ยวข้อง
            $stmtRelations = $dbh->prepare("
                SELECT rg.*, gu.group_name, gu.type AS group_type
                FROM relation_group rg
                LEFT JOIN group_user gu ON rg.group_user_id = gu.group_user_id
                WHERE rg.subcategory_id = :id
            ");
            $stmtRelations->bindParam(":id", $input["subcategory_id"]);
            $stmtRelations->execute();
            $relationData = $stmtRelations->fetchAll(PDO::FETCH_ASSOC);

            // รวมข้อมูล relation_group เข้าไปใน oldData
            $oldData['relations'] = $relationData;

            // ลบ relation_group ก่อน
            $stmt1 = $dbh->prepare("DELETE FROM relation_group WHERE subcategory_id = :id");
            $stmt1->bindParam(":id", $input["subcategory_id"]);
            $stmt1->execute();

            // ลบ subcategory
            $stmt2 = $dbh->prepare("DELETE FROM equipment_subcategories WHERE subcategory_id = :id");
            $stmt2->bindParam(":id", $input["subcategory_id"]);
            $stmt2->execute();

            $logModel->insertLog($u_id, 'equipment_subcategories', 'DELETE', $oldData, null);

            $dbh->commit();

            echo json_encode(["status" => "ok", "message" => "ลบข้อมูลเรียบร้อย"]);
            exit;

        } catch (Exception $e) {
            $dbh->rollBack();
            throw $e;
        }
    }

    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit;

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>