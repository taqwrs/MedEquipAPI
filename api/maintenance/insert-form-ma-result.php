<?php
include "../config/jwt.php";
include "../config/LogModel.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method required"]);
    exit;
}

$input = $_POST; // ใช้ $_POST เป็น array
$required_fields = ['equipment_id','performed_date','details_ma_id'];
foreach ($required_fields as $field) {
    if (empty($input[$field])) {
        echo json_encode(["status"=>"error","message"=>"Missing required field: $field"]);
        exit;
    }
}

try {
    $dbh->beginTransaction();
    $log = new LogModel($dbh);
    $user_id = $decoded->data->ID ?? null;
    if (!$user_id) throw new Exception("User ID not found");

    // ตรวจสอบว่าเคยบันทึกผลรอบนี้ของเครื่องมือนี้หรือยัง
    $checkStmt = $dbh->prepare("
        SELECT COUNT(*) 
        FROM maintenance_result
        WHERE details_ma_id = :details_ma_id
          AND equipment_id = :equipment_id
    ");
    $checkStmt->execute([
        ":details_ma_id"=>$input['details_ma_id'],
        ":equipment_id"=>$input['equipment_id']
    ]);
    if ($checkStmt->fetchColumn() > 0) {
        $dbh->rollBack();
        echo json_encode(["status"=>"error","message"=>"ผลการบำรุงรักษานี้ของเครื่องมือนี้ถูกบันทึกแล้ว"]);
        exit;
    }

    // --- auto-generate insert ---
    $insertFields = [
        'details_ma_id', 'user_id', 'equipment_id', 'performed_date',
        'result', 'details', 'reason', 'send_repair'
    ];

    $insertData = [];
    foreach ($insertFields as $f) {
        $value = $input[$f] ?? null;

        // แปลง empty string หรือ "null" เป็น null
        if (in_array($f,['details','reason']) && ($value === '' || $value === 'null')) {
            $value = null;
        }

        // ค่า default
        if ($f === 'result' && !$value) $value = 'ผ่าน';
        if ($f === 'send_repair' && !$value) $value = 'false';

        $insertData[$f] = $value;
    }

    $cols = implode(',', $insertFields);
    $placeholders = ':' . implode(',:', $insertFields);

    // Force user from JWT
    $insertData['user_id'] = $user_id;
    $stmt = $dbh->prepare("INSERT INTO maintenance_result ($cols) VALUES ($placeholders)");
    $stmt->execute($insertData);

    $ma_result_id = $dbh->lastInsertId();

    // --- log auto-generate ---
    $logData = $insertData;
    $logData['ma_result_id'] = $ma_result_id;

    $log->insertLog($user_id,'maintenance_result','INSERT',null,$logData);

    $dbh->commit();
    echo json_encode([
        "status"=>"success",
        "message"=>"Saved successfully",
        "ma_result_id"=>$ma_result_id
    ]);

} catch (Exception $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
