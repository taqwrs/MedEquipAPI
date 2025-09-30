<?php
include "../config/jwt.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

try {
    $dbh->beginTransaction();

    $plan_id = $_POST['plan_id'] ?? null;
    if (!$plan_id) throw new Exception("plan_id ไม่พบ");

    $uploadedFiles = [];
    $uploadDir = __DIR__ . "/../file-upload/file_ma/";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $files = $_FILES['file_ma'] ?? null;

    if ($files && $files['name'][0] !== "") {
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
                if (!in_array($ext, $allowed))
                    throw new Exception("Invalid file format: $name");

                $newName = uniqid('ma_', true) . '.' . $ext;
                $targetFile = $uploadDir . $newName;
                if (!move_uploaded_file($tmp, $targetFile))
                    throw new Exception("Upload failed: $name");

                $url = "/file-upload/file_ma/$newName";
                $typeName = $_POST['ma_type_name'][$key] ?? "ไม่ระบุ";

                // บันทึกลงตาราง file_ma เชื่อมกับ plan_id
                $stmt = $dbh->prepare("
                    INSERT INTO file_ma(plan_id, file_ma_name, file_ma_url, ma_type_name, upload_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$plan_id, $name, $url, $typeName]);

                $uploadedFiles[] = $url;
            }
        }
    }
/* ------------------ กรณี URL ------------------ */
if (!empty($_POST['ma_result_url'])) {
    $urls = is_array($_POST['ma_result_url']) ? $_POST['ma_result_url'] : [$_POST['ma_result_url']];

    // ดึงข้อมูลอุปกรณ์จาก ma_result_id
    $stmtEquip = $dbh->prepare("
    SELECT e.asset_code, e.name AS equipment_name
    FROM maintenance_plans mp
    JOIN equipments e ON mp.equipment_id = e.equipment_id
    WHERE mp.plan_id = ?
    LIMIT 1
");
    $stmtEquip->execute([$plan_id]);
    $equipmentRow  = $stmtEquip->fetch(PDO::FETCH_ASSOC);
    $equipmentName = $equipmentRow['equipment_name'] ?? null;
    $assetCode     = $equipmentRow['asset_code'] ?? null;

    // ฟังก์ชัน sanitize
    function sanitizeName($str)
    {
        // ลบอักขระพิเศษ แต่เก็บตัวอักษรไทย, ตัวเลข, ตัวอักษรอังกฤษ, ขีดกลาง, ขีดล่าง
        $str = preg_replace('/[^\p{L}\p{N}_\-]/u', '', $str);
        return $str;
    }
    foreach ($urls as $key => $url) {
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            if (!empty($_POST['ma_file_name'][$key])) {
                $customName = $_POST['ma_file_name'][$key];
            } elseif ($assetCode) {
                // ใช้แค่ asset_code + running number
                $customName = sprintf("%s-%02d", $assetCode, $key + 1);
            } else {
                $customName = "Ma_URL_" . $plan_id . "_" . ($key + 1);
            }

            $typeName = $_POST['ma_type_name'][$key] ?? "ไม่ระบุ";

            $stmt = $dbh->prepare("
                INSERT INTO file_ma(plan_id, file_ma_name, file_ma_url, ma_type_name, upload_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$plan_id, $customName, $url, $typeName]);

            $uploadedFiles[] = [
                "name"  => $customName,
                "saved" => null,
                "url"   => $url,
                "type"  => $typeName
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
