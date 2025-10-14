<?php
include "../config/jwt.php";
include "../config/LogModel.php"; 

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

try {
    $dbh->beginTransaction();
    $log = new LogModel($dbh); 
    $user_id = $decoded->data->ID ?? null; 

    $spare_part_id = $_POST['spare_part_id'] ?? null;
    if (!$spare_part_id)
        throw new Exception("spare_part_id ไม่พบ");

    $uploadedFiles = [];
    $uploadDir = __DIR__ . "/../file-upload/file_spare/";
    if (!is_dir($uploadDir)) {
        throw new Exception("ไม่พบโฟลเดอร์สำหรับอัปโหลดไฟล์: $uploadDir");
    }

    $files = $_FILES['file_spare'] ?? null;
    // ตรวจสอบว่ามีไฟล์อัปโหลดหรือไม่
    if ($files && $files['name'][0] !== "") {

        // ถ้าเป็น single file → แปลงเป็น array
        if (!is_array($files['name'])) {
            $files = [
                'name' => [$files['name']],
                'type' => [$files['type']],
                'tmp_name' => [$files['tmp_name']],
                'error' => [$files['error']],
                'size' => [$files['size']],
            ];
        }
    }
    foreach ($files['name'] as $key => $name) {
        if ($files['error'][$key] === UPLOAD_ERR_OK) {
            $tmp = $files['tmp_name'][$key];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'docx'];
            if (!in_array($ext, $allowed))
                throw new Exception("Invalid file format: $name");

            $newName = uniqid('spare_', true) . '.' . $ext;
            if (!move_uploaded_file($tmp, $uploadDir . $newName))
                throw new Exception("Upload failed: $name");

            $url = "/file-upload/file_spare/$newName";
            // $typeName = trim($_POST['spare_type_name'][$key] ?? "");
            // if ($typeName === "") {
            //     throw new Exception("spare_type_name ห้ามว่างสำหรับไฟล์: $name");
            // }
            $typeName = $_POST['spare_type_name'][$key] ?? "";
            $stmt = $dbh->prepare("INSERT INTO file_spare(file_spare_name, spare_part_id, spare_url, spare_type_name, upload_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$name, $spare_part_id, $url, $typeName]);

            // --- Log upload file ---
            $log->insertLog($user_id, 'file_spare', 'INSERT', null, [
                'file_spare_name' => $name,
                'spare_part_id' => $spare_part_id,
                'spare_url' => $url,
                'spare_type_name' => $typeName
            ], 'register_logs'); 

            $uploadedFiles[] = $url;
        }
    }

    $dbh->commit();
    echo json_encode(["status" => "success", "files" => $uploadedFiles]);
} catch (Exception $e) {
    $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}