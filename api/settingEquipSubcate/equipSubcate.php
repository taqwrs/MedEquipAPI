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
        // CREATE equipment_subcategories + relation_group
        if (empty($input["category_id"]) || empty($input["name"]) || empty($input["type"])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก category_id, name, type"]);
            exit;
        }

        // เพิ่มข้อมูล subcategory
        $stmt = $dbh->prepare("
            INSERT INTO equipment_subcategories (category_id, name, type)
            VALUES (:category_id, :name, :type)
        ");
        $stmt->bindParam(":category_id", $input["category_id"]);
        $stmt->bindParam(":name", $input["name"]);
        $stmt->bindParam(":type", $input["type"]);
        $stmt->execute();

        $newId = $dbh->lastInsertId();

        // ผูก relation_group ถ้ามี group_user_id
        if (!empty($input["group_user_id"])) {
            $stmt2 = $dbh->prepare("
                INSERT INTO relation_group (group_user_id, subcategory_id)
                VALUES (:group_user_id, :subcategory_id)
            ");
            $stmt2->bindParam(":group_user_id", $input["group_user_id"]);
            $stmt2->bindParam(":subcategory_id", $newId);
            $stmt2->execute();
        }

        echo json_encode(["status" => "ok", "message" => "เพิ่มข้อมูลเรียบร้อย"]);

    } elseif ($method === "PUT") {
        // UPDATE equipment_subcategories + relation_group
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

        // อัปเดต relation_group ถ้ามี group_user_id
        if (!empty($input["group_user_id"])) {
            // เช็คว่ามีอยู่แล้วหรือยัง
            $stmtCheck = $dbh->prepare("
                SELECT relation_group_id FROM relation_group WHERE subcategory_id = :subcategory_id
            ");
            $stmtCheck->bindParam(":subcategory_id", $input["subcategory_id"]);
            $stmtCheck->execute();
            $exist = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($exist) {
                // update
                $stmt2 = $dbh->prepare("
                    UPDATE relation_group
                    SET group_user_id = :group_user_id
                    WHERE subcategory_id = :subcategory_id
                ");
            } else {
                // insert
                $stmt2 = $dbh->prepare("
                    INSERT INTO relation_group (group_user_id, subcategory_id)
                    VALUES (:group_user_id, :subcategory_id)
                ");
            }

            $stmt2->bindParam(":group_user_id", $input["group_user_id"]);
            $stmt2->bindParam(":subcategory_id", $input["subcategory_id"]);
            $stmt2->execute();
        }

        echo json_encode(["status" => "ok", "message" => "แก้ไขข้อมูลเรียบร้อย"]);

    } elseif ($method === "DELETE") {
        // DELETE ทั้ง relation_group และ equipment_subcategories
        if (empty($input["subcategory_id"])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก subcategory_id"]);
            exit;
        }

        // ลบ relation_group ก่อน
        $stmt1 = $dbh->prepare("DELETE FROM relation_group WHERE subcategory_id = :id");
        $stmt1->bindParam(":id", $input["subcategory_id"]);
        $stmt1->execute();

        // ลบ equipment_subcategories
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
