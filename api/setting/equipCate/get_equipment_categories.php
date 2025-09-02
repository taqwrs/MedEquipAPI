<?php
include "../config/jwt.php";

$input = json_decode(file_get_contents('php://input'));

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(array("status" => "error", "message" => "GET method required"));
    die();
}

try {
    // query ข้อมูล category_id และ name จากตาราง equipment_categories
    $query = "SELECT category_id, name FROM equipment_categories";
    $stmt = $dbh->prepare($query);
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ส่งผลลัพธ์กลับเป็น JSON
    echo json_encode([
        "status" => "ok",
        "data" => $result
    ]);

} catch (Exception $e) {
    // หากมีข้อผิดพลาด ส่ง error กลับ
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
