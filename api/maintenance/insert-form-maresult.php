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
$remarks        = $_POST['remarks'] ?? "";
$reason         = $_POST['reason'] ?? "";
$details_cal_id = $_POST['details_cal_id'] ?? null;
$send_repair    = $_POST['send_repair'] ?? "false";

if (!$equipment_id || !$performed_date || !$user_id || !$details_cal_id) {
    echo json_encode(["status" => "error", "message" => "Missing required fields"]);
    exit;
}

try {

    $checkStmt = $dbh->prepare("
        SELECT COUNT(*) 
        FROM calibration_result
        WHERE details_cal_id = :details_cal_id
          AND equipment_id = :equipment_id
    ");
    $checkStmt->execute([
        ":details_cal_id" => $details_cal_id,
        ":equipment_id" => $equipment_id
    ]);
    if ($checkStmt->fetchColumn() > 0) {
        echo json_encode(["status" => "error", "message" => "ผลการบำรุงรักษานี้ของเครื่องมือนี้ถูกบันทึกแล้ว ไม่สามารถบันทึกซ้ำได้"]);
        exit;
    }

    // บันทึกลง DB
    $stmt = $dbh->prepare("
    INSERT INTO calibration_result 
    (details_cal_id, user_id, equipment_id, performed_date, result, remarks, reason, send_repair)
    VALUES 
    (:details_cal_id, :user_id, :equipment_id, :performed_date, :result, :remarks, :reason, :send_repair)
");
$stmt->execute([
    ":details_cal_id" => $details_cal_id,
    ":user_id" => $user_id,
    ":equipment_id" => $equipment_id,
    ":performed_date" => $performed_date,
    ":result" => $result,
    ":remarks" => ($remarks === "null" || !$remarks) ? null : $remarks,
    ":reason" => ($reason === "null" || !$reason) ? null : $reason,
    ":send_repair" => $send_repair
]);

    $cal_result_id = $dbh->lastInsertId();

    // อัปโหลดไฟล์ถ้ามี
    if (!empty($_FILES['file'])) {
        $uploadDir = "../uploads/calibration/";
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        $fileName = basename($_FILES['file']['name']);
        $targetFile = $uploadDir . time() . "_" . $fileName;

        if (move_uploaded_file($_FILES['file']['tmp_name'], $targetFile)) {
            $stmtFile = $dbh->prepare("
                INSERT INTO file_cal_result (cal_result_id, file_cal_name, file_cal_url, cal_type_name)
                VALUES (:cal_result_id, :file_name, :file_url, :cal_type_name)
            ");
            $stmtFile->execute([
                ":cal_result_id" => $cal_result_id,
                ":file_name" => $fileName,
                ":file_url" => $targetFile,
                ":cal_type_name" => "ไฟล์บำรุงรักษา"
            ]);
        }
    }

    echo json_encode(["status" => "success", "message" => "Saved successfully"]);

} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
