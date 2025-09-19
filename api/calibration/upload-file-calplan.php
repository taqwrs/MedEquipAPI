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
    if (!$plan_id) throw new Exception("plan_id ไม่พบ");

    $uploadedFiles = [];
    $uploadDir = __DIR__ . "/../file-upload/file_cal/";
    $files = $_FILES['file_cal'] ?? null;

    if (!$files) {
        throw new Exception("No file uploaded");
    }

    // ให้รองรับหลายไฟล์
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
            if (!in_array($ext, $allowed)) throw new Exception("Invalid file format: $name");

            $newName = uniqid('cal_', true) . '.' . $ext;
            if (!move_uploaded_file($tmp, $uploadDir.$newName)) throw new Exception("Upload failed: $name");

            $url = "/file-upload/file_cal/$newName";
            $typeName = $_POST['cal_type_name'][$key] ?? "";

            $stmt = $dbh->prepare("INSERT INTO file_cal(file_cal_name, plan_id, file_cal_url, cal_type_name) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $plan_id, $url, $typeName]);

            $uploadedFiles[] = $url;
        }
    }

    $dbh->commit();
    echo json_encode(["status"=>"success","files"=>$uploadedFiles]);
} catch (Exception $e) {
    $dbh->rollBack();
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
?>
