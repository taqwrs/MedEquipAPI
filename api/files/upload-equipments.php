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
    if (!$user_id)
        throw new Exception("User ID not found");

    $equipment_id = $_POST['equipment_id'] ?? null;
    if (!$equipment_id)
        throw new Exception("equipment_id ไม่พบ");

    $uploadedFiles = [];
    $uploadDir = __DIR__ . "/../file-upload/file_equip/";
    // if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    if (!is_dir($uploadDir)) {
        throw new Exception("ไม่พบโฟลเดอร์สำหรับอัปโหลดไฟล์: $uploadDir");
    }
    $files = $_FILES['file_equip'] ?? null;

    // ไม่มีไฟล์ → ส่งกลับสำเร็จ
    if (!$files || $files['name'][0] === "") {
        echo json_encode(["status" => "success", "files" => []], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // normalize single file → array
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
        try {
            if ($files['error'][$key] !== UPLOAD_ERR_OK)
                continue;

            $tmp = $files['tmp_name'][$key];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'docx'];
            if (!in_array($ext, $allowed))
                continue;

            $newName = uniqid('equip_', true) . '.' . $ext;
            if (!move_uploaded_file($tmp, $uploadDir . $newName))
                continue;

            $url = "/file-upload/file_equip/$newName";
            $typeName = $_POST['equip_type_name'][$key] ?? "";

            // บันทึกลง DB
            $stmt = $dbh->prepare("
                INSERT INTO file_equip(file_equip_name, equipment_id, equip_url, equip_type_name, upload_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$name, $equipment_id, $url, $typeName]);

            // บันทึก log
            $log->insertLog($user_id, 'file_equip', 'INSERT', null, [
                'equipment_id' => $equipment_id,
                'file_name' => $name,
                'file_url' => $url,
                'type' => $typeName
            ]);

            $uploadedFiles[] = [
                "name" => $name,
                "saved" => $newName,
                "url" => $url,
                "type" => $typeName
            ];
        } catch (Exception $e) {
            // ถ้าไฟล์นี้ล้มเหลว → ข้ามไปไฟล์ต่อไป
            continue;
        }
    }

    $dbh->commit();
    echo json_encode(["status" => "success", "files" => $uploadedFiles], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($dbh->inTransaction())
        $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
