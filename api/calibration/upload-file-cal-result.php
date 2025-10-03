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

    $cal_result_id = $_POST['cal_result_id'] ?? null;
    if (!$cal_result_id) {
        throw new Exception("cal_result_id ไม่พบ - ได้รับ: " . var_export($_POST, true));
    }

    $checkStmt = $dbh->prepare("SELECT COUNT(*) FROM calibration_result WHERE cal_result_id = ?");
    $checkStmt->execute([$cal_result_id]);
    if ($checkStmt->fetchColumn() == 0) {
        throw new Exception("ไม่พบ cal_result_id = $cal_result_id ใน database");
    }

    $uploadedFiles = [];
    $uploadDir = __DIR__ . "/../file-upload/file_cal_result/";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $fileCount = 0; // เพิ่มตัวนับไฟล์
    $files = $_FILES['file_cal_result'] ?? null;
    if ($files && isset($files['name']) && !empty($files['name'])) {
        if (!is_array($files['name'])) {
            $files = [
                'name' => [$files['name']],
                'type' => [$files['type']],
                'tmp_name' => [$files['tmp_name']],
                'error' => [$files['error']],
                'size' => [$files['size']],
            ];
        }

        $calTypeNames = isset($_POST['cal_type_name']) && is_array($_POST['cal_type_name']) 
            ? $_POST['cal_type_name'] 
            : [];

        foreach ($files['name'] as $key => $name) {
            if (empty($name)) continue; 
            
            if ($files['error'][$key] === UPLOAD_ERR_OK) {
                $tmp = $files['tmp_name'][$key];
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','gif','webp','pdf','docx'];
                if (!in_array($ext, $allowed)) throw new Exception("Invalid file format: $name");

                $newName = uniqid('cal_result_', true) . '.' . $ext;
                $fullPath = $uploadDir . $newName;
                if (!move_uploaded_file($tmp, $fullPath)) throw new Exception("Upload failed: $name");

                $url = "/file-upload/file_cal_result/$newName";
                $calTypeName = isset($calTypeNames[$key]) ? $calTypeNames[$key] : "ไม่ระบุ";

                $stmt = $dbh->prepare("
                    INSERT INTO file_cal_result(cal_result_id, file_cal_name, file_cal_url, cal_type_name)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$cal_result_id, $name, $url, $calTypeName]);

                $uploadedFiles[] = [
                    'file_cal_result_id' => $dbh->lastInsertId(),
                    'file_cal_name' => $name,
                    'file_cal_url' => $url,
                    'cal_type_name' => $calTypeName
                ];
                
                $fileCount++; // นับไฟล์ที่อัปโหลดสำเร็จ
            }
        }
    }

    if (!empty($_POST['file_cal_url'])) {
        $urls = is_array($_POST['file_cal_url']) ? $_POST['file_cal_url'] : [$_POST['file_cal_url']];
        $calTypeNames = isset($_POST['cal_type_name']) && is_array($_POST['cal_type_name']) 
            ? $_POST['cal_type_name'] 
            : [];
        
        foreach ($urls as $index => $url) {
            if (!empty($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                $baseName = "ลิงก์ผลการสอบเทียบ";
                $customName = $baseName;
                $counter = 1;

                while (true) {
                    $stmt = $dbh->prepare("SELECT COUNT(*) FROM file_cal_result WHERE file_cal_name = ? AND cal_result_id = ?");
                    $stmt->execute([$customName, $cal_result_id]);
                    if ($stmt->fetchColumn() == 0) break;
                    $counter++;
                    $customName = $baseName . '-' . sprintf('%02d', $counter);
                }

                // แก้ไขตรงนี้: ใช้ $fileCount + $index แทน $index
                $typeIndex = $fileCount + $index;
                $calTypeName = isset($calTypeNames[$typeIndex]) ? $calTypeNames[$typeIndex] : "ลิงก์";

                $stmt = $dbh->prepare("
                    INSERT INTO file_cal_result(cal_result_id, file_cal_name, file_cal_url, cal_type_name)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$cal_result_id, $customName, $url, $calTypeName]);

                $uploadedFiles[] = [
                    'file_cal_result_id' => $dbh->lastInsertId(),
                    'file_cal_name' => $customName,
                    'file_cal_url' => $url,
                    'cal_type_name' => $calTypeName
                ];
            }
        }
    }

    $dbh->commit();
    echo json_encode([
        "status" => "success",
        "message" => count($uploadedFiles) > 0 ? "Files/URLs uploaded successfully" : "Result saved without files",
        "files" => $uploadedFiles,
        "count" => count($uploadedFiles)
    ]);

} catch (Exception $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>