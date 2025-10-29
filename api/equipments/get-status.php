<?php
include "../config/jwt.php";
$input = json_decode(file_get_contents('php://input'));
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(["status" => "error", "message" => "get method!!!"]);
    exit;
}

try {
    // ตรวจสอบ method
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
        exit;
    }

    // รับค่า type จาก query string เช่น ?type=equipment หรือ ?type=spare
    $type = isset($_GET['type']) ? $_GET['type'] : null;

    if (!$type) {
        echo json_encode(['status' => 'error', 'message' => 'Missing type parameter']);
        exit;
    }

    // สร้าง query ตามประเภทที่ส่งมา
    if ($type === 'equipment') {
        $sql = "SELECT DISTINCT status FROM equipments WHERE status IS NOT NULL AND status <> ''";
    } elseif ($type === 'spare') {
        $sql = "SELECT DISTINCT status FROM spare_parts WHERE status IS NOT NULL AND status <> ''";
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid type']);
        exit;
    }

    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // จัดรูปแบบข้อมูลให้เป็น array ของ object { value, label }
    $data = array_map(fn($s) => ['value' => $s, 'label' => $s], $result);

    echo json_encode([
        'status' => 'ok',
        'message' => 'Success',
        'data' => $data
    ]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
