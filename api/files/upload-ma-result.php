<?php
include "../config/jwt.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

try {
    $dbh->beginTransaction();

    $ma_result_id = $_POST['ma_result_id'] ?? null; // ใช้ ma_result_id แทน
    if (!$ma_result_id) {
        throw new Exception("ma_result_id ไม่พบ");
    }

    $uploadedFiles = [];
    $uploadDir = __DIR__ . "/../file-upload/file_ma_result/";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $files = $_FILES['file_ma_result'] ?? null;

    if ($files && $files['name'][0] !== "") {
        // normalize array
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

                $newName = uniqid('MaResult_', true) . '.' . $ext;
                $targetFile = $uploadDir . $newName;
                if (!move_uploaded_file($tmp, $targetFile)) {
                    throw new Exception("Upload failed: $name");
                }

                $url = "/file-upload/file_ma_result/$newName";
                $typeName = $_POST['ma_result_type_name'][$key] ?? "ไม่ระบุ";

                $stmt = $dbh->prepare("
                    INSERT INTO file_ma_result(ma_result_id, file_ma_name, file_ma_url, ma_type_name, upload_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$ma_result_id, $name, $url, $typeName]);

                $uploadedFiles[] = [
                    "name" => $name,
                    "saved" => $newName,
                    "url" => $url,
                    "type" => $typeName
                ];
            }
        }
    }

    $dbh->commit();
    echo json_encode(["status" => "success", "files" => $uploadedFiles]);

} catch (Exception $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
