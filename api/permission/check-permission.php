<?php
include "../config/jwt.php";
$input = json_decode(file_get_contents('php://input'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array("status" => "error", "message" => "post method!!!"));
    die();
}
try {
    // ดึง role_id จาก token
    $role_id = $decoded->data->role_id ?? null;

    if (!$role_id) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Missing role_id in token"]);
        exit;
    }

    // รับ menuPath จาก client
    $menuPath = $input->menuPath ?? null;
    if (!$menuPath) {
        echo json_encode(["status" => "error", "message" => "menuPath is required"]);
        exit;
    }

    // ตรวจสอบสิทธิ์จากตาราง permission
    $query = "
        SELECT permission.status 
        FROM menu 
        LEFT JOIN permission ON menu.menu_id = permission.menu_id 
        WHERE menu.path_name = ? AND permission.role_id = ? AND permission.status = 1
    ";

    $stmt = $dbh->prepare($query);
    $stmt->bindParam(1, $menuPath);
    $stmt->bindParam(2, $role_id);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        echo json_encode(["status" => "success", "message" => "Access granted"]);
    } else {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Access denied"]);
    }
} catch (Exception $e) {
    echo json_encode(array("status" => "error", "message" => $e->getMessage()));
}

// โค้ดนี้ทำหน้าที่ตรวจสอบสิทธิ์การเข้าถึงเมนูตาม path ที่ผู้ใช้ส่งมา (menuPath)
// โดยดึง role_id จากข้อมูลใน token JWT เพื่อใช้ตรวจสอบสิทธิ์
// จากนั้นจะเช็คในตาราง permission ว่าบทบาท (role) นี้มีสิทธิ์ (status = 1) เข้าถึงเมนูที่มี path_name ตรงกับ menuPath หรือไม่
// หากมีสิทธิ์ จะส่งผลลัพธ์กลับเป็น success พร้อมข้อความ "Access granted"
// หากไม่มีสิทธิ์ จะส่ง HTTP status 403 พร้อมข้อความ "Access denied"
