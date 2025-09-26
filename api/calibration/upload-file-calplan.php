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

    $plan_id = $_POST['plan_id'] ?? null;
    if (!$plan_id) {
        throw new Exception("plan_id ไม่พบ");
    }

    $checkStmt = $dbh->prepare("SELECT plan_id FROM calibration_plans WHERE plan_id = ?");
    $checkStmt->execute([$plan_id]);
    if (!$checkStmt->fetch()) {
        throw new Exception("plan_id ไม่ถูกต้อง: $plan_id");
    }

    $uploadedFiles = [];
    $uploadDir = __DIR__ . "/../file-upload/file_cal/";
    
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true)) {
        throw new Exception("ไม่สามารถสร้าง directory: $uploadDir");
    }

    $files = $_FILES['file_cal'] ?? null;
    if (!$files) throw new Exception("ไม่พบไฟล์ที่อัปโหลด");

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
            $allowed = ['jpg','jpeg','png','gif','webp','pdf','docx','doc','txt'];
            if (!in_array($ext, $allowed)) throw new Exception("รูปแบบไฟล์ไม่ถูกต้อง: $name");

            $newName = uniqid('cal_', true) . '.' . $ext;
            $targetPath = $uploadDir . $newName;
            if (!move_uploaded_file($tmp, $targetPath)) throw new Exception("ไม่สามารถอัปโหลดไฟล์: $name");

            $url = "/file-upload/file_cal/$newName";
            $stmt = $dbh->prepare("INSERT INTO file_cal(file_cal_name, plan_id, file_cal_url, cal_type_name) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $plan_id, $url, null]);

            $uploadedFiles[] = [
                'name' => $name,
                'url' => $url,
                'type' => null
            ];
        } else {
            throw new Exception("ข้อผิดพลาดการอัปโหลด: " . $files['error'][$key]);
        }
    }

    $dbh->commit();
    echo json_encode([
        "status" => "success",
        "message" => "อัปโหลดไฟล์สำเร็จ",
        "files" => $uploadedFiles,
        "count" => count($uploadedFiles)
    ]);

} catch (Exception $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
