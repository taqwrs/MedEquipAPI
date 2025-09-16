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

    if ($stmt->execute()) {
        $writeoff_id = $dbh->lastInsertId();

        // Handle file uploads if provided
        if (!empty($_FILES['files'])) {
            $uploadDir = '../uploads/writeoffs/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

            foreach ($_FILES['files']['tmp_name'] as $index => $tmpName) {
                $fileName = basename($_FILES['files']['name'][$index]);
                $filePath = $uploadDir . uniqid() . "_" . $fileName;

                if (move_uploaded_file($tmpName, $filePath)) {
                    $type_name = pathinfo($fileName, PATHINFO_EXTENSION);

                    $fileStmt = $dbh->prepare("
                        INSERT INTO file_writeoffs (writeoff_id, File_name, url, type_name)
                        VALUES (:writeoff_id, :File_name, :url, :type_name)
                    ");
                    $fileStmt->bindParam(':writeoff_id', $writeoff_id);
                    $fileStmt->bindParam(':File_name', $fileName);
                    $fileStmt->bindParam(':url', $filePath);
                    $fileStmt->bindParam(':type_name', $type_name);
                    $fileStmt->execute();
                }
            }
        }

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
    } else {
        echo json_encode(["status" => "error", "message" => "ไม่สามารถบันทึกข้อมูลได้"]);
    }
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
