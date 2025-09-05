<?php
include "../config/jwt.php"; 

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents("php://input"), true);

if ($method === "POST" && isset($input["_method"])) {
    $method = strtoupper($input["_method"]);
}

try {
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

    } elseif ($method === "POST") {
        if (empty($input["category_id"]) || empty($input["name"]) || empty($input["type"])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก category_id, name, type"]);
            exit;
        }

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

        echo json_encode(["status" => "ok", "message" => "เพิ่มข้อมูลเรียบร้อย"]);

    } elseif ($method === "PUT") {
        if (empty($input["subcategory_id"]) || empty($input["name"]) || empty($input["type"])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก subcategory_id, name, type"]);
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

        echo json_encode(["status" => "ok", "message" => "แก้ไขข้อมูลเรียบร้อย"]);

    } elseif ($method === "DELETE") {
        if (empty($input["subcategory_id"])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก subcategory_id"]);
            exit;
        }

        // ลบ relation_group ก่อน
        $stmt1 = $dbh->prepare("DELETE FROM relation_group WHERE subcategory_id = :id");
        $stmt1->bindParam(":id", $input["subcategory_id"]);
        $stmt1->execute();

        // ลบ subcategory
        $stmt2 = $dbh->prepare("DELETE FROM equipment_subcategories WHERE subcategory_id = :id");
        $stmt2->bindParam(":id", $input["subcategory_id"]);
        $stmt2->execute();

        echo json_encode(["status" => "ok", "message" => "ลบข้อมูลเรียบร้อย"]);

    } else {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    }

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
