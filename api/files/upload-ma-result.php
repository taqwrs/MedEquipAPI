<?php
include "../config/jwt.php";
// include "../config/LogModel.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

try {
    $dbh->beginTransaction();
    // $log = new LogModel($dbh);

    $plan_id = $_POST['plan_id'] ?? null;
    if (!$plan_id)
        throw new Exception("plan_id ไม่พบ");

    $uploadedFiles = [];
    $uploadDir = __DIR__ . "/../file-upload/file_ma_result/";
    $files = $_FILES['file_ma_result'] ?? null;

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

            $newName = uniqid('MaPlan_', true) . '.' . $ext;
            if (!move_uploaded_file($tmp, $uploadDir . $newName))
                throw new Exception("Upload failed: $name");

            $url = "/file-upload/file_ma_result/$newName";
            // $typeName = trim($_POST['equip_type_name'][$key] ?? "");
            // if ($typeName === "") {
            //     throw new Exception("equip_type_name ห้ามว่างสำหรับไฟล์: $name");
            // }
            $typeName = $_POST['equip_type_name'][$key] ?? "";
            $stmt = $dbh->prepare("INSERT INTO file_equip(file_equip_name, equipment_id, equip_url, equip_type_name, upload_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$name, $equipment_id, $url, $typeName]);

            $uploadedFiles[] = $url;

            // $log->insertLog($employee_code, 'file_equip', 'INSERT', null, ['equipment_id'=>$equipment_id,'file'=>$name]);
        }
    }

    $dbh->commit();
    echo json_encode(["status" => "success", "files" => $uploadedFiles]);
} catch (Exception $e) {
    $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>