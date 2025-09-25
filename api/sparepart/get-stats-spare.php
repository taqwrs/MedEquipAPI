<?php
include "../config/jwt.php";

$input = json_decode(file_get_contents('php://input'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only!"]);
    exit;
}

try {
    // Query จำนวนทั้งหมด
    $stmtTotal = $dbh->prepare("SELECT COUNT(*) as total FROM spare_parts WHERE active = 1");
    $stmtTotal->execute();
    $total = $stmtTotal->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // Query จำนวนที่ลงทะเบียนเสร็จสิ้น
    $stmtcomplete = $dbh->prepare("SELECT COUNT(*) as complete FROM spare_parts WHERE record_status = 'complete' AND active = 1");
    $stmtcomplete->execute();
    $complete = $stmtcomplete->fetch(PDO::FETCH_ASSOC)['complete'] ?? 0;

    // Query จำนวนรอดำเนินการ
    $stmtdraft = $dbh->prepare("SELECT COUNT(*) as draft FROM spare_parts WHERE record_status = 'draft' AND active = 1");
    $stmtdraft->execute();
    $draft = $stmtdraft->fetch(PDO::FETCH_ASSOC)['draft'] ?? 0;

    echo json_encode([
        'status' => 'success',
        'stats' => [
            'total' => (int)$total,
            'complete' => (int)$complete,
            'draft' => (int)$draft
        ]
    ]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
