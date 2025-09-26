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

$equipment_id   = $_POST['equipment_id'] ?? null;
$user_id        = $_POST['user_id'] ?? null;
$performed_date = $_POST['performed_date'] ?? null;
$result         = $_POST['result'] ?? "ผ่าน";
$details        = $_POST['details'] ?? "";
$reason         = $_POST['reason'] ?? "";
$details_ma_id  = $_POST['details_ma_id'] ?? null;
$send_repair    = $_POST['send_repair'] ?? "false";

if (!$equipment_id || !$performed_date || !$user_id || !$details_ma_id) {
    echo json_encode(["result" => "error", "message" => "Missing required fields"]);
    exit;
}

try {

    // ตรวจสอบว่าเคยบันทึกผลรอบนี้ของเครื่องมือนี้หรือยัง
    $checkStmt = $dbh->prepare("
        SELECT COUNT(*) 
        FROM maintenance_result
        WHERE details_ma_id = :details_ma_id
          AND equipment_id = :equipment_id
    ");
    $checkStmt->execute([
        ":details_ma_id" => $details_ma_id,
        ":equipment_id" => $equipment_id
    ]);
    if ($checkStmt->fetchColumn() > 0) {
        echo json_encode(["status" => "error", "message" => "ผลการบำรุงรักษานี้ของเครื่องมือนี้ถูกบันทึกแล้ว ไม่สามารถบันทึกซ้ำได้"]);
        exit;
    }

    // บันทึกผล MA ลง DB
    $stmt = $dbh->prepare("
        INSERT INTO maintenance_result 
        (details_ma_id, user_id, equipment_id, performed_date, result, details, reason, send_repair)
        VALUES 
        (:details_ma_id, :user_id, :equipment_id, :performed_date, :result, :details, :reason, :send_repair)
    ");
    $stmt->execute([
        ":details_ma_id" => $details_ma_id,
        ":user_id" => $user_id,
        ":equipment_id" => $equipment_id,
        ":performed_date" => $performed_date,
        ":result" => $result,
        ":details" => ($details === "null" || !$details) ? null : $details,
        ":reason" => ($reason === "null" || !$reason) ? null : $reason,
        ":send_repair" => $send_repair
    ]);

    $ma_result_id = $dbh->lastInsertId();

    echo json_encode(["status" => "success", "message" => "Saved successfully"]);

} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
