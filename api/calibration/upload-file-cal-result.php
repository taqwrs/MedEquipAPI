<?php
include "../config/jwt.php";
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

try {
    $dbh->beginTransaction();

    $calibration_id = $_POST['calibration_id'] ?? null;
    if (!$calibration_id) throw new Exception("calibration_id ไม่พบ");

    $uploadedFiles = [];
    $uploadDir = __DIR__ . "/../file-upload/file_cal_result/";
    
    
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $files = $_FILES['file_cal_result'] ?? null;

    if (!$files) {
        throw new Exception("No file uploaded");
    }


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
        if ($files['error'][$key] === UPLOAD_ERR_OK) {
            $tmp = $files['tmp_name'][$key];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $allowed = ['jpg','jpeg','png','gif','webp','pdf','docx'];
            
            if (!in_array($ext, $allowed)) {
                throw new Exception("Invalid file format: $name");
            }

            $newName = uniqid('cal_result_', true) . '.' . $ext;
            $fullPath = $uploadDir . $newName;
            
            if (!move_uploaded_file($tmp, $fullPath)) {
                throw new Exception("Upload failed: $name");
            }

            $url = "/file-upload/file_cal_result/$newName";
            $calTypeName = $_POST['cal_type_name'][$key] ?? "";


            $stmt = $dbh->prepare("INSERT INTO file_cal_result (calibration_id, file_cal_name, file_cal_url, cal_type_name) VALUES (?, ?, ?, ?)");
            $stmt->execute([$calibration_id, $name, $url, $calTypeName]);
            
            $insertedId = $dbh->lastInsertId();

            $uploadedFiles[] = [
                'file_cal_result_id' => $insertedId,
                'file_cal_name' => $name,
                'file_cal_url' => $url,
                'cal_type_name' => $calTypeName
            ];
        }
    }

    $dbh->commit();
    echo json_encode([
        "status" => "success", 
        "message" => "Files uploaded successfully",
        "files" => $uploadedFiles,
        "count" => count($uploadedFiles)
    ]);

} catch (Exception $e) {
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