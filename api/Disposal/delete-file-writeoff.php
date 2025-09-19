<?php
include "../config/jwt.php"; 
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method required"]);
    exit;
}

// Decode JSON
$data = json_decode(file_get_contents("php://input"), true);
$writeoff_id = $data['writeoff_id'] ?? null;

if (!$writeoff_id) {
    echo json_encode(["status" => "error", "message" => "writeoff_id ไม่พบ"]);
    exit;
}

try {
    $dbh->beginTransaction();

    // 1️⃣ ลบไฟล์จาก table file_writeoffs
    $stmtFiles = $dbh->prepare("SELECT url FROM file_writeoffs WHERE writeoff_id = ?");
    $stmtFiles->execute([$writeoff_id]);
    $files = $stmtFiles->fetchAll(PDO::FETCH_ASSOC);

    foreach ($files as $f) {
        $filePath = __DIR__ . "/.." . $f['url']; // adjust path if needed
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    $dbh->prepare("DELETE FROM file_writeoffs WHERE writeoff_id = ?")->execute([$writeoff_id]);

    // 2️⃣ ลบ record write_off
    $dbh->prepare("DELETE FROM write_offs WHERE writeoff_id = ?")->execute([$writeoff_id]);

    $dbh->commit();
    echo json_encode(["status" => "success", "message" => "ลบข้อมูลสำเร็จ"]);
} catch (Exception $e) {
    $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
