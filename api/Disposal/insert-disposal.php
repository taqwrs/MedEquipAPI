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

// Decode JSON data
$data = json_decode(file_get_contents("php://input"), true);

// Validate required fields
$required = ['equipment_id', 'asset_number', 'WriteoffTypes', 'user_id'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        echo json_encode([
            "status" => "error",
            "message" => "กรุณากรอก $field"
        ]);
        exit;
    }
}

try {
    $dbh->beginTransaction(); // เริ่ม Transaction

    $equipment_id = $data['equipment_id'];
    $asset_number = $data['asset_number'];
    $writeoff_types_id = $data['WriteoffTypes'];
    $cause = isset($data['details']) ? $data['details'] : "";
    $user_id = $data['user_id'];
    $writeoff_date = date('Y-m-d');
    $status = 'รออนุมัติ';

    // Insert write-off
    $stmt = $dbh->prepare("
        INSERT INTO write_offs 
        (equipment_id, user_id, cause, writeoff_types_id, asset_number, writeoff_date, status) 
        VALUES (:equipment_id, :user_id, :cause, :writeoff_types_id, :asset_number, :writeoff_date, :status)
    ");

    $stmt->bindParam(':equipment_id', $equipment_id);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->bindParam(':cause', $cause);
    $stmt->bindParam(':writeoff_types_id', $writeoff_types_id);
    $stmt->bindParam(':asset_number', $asset_number);
    $stmt->bindParam(':writeoff_date', $writeoff_date);
    $stmt->bindParam(':status', $status);

    if (!$stmt->execute()) {
        throw new Exception("ไม่สามารถบันทึกข้อมูล write_offs ได้");
    }

    $writeoff_id = $dbh->lastInsertId();

    // หากต้องการบันทึก table อื่น สามารถเพิ่มตรงนี้ได้
    // ตัวอย่าง:
    // $stmt2 = $dbh->prepare("INSERT INTO table2 (writeoff_id, ...) VALUES (?, ...)");
    // $stmt2->execute([$writeoff_id, ...]);

    $dbh->commit(); // Commit Transaction

    echo json_encode([
        "status" => "success",
        "message" => "บันทึกข้อมูลสำเร็จ",
        "data" => [
            "writeoff_id" => $writeoff_id,
            "equipment_id" => $equipment_id,
            "user_id" => $user_id,
            "asset_number" => $asset_number,
            "WriteoffTypes" => $writeoff_types_id,
            "details" => $cause,
            "status" => $status,
            "writeoff_date" => $writeoff_date,
        ]
    ]);

} catch (Exception $e) {
    $dbh->rollBack(); // Rollback กรณีเกิดข้อผิดพลาด
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
