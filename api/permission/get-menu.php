<?php
include "../config/jwt.php";
$input = json_decode(file_get_contents('php://input'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array("status" => "error", "message" => "post method!!!"));
    die();
}
try {
    // ดึง role_id จาก token (มาจาก jwt.php)
    $role_id = $decoded->data->role_id ?? null;

    if (!$role_id) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Missing role_id"]);
        exit;
    }

    // ดึงเมนูที่ role_id นี้มีสิทธิ์ (status = 1)
    $query = "
        SELECT 
            m.menu_id,
            m.menu_name,
            m.path_name,
            p.status
        FROM menu m
        INNER JOIN permission p ON m.menu_id = p.menu_id
        WHERE p.role_id = ? AND p.status = 1
        ORDER BY m.menu_id ASC
    ";

    $stmt = $dbh->prepare($query);
    $stmt->execute([$role_id]);

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "ok",
        "menus" => $results
    ]);
} catch (Exception $e) {
    echo json_encode(array("status" => "error", "message" => $e->getMessage()));
}

// โค้ดนี้ทำหน้าที่ดึงรายการเมนูทั้งหมดที่บทบาท (role) ที่ระบุมีสิทธิ์เข้าถึงเท่านั้น
// โดยตรวจสอบจากตาราง permission ว่าบทบาทนั้นมีสถานะการอนุญาต (status) เป็น 1 หรือไม่
// แล้วส่งผลลัพธ์กลับในรูปแบบ JSON โดยจะแสดงข้อมูลเมนูที่มีสิทธิ์ เช่น menu_id, menu_name, path_name และ status
