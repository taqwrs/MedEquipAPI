<?php
include "../config/jwt.php";
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

// Debug: แสดงข้อมูลที่ได้รับ
error_log("POST data: " . print_r($_POST, true));
error_log("FILES data: " . print_r($_FILES, true));

try {
    $dbh->beginTransaction();

    $cal_result_id = $_POST['cal_result_id'] ?? null;
    if (!$cal_result_id) {
        throw new Exception("cal_result_id ไม่พบ - ได้รับ: " . var_export($_POST, true));
    }

    // ตรวจสอบว่า cal_result_id มีอยู่จริงใน database หรือไม่
    $checkStmt = $dbh->prepare("SELECT COUNT(*) FROM calibration_result WHERE cal_result_id = ?");
    $checkStmt->execute([$cal_result_id]);
    if ($checkStmt->fetchColumn() == 0) {
        throw new Exception("ไม่พบ cal_result_id = $cal_result_id ใน database");
    }

    $uploadedFiles = [];
    $uploadDir = __DIR__ . "/../file-upload/file_cal_result/";
    
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0777, true)) {
            throw new Exception("ไม่สามารถสร้างโฟลเดอร์ได้: $uploadDir");
        }
    }
    
    $files = $_FILES['file_cal_result'] ?? null;

    if (!$files) {
        throw new Exception("ไม่พบไฟล์ที่อัปโหลด - FILES: " . var_export($_FILES, true));
    }

    // Debug: แสดงข้อมูลไฟล์
    error_log("Files structure: " . print_r($files, true));

    if (!is_array($files['name'])) {
        $files = [
            'name' => [$files['name']],
            'type' => [$files['type']],
            'tmp_name' => [$files['tmp_name']],
            'error' => [$files['error']],
            'size' => [$files['size']],
        ];
    }

    foreach ($files['name'] as $key => $name) {
        error_log("Processing file $key: $name, error: {$files['error'][$key]}");
        
        if ($files['error'][$key] === UPLOAD_ERR_OK) {
            $tmp = $files['tmp_name'][$key];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp','pdf','docx'];
            
            if (!in_array($ext, $allowed)) {
                throw new Exception("Invalid file format: $name (allowed: " . implode(',', $allowed) . ")");
            }

            $newName = uniqid('cal_result_', true) . '.' . $ext;
            $fullPath = $uploadDir . $newName;
            
            error_log("Moving file from $tmp to $fullPath");
            
            if (!move_uploaded_file($tmp, $fullPath)) {
                throw new Exception("Upload failed: $name (from $tmp to $fullPath)");
            }

            $url = "/file-upload/file_cal_result/$newName";
            $calTypeName = $_POST['cal_type_name'][$key] ?? "";

            error_log("Inserting to DB: cal_result_id=$cal_result_id, name=$name, url=$url, type=$calTypeName");

            $stmt = $dbh->prepare("INSERT INTO file_cal_result (cal_result_id, file_cal_name, file_cal_url, cal_type_name) VALUES (?, ?, ?, ?)");
            $result = $stmt->execute([$cal_result_id, $name, $url, $calTypeName]);
            
            if (!$result) {
                $errorInfo = $stmt->errorInfo();
                throw new Exception("Database insert failed: " . implode(', ', $errorInfo));
            }
            
            $insertedId = $dbh->lastInsertId();
            error_log("Inserted with ID: $insertedId");

            $uploadedFiles[] = [
                'file_cal_result_id' => $insertedId,
                'file_cal_name' => $name,
                'file_cal_url' => $url,
                'cal_type_name' => $calTypeName
            ];
        } else {
            error_log("File upload error for $name: " . $files['error'][$key]);
        }
    }

    if (empty($uploadedFiles)) {
        throw new Exception("ไม่มีไฟล์ใดที่อัปโหลดสำเร็จ");
    }

    $dbh->commit();
    error_log("Transaction committed successfully");
    
    echo json_encode([
        "status" => "success", 
        "message" => "Files uploaded successfully",
        "files" => $uploadedFiles,
        "count" => count($uploadedFiles)
    ]);

} catch (Exception $e) {
    error_log("Exception caught: " . $e->getMessage());
    $dbh->rollBack();
    
    foreach ($uploadedFiles as $file) {
        $filePath = __DIR__ . "/../file-upload/file_cal_result/" . basename($file['file_cal_url']);
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }
    
    echo json_encode([
        "status" => "error", 
        "message" => $e->getMessage()
    ]);
}
?>