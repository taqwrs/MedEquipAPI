<?php
include "../config/jwt.php";
include "../config/LogModel.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

try {
    $dbh->beginTransaction();
    $user_id = null;
    if (isset($decoded->data->ID)) {
        $user_id = $decoded->data->ID;
    } else {
        throw new Exception("ไม่พบข้อมูล user_id ใน token");
    }


    $logModel = new LogModel($dbh);

    $plan_id = $_POST['plan_id'] ?? null;
    if (!$plan_id) throw new Exception("plan_id ไม่พบ");

    $uploadedFiles = [];
    $uploadDir = __DIR__ . "/../file-upload/file_cal/";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    $files = $_FILES['file_cal'] ?? null;
    $fileCount = 0;

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
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'docx'];
                if (!in_array($ext, $allowed)) throw new Exception("Invalid file format: $name");

                $newName = uniqid('cal_', true) . '.' . $ext;
                $targetFile = $uploadDir . $newName;
                if (!move_uploaded_file($tmp, $targetFile)) throw new Exception("Upload failed: $name");

                $url = "/file-upload/file_cal/$newName";
                $typeName = $_POST['cal_type_name'][$key] ?? "ไม่ระบุ";

                $stmt = $dbh->prepare("
                    INSERT INTO file_cal(plan_id, file_cal_name, file_cal_url, cal_type_name)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$plan_id, $name, $url, $typeName]);
                
                $insertedId = $dbh->lastInsertId();
                
 
                $logModel->insertLog(
                    $user_id,
                    'file_cal',
                    'INSERT',
                    null,
                    [
                        'file_cal_id' => $insertedId,
                        'plan_id' => $plan_id,
                        'file_cal_name' => $name,
                        'file_cal_url' => $url,
                        'cal_type_name' => $typeName
                    ]
                );

                $uploadedFiles[] = [
                    "name" => $name,
                    "saved" => $newName,
                    "url" => $url,
                    "type" => $typeName
                ];
                
                $fileCount++;
            }
        }
    }

    if (!empty($_POST['file_cal_url'])) {
        $urls = is_array($_POST['file_cal_url']) ? $_POST['file_cal_url'] : [$_POST['file_cal_url']];

        foreach ($urls as $key => $url) {
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                $baseName = "ฟอร์มบันทึกผลการสอบเทียบ";
                $customName = $baseName;
                $counter = 1;

                while (true) {
                    $stmt = $dbh->prepare("SELECT COUNT(*) FROM file_cal WHERE file_cal_name = ? AND plan_id = ?");
                    $stmt->execute([$customName, $plan_id]);
                    $count = $stmt->fetchColumn();
                    if ($count == 0) break;
                    $counter++;
                    $customName = $baseName . '-' . sprintf('%02d', $counter);
                }

                $typeIndex = $fileCount + $key;
                $typeName = $_POST['cal_type_name'][$typeIndex] ?? "ลิงก์";

                $stmt = $dbh->prepare("
                    INSERT INTO file_cal(plan_id, file_cal_name, file_cal_url, cal_type_name)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$plan_id, $customName, $url, $typeName]);
                
                $insertedId = $dbh->lastInsertId();
                
                // บันทึก log การเพิ่ม URL
                $logModel->insertLog(
                    $user_id,
                    'file_cal',
                    'INSERT',
                    null,
                    [
                        'file_cal_id' => $insertedId,
                        'plan_id' => $plan_id,
                        'file_cal_name' => $customName,
                        'file_cal_url' => $url,
                        'cal_type_name' => $typeName
                    ]
                );

                $uploadedFiles[] = [
                    "name" => $customName,
                    "saved" => null,
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