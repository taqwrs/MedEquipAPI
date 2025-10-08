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
    if (!$user_id) throw new Exception("User ID not found");

    $plan_id = $_POST['plan_id'] ?? null;
    if (!$plan_id) throw new Exception("plan_id ไม่พบ");

    $uploadedFiles = [];
    $uploadDir = __DIR__ . "/../file-upload/file_ma/";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $files = $_FILES['file_ma'] ?? null;

    // ------------------ อัปโหลดไฟล์จากเครื่อง ------------------
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
            try {
                if ($files['error'][$key] !== UPLOAD_ERR_OK) continue;

                $tmp = $files['tmp_name'][$key];
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'docx'];
                if (!in_array($ext, $allowed)) continue;

                $newName = uniqid('ma_', true) . '.' . $ext;
                $targetFile = $uploadDir . $newName;
                if (!move_uploaded_file($tmp, $targetFile)) continue;

                $url = "/file-upload/file_ma/$newName";
                $typeName = $_POST['ma_type_name'][$key] ?? "ไม่ระบุ";

                // บันทึกลง DB
                $stmt = $dbh->prepare("
                    INSERT INTO file_ma(plan_id, file_ma_name, file_ma_url, ma_type_name, upload_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$plan_id, $name, $url, $typeName]);

                // บันทึก log
                $log->insertLog($user_id, 'file_ma', 'INSERT', null, [
                    'plan_id' => $plan_id,
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
                // ถ้าไฟล์นี้ล้มเหลว ให้ข้ามไปไฟล์ถัดไป
                continue;
            }
        }
    }

    // ------------------ เพิ่มไฟล์จาก URL ------------------
    if (!empty($_POST['ma_result_url'])) {
        $urls = is_array($_POST['ma_result_url']) ? $_POST['ma_result_url'] : [$_POST['ma_result_url']];
        $dateStr = date('d-m-Y');

        foreach ($urls as $key => $url) {
            try {
                if (!filter_var($url, FILTER_VALIDATE_URL)) continue;

                $baseName = "MaPlan{$plan_id}-{$dateStr}";
                $customName = $baseName;
                $counter = 1;

                // ตรวจสอบซ้ำใน DB
                while (true) {
                    $stmt = $dbh->prepare("SELECT COUNT(*) FROM file_ma WHERE file_ma_name = ? AND plan_id = ?");
                    $stmt->execute([$customName, $plan_id]);
                    if ($stmt->fetchColumn() == 0) break;
                    $counter++;
                    $customName = $baseName . '-' . sprintf('%02d', $counter);
                }

                $typeName = $_POST['ma_type_name'][$key] ?? "ลิงก์";

                $stmt = $dbh->prepare("
                    INSERT INTO file_ma(plan_id, file_ma_name, file_ma_url, ma_type_name, upload_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmt->execute([$plan_id, $customName, $url, $typeName]);

                // บันทึก log
                $log->insertLog($user_id, 'file_ma', 'INSERT', null, [
                    'plan_id' => $plan_id,
                    'file_name' => $customName,
                    'file_url' => $url,
                    'type' => $typeName
                ]);

                $uploadedFiles[] = [
                    "name" => $customName,
                    "saved" => null,
                    "url" => $url,
                    "type" => $typeName
                ];
            } catch (Exception $e) {
                continue;
            }
        }
    }

    $dbh->commit();
    echo json_encode(["status" => "success", "files" => $uploadedFiles], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
