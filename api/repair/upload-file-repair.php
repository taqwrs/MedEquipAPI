<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

try {
    $dbh->beginTransaction();

    $repair_result_id = $_POST['repair_result_id'] ?? null;
    if (!$repair_result_id) throw new Exception("repair_result_id ไม่พบ");

    $files = $_FILES['repair_files'] ?? null;
    if (!$files) throw new Exception("No file uploaded");

    $uploadedFiles = [];
    $uploadDir = __DIR__ . "/../file-upload/repair_results/";

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0777, true)) {
            throw new Exception("ไม่สามารถสร้างโฟลเดอร์ upload ได้");
        }
    }

    if (!is_writable($uploadDir)) {
        if (!chmod($uploadDir, 0777)) {
            throw new Exception("โฟลเดอร์ upload ไม่มีสิทธิ์เขียนไฟล์");
        }
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
        if ($files['error'][$key] !== UPLOAD_ERR_OK) {
            throw new Exception("Upload error: " . $name);
        }

        $tmp = $files['tmp_name'][$key];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp','pdf','docx'];

        if (!in_array($ext, $allowed)) {
            throw new Exception("Invalid file format: $name");
        }

        $newName = uniqid('repair_', true) . '.' . $ext;
        $filePath = $uploadDir . $newName;

        if (!move_uploaded_file($tmp, $filePath)) {
            throw new Exception("Upload failed: $name");
        }

        $url = "/file-upload/repair_results/$newName";
        $typeName = $_POST['repair_type_name'][$key] ?? "";

        $stmt = $dbh->prepare("
            INSERT INTO file_repair_result 
            (repair_result_id, repair_file_name, repair_file_url, repair_type_name) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$repair_result_id, $name, $url, $typeName]);

        $uploadedFiles[] = $url;
    }

    $dbh->commit();
    echo json_encode(["status" => "success", "files" => $uploadedFiles]);

} catch (Exception $e) {
    $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
