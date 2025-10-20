<?php
include "../config/jwt.php";
include "../config/LogModel.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// ตรวจสอบ method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method required"]);
    exit;
}

// รับค่า
$ma_result_id   = $_POST['ma_result_id'] ?? null;
$user_id        = $_POST['user_id'] ?? null;
$performed_date = $_POST['performed_date'] ?? null;
$result         = $_POST['result'] ?? null;
$details        = $_POST['details'] ?? null;
$reason         = $_POST['reason'] ?? null;
$send_repair    = $_POST['send_repair'] ?? null;

if (!$ma_result_id) {
    echo json_encode(["status" => "error", "message" => "Missing ma_result_id"]);
    exit;
}

try {
    $dbh->beginTransaction();
    $user_id = $decoded->data->ID ?? null;
    if (!$user_id) throw new Exception("User ID not found");

    // ตรวจสอบว่ามีผล MA นี้อยู่หรือไม่
    $checkStmt = $dbh->prepare("
        SELECT * 
        FROM maintenance_result 
        WHERE ma_result_id = :ma_result_id
    ");
    $checkStmt->execute([":ma_result_id" => $ma_result_id]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        $dbh->rollBack();
        echo json_encode(["status" => "error", "message" => "ไม่พบผล MA นี้"]);
        exit;
    }

    // เตรียมข้อมูลใหม่
    $newData = [
        "user_id"        => $user_id        ?? $existing['user_id'],
        "performed_date" => $performed_date ?? $existing['performed_date'],
        "result"         => $result         ?? $existing['result'],
        "details"        => $details        ?? $existing['details'],
        "reason"         => $reason         ?? $existing['reason'],
        "send_repair"    => $send_repair    ?? $existing['send_repair']
    ];

    // หาเฉพาะ field ที่เปลี่ยนจริง
    $logModel = new LogModel($dbh);
    $changedData = $logModel->filterChangedFields($existing, $newData);

    if ($changedData) {
        // อัปเดตข้อมูล
        $stmt = $dbh->prepare("
            UPDATE maintenance_result
            SET user_id = :user_id,
                performed_date = :performed_date,
                result = :result,
                details = :details,
                reason = :reason,
                send_repair = :send_repair
            WHERE ma_result_id = :ma_result_id
        ");
        $stmt->execute(array_merge($newData, [":ma_result_id" => $ma_result_id]));

        // เพิ่ม log เฉพาะ field ที่เปลี่ยน
        $logModel->insertLog($user_id, 'maintenance_result', 'UPDATE', $existing, $changedData);
    }

    $dbh->commit();

    echo json_encode([
        "status" => "success",
        "message" => "Update MA result successfully",
        "changed_fields" => $changedData
    ]);

} catch (PDOException $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
