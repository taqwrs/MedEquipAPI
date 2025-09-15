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

$data = json_decode(file_get_contents("php://input"), true);


$required = ['equipment_id', 'asset_number', 'WriteoffTypes'];
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

    $equipment_id = $data['equipment_id'];
    $asset_number = $data['asset_number'];
    $writeoff_types_id = $data['WriteoffTypes'];
    $cause = isset($data['details']) ? $data['details'] : "";
    $user_id = 1;
    $writeoff_date = date('Y-m-d');
    $status = 'รออนุมัติ';

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

    if ($stmt->execute()) {
        $writeoff_id = $dbh->lastInsertId();
        echo json_encode([
            "status" => "success",
            "message" => "บันทึกข้อมูลสำเร็จ",
            "data" => [
                "writeoff_id" => $writeoff_id,
                "equipment_id" => $equipment_id,
                "asset_number" => $asset_number,
                "WriteoffTypes" => $writeoff_types_id,
                "details" => $cause,
                "status" => $status,
                "writeoff_date" => $writeoff_date,
            ]
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "ไม่สามารถบันทึกข้อมูลได้"]);
    }
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
