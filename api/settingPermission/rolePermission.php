<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents("php://input"), true);

// รองรับ _method
if ($method === "POST" && isset($input["_method"])) {
    $method = strtoupper($input["_method"]);
}

try {
    // ================== GET ==================
    if ($method === "GET") {
        $stmt = $dbh->prepare("
            SELECT 
                r.role_id,
                r.role_name,
                p.permission_id,
                p.menu_id,
                p.status,
                m.menu_name,
                m.path_name
            FROM roles r
            LEFT JOIN permission p ON r.role_id = p.role_id
            LEFT JOIN menu m ON p.menu_id = m.menu_id
            ORDER BY r.role_id, m.menu_id
        ");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = [];
        foreach ($results as $row) {
            $roleId = $row['role_id'];
            if (!isset($data[$roleId])) {
                $data[$roleId] = [
                    "role_id" => $row['role_id'],
                    "role_name" => $row['role_name'],
                    "permissions" => []
                ];
            }

            if ($row['permission_id']) {
                $data[$roleId]["permissions"][] = [
                    "permission_id" => $row['permission_id'],
                    "menu_id" => $row['menu_id'],
                    "menu_name" => $row['menu_name'],
                    "path_name" => $row['path_name'],
                    "status" => $row['status'] == 1 ? "1 มีสิทธิ์" : "0 ไม่มีสิทธิ์"
                ];
            }
        }

        echo json_encode(["status" => "ok", "data" => array_values($data)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ================== POST ==================
    if ($method === "POST") {
        // ====== เพิ่ม role พร้อม auto permission ======
        if (!empty($input["role_name"])) {
            $dbh->beginTransaction();

            // เพิ่ม role
            $stmt = $dbh->prepare("INSERT INTO roles (role_name) VALUES (:role_name)");
            $stmt->bindParam(":role_name", $input["role_name"]);
            $stmt->execute();
            $role_id = $dbh->lastInsertId();

            // ดึงเมนูทั้งหมด
            $stmt = $dbh->prepare("SELECT menu_id FROM menu");
            $stmt->execute();
            $menus = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // เพิ่ม permission ทุกเมนู status=0
            $stmt = $dbh->prepare("INSERT INTO permission (role_id, menu_id, status) VALUES (:role_id, :menu_id, 0)");
            foreach ($menus as $menu) {
                $stmt->bindParam(":role_id", $role_id);
                $stmt->bindParam(":menu_id", $menu['menu_id']);
                $stmt->execute();
            }

            $dbh->commit();
            echo json_encode(["status" => "ok", "message" => "เพิ่ม role และ permission อัตโนมัติเรียบร้อย", "role_id" => $role_id]);
            exit;
        }

        // ====== เพิ่ม permission เดี่ยว ======
        if (!empty($input["role_id"]) && !empty($input["menu_id"]) && isset($input["status"])) {
            $stmt = $dbh->prepare("
                INSERT INTO permission (role_id, menu_id, status)
                VALUES (:role_id, :menu_id, :status)
            ");
            $stmt->bindParam(":role_id", $input["role_id"]);
            $stmt->bindParam(":menu_id", $input["menu_id"]);
            $stmt->bindParam(":status", $input["status"]);
            $stmt->execute();

            echo json_encode(["status" => "ok", "message" => "เพิ่ม permission เรียบร้อย"]);
            exit;
        }

        echo json_encode(["status" => "error", "message" => "กรอกข้อมูลไม่ครบ"]);
        exit;
    }

    // ================== PUT ==================
    if ($method === "PUT") {
        // ====== แก้ไข role ======
        if (!empty($input["role_id"]) && !empty($input["role_name"])) {
            $stmt = $dbh->prepare("UPDATE roles SET role_name = :role_name WHERE role_id = :role_id");
            $stmt->bindParam(":role_name", $input["role_name"]);
            $stmt->bindParam(":role_id", $input["role_id"]);
            $stmt->execute();

            echo json_encode(["status" => "ok", "message" => "แก้ไข role เรียบร้อย"]);
            exit;
        }

        // ====== แก้ไข permission ======
        if (!empty($input["permission_id"]) && isset($input["status"])) {
            $stmt = $dbh->prepare("UPDATE permission SET status = :status WHERE permission_id = :permission_id");
            $stmt->bindParam(":status", $input["status"]);
            $stmt->bindParam(":permission_id", $input["permission_id"]);
            $stmt->execute();

            echo json_encode(["status" => "ok", "message" => "แก้ไข permission เรียบร้อย"]);
            exit;
        }

        echo json_encode(["status" => "error", "message" => "กรอกข้อมูลไม่ครบสำหรับ PUT"]);
        exit;
    }

    // ================== DELETE ==================
    if ($method === "DELETE") {
        // ====== ลบ role และ permission ทั้งหมด ======
        if (!empty($input["role_id"])) {
            $dbh->beginTransaction();

            // ลบ permission ของ role
            $stmt = $dbh->prepare("DELETE FROM permission WHERE role_id = :role_id");
            $stmt->bindParam(":role_id", $input["role_id"]);
            $stmt->execute();

            // ลบ role
            $stmt = $dbh->prepare("DELETE FROM roles WHERE role_id = :role_id");
            $stmt->bindParam(":role_id", $input["role_id"]);
            $stmt->execute();

            $dbh->commit();
            echo json_encode(["status" => "ok", "message" => "ลบ role และ permission เรียบร้อย"]);
            exit;
        }

        // ====== ลบ permission เดี่ยว ======
        if (!empty($input["permission_id"])) {
            $stmt = $dbh->prepare("DELETE FROM permission WHERE permission_id = :permission_id");
            $stmt->bindParam(":permission_id", $input["permission_id"]);
            $stmt->execute();

            echo json_encode(["status" => "ok", "message" => "ลบ permission เรียบร้อย"]);
            exit;
        }

        echo json_encode(["status" => "error", "message" => "กรอกข้อมูลไม่ครบสำหรับ DELETE"]);
        exit;
    }

    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit;

} catch (Exception $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
