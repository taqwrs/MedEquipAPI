<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // ดึงข้อมูล role และ user ที่อยู่ใน role นั้น
        $stmt = $dbh->prepare("
            SELECT 
                r.role_id,
                r.role_name,
                u.ID AS u_id,
                u.user_id,
                u.full_name
            FROM roles r
            LEFT JOIN users u ON u.role_id = r.role_id
            ORDER BY r.role_id ASC, u.ID ASC
        ");
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // จัดกลุ่มผลลัพธ์ตาม role
        $roles = [];
        foreach ($rows as $row) {
            $roleId = $row['role_id'];
            if (!isset($roles[$roleId])) {
                $roles[$roleId] = [
                    "role_id" => $row['role_id'],
                    "role_name" => $row['role_name'],
                    "user_count" => 0,
                    "users" => []
                ];
            }
            if (!empty($row['u_id'])) {
                $roles[$roleId]["users"][] = [
                    "u_id" => $row['u_id'],
                    "user_id" => $row['user_id'],
                    "full_name" => $row['full_name']
                ];
                $roles[$roleId]["user_count"]++;
            }
        }

        echo json_encode(["status" => "ok", "data" => array_values($roles)], JSON_UNESCAPED_UNICODE);

    } else {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    }

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
