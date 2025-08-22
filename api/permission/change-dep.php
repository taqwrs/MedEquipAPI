<?php
include "../config/jwt.php";
$input = json_decode(file_get_contents('php://input'));

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array("status" => "error", "message" => "post method!!!"));
    die();
}
try {
   
    $user_id = $input->user_id ?? '';
    $department = $input->department ?? '';
    $role_id = $input->role_id ?? null;

    if (!$user_id || !$department || $role_id === null) {
        throw new Exception("Missing user_id, department, or role_id");
    }

    // 1. อัปเดต department และ role_id ของผู้ใช้
    $stmt = $dbh->prepare("UPDATE users SET department = ?, role_id = ? WHERE user_id = ?");
    $stmt->bindParam(1, $department);
    $stmt->bindParam(2, $role_id);
    $stmt->bindParam(3, $user_id);

    if (!$stmt->execute()) {
        throw new Exception("ไม่สามารถอัปเดตข้อมูลได้");
    }

    // 2. ดึงข้อมูลผู้ใช้พร้อมชื่อแผนก
    $query = "SELECT u.user_id, u.full_name, u.department AS department_name, u.role_id, r.role_name
              FROM users u
              LEFT JOIN ward w ON u.department = w.department
              LEFT JOIN role r ON u.role_id = r.role_id
              WHERE u.user_id = ?";

    $stmt = $dbh->prepare($query);
    $stmt->bindParam(1, $user_id);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        throw new Exception("ไม่พบข้อมูลผู้ใช้");
    }

    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "ok",
        "data" => $result
    ]);

    } catch (Exception $e) {
    //    $dbh->rollBack();

    echo json_encode(array("status" => "error", "message" => $e->getMessage()));
    }
