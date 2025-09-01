<?php
include "../config/jwt.php";
$input = json_decode(file_get_contents('php://input'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array("status" => "error", "message" => "post method!!!"));
    die();
}
try {
    $query = "SELECT 
        m.menu_id,
        m.menu_name,
        m.path_name,
        COALESCE(p.status, 0) AS status  -- 1 = มีสิทธิ์, 0 = ไม่มีสิทธิ์
    FROM menu m
    LEFT JOIN permission p ON m.menu_id = p.menu_id AND p.role_id = ?
    ORDER BY m.menu_id ASC";

    $stmt = $dbh->prepare($query);
    $stmt->execute([$role_id]);


    // แปลงผลลัพธ์ให้อยู่ในรูปแบบ array
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ส่งผลลัพธ์กลับในรูปแบบ JSON
    echo json_encode(["status" => "ok", "menus" => $results]);
} catch (Exception $e) {
    echo json_encode(array("status" => "error", "message" => $e->getMessage()));
}

// โค้ดนี้มีหน้าที่ในการ ดึงรายการเมนูทั้งหมดในระบบ พร้อมกับสถานะ status ที่แสดงว่า บทบาท (role) 
// ที่ระบุ (role_id) มีสิทธิ์เข้าถึงเมนูแต่ละรายการหรือไม่ (1 = มีสิทธิ์, 0 = ไม่มีสิทธิ์)