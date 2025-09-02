<?php
// เรียก jwt.php แบบ absolute path เพื่อให้ path vendor ถูกต้อง
include_once __DIR__ . "/../../config/jwt.php";

// ตรวจสอบ Method GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode([
        "status" => "error",
        "message" => "GET method required"
    ]);
    die();
}

try {
    // Query สำหรับดึงข้อมูลหมวดหมู่เครื่องมือแพทย์
    $query = "SELECT category_id, name FROM equipment_categories ORDER BY name ASC";

    // เตรียมและ execute query
    $stmt = $dbh->prepare($query);
    $stmt->execute();

    // ตรวจสอบผลลัพธ์
    if ($stmt->rowCount() > 0) {
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode([
            "status" => "ok",
            "data" => $results
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            "status" => "error",
            "message" => "ไม่พบข้อมูล"
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
