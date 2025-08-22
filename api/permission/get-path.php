<?php
include "../config/jwt.php";
$input = json_decode(file_get_contents('php://input'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array("status" => "error", "message" => "post method!!!"));
    die();
}
try {
    // คำสั่ง SQL: ดึงข้อมูลเมนู พร้อมเมนูย่อย (sub_menu) ในรูปแบบ JSON
    $query = "SELECT 
                m.menu_id,
                m.menu_name,
                m.path_name,
                (SELECT JSON_ARRAYAGG(
                    JSON_OBJECT(
                        'name', sm.submenu_name,
                        'path_name', sm.path_name
                    )) 
                    FROM sub_menu sm 
                    WHERE sm.menu_id = m.menu_id) as sub_menu
            FROM menu m
            LEFT JOIN permission ON m.menu_id = permission.menu_id
            WHERE permission.role_id = ? ;";

    // เตรียม statement ด้วย PDO เพื่อป้องกัน SQL injection
    $stmt = $dbh->prepare($query);

    // ดำเนินการ execute SQL query โดยส่ง role_id เป็นพารามิเตอร์
    $stmt->execute([$role_id]);

    // ดึงผลลัพธ์ทั้งหมดในรูปแบบ associative array
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ส่งข้อมูลกลับให้ client เป็น JSON โดยมี status และข้อมูลเมนู
    echo json_encode(["status" => "ok", "menus" => $results]);
} catch (Exception $e) {
    echo json_encode(array("status" => "error", "message" => $e->getMessage()));
}

