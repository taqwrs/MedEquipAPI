<?php
include "../config/jwt.php";
$input = json_decode(file_get_contents('php://input'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "post method!!!"]);
    exit;
}

try {
    // Query จำนวนทั้งหมด
    $stmtTotal = $dbh->prepare("SELECT COUNT(*) as total FROM equipments");
    $stmtTotal->execute();
    $total = $stmtTotal->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

    // Query จำนวนที่ลงทะเบียนเสร็จสิ้น
    $stmtcomplete = $dbh->prepare("SELECT COUNT(*) as complete FROM equipments WHERE record_status = 'complete'");
    $stmtcomplete->execute();
    $complete = $stmtcomplete->fetch(PDO::FETCH_ASSOC)['complete'] ?? 0;

    // Query จำนวนรอดำเนินการ
    $stmtdraft = $dbh->prepare("SELECT COUNT(*) as draft FROM equipments WHERE record_status = 'draft'");
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
