<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

try {
    $dbh->beginTransaction();

    $writeoff_id = $_POST['writeoff_id'] ?? null;
    if (!$writeoff_id) throw new Exception("writeoff_id ไม่พบ");

    $files = $_FILES['file_writeoffs'] ?? null;
    if (!$files) throw new Exception("No file uploaded");

    $uploadedFiles = [];
    $uploadDir = __DIR__ . "/../file-upload/writeoffs/";


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

        $newName = uniqid('writeoff_', true) . '.' . $ext;
        $filePath = $uploadDir . $newName;

        if (!move_uploaded_file($tmp, $filePath)) {
            throw new Exception("Upload failed: $name");
        }

        $url = "/file-upload/writeoffs/$newName";
        $typeName = $_POST['writeoff_type_name'][$key] ?? "";

        $stmt = $dbh->prepare("INSERT INTO file_writeoffs(writeoff_id, File_name, url, type_name) VALUES (?, ?, ?, ?)");
        $stmt->execute([$writeoff_id, $name, $url, $typeName]);

        $uploadedFiles[] = $url;
    }

    $dbh->commit();
    echo json_encode(["status" => "success", "files" => $uploadedFiles]);

} catch (Exception $e) {
    $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
