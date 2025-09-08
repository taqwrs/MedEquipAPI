<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents("php://input"), true);

// รองรับ _method จาก POST
if ($method === 'POST' && isset($input['_method'])) {
    $method = strtoupper($input['_method']);
}

try {
    if ($method === 'GET') {
        // READ - JOIN กับ roles และ departments
        $stmt = $dbh->prepare("
            SELECT 
                u.ID,
                u.user_id,
                u.full_name,
                u.department_id,
                d.department_name,
                u.role_id,
                r.role_name,
                u.first_login,
                u.last_login
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.role_id
            LEFT JOIN departments d ON u.department_id = d.department_id
            ORDER BY u.ID ASC
        ");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["status" => "ok", "data" => $results], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'POST') {
        // CREATE
        $required = ['user_id', 'full_name', 'department_id', 'role_id', 'first_login', 'last_login'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                echo json_encode(["status" => "error", "message" => "กรุณากรอก $field"]);
                exit;
            }
        }

        $stmt = $dbh->prepare("
            INSERT INTO users 
                (user_id, full_name, department_id, role_id, first_login, last_login) 
            VALUES 
                (:user_id, :full_name, :department_id, :role_id, :first_login, :last_login)
        ");
        $stmt->bindParam(":user_id", $input['user_id']);
        $stmt->bindParam(":full_name", $input['full_name']);
        $stmt->bindParam(":department_id", $input['department_id']);
        $stmt->bindParam(":role_id", $input['role_id']);
        $stmt->bindParam(":first_login", $input['first_login']);
        $stmt->bindParam(":last_login", $input['last_login']);
        $stmt->execute();

        echo json_encode(["status" => "ok", "message" => "เพิ่มผู้ใช้เรียบร้อย"]);

    } elseif ($method === 'PUT') {
        // UPDATE
        if (empty($input['ID'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก ID"]);
            exit;
        }

        $stmt = $dbh->prepare("
            UPDATE users SET 
                user_id = :user_id, 
                full_name = :full_name, 
                department_id = :department_id, 
                role_id = :role_id, 
                first_login = :first_login, 
                last_login = :last_login
            WHERE ID = :ID
        ");
        $stmt->bindParam(":user_id", $input['user_id']);
        $stmt->bindParam(":full_name", $input['full_name']);
        $stmt->bindParam(":department_id", $input['department_id']);
        $stmt->bindParam(":role_id", $input['role_id']);
        $stmt->bindParam(":first_login", $input['first_login']);
        $stmt->bindParam(":last_login", $input['last_login']);
        $stmt->bindParam(":ID", $input['ID']);
        $stmt->execute();

        echo json_encode(["status" => "ok", "message" => "แก้ไขผู้ใช้เรียบร้อย"]);

    } elseif ($method === 'DELETE') {
        // DELETE
        if (empty($input['ID'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก ID"]);
            exit;
        }

        $stmt = $dbh->prepare("DELETE FROM users WHERE ID = :ID");
        $stmt->bindParam(":ID", $input['ID']);
        $stmt->execute();

        echo json_encode(["status" => "ok", "message" => "ลบผู้ใช้เรียบร้อย"]);

    } else {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    }

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
