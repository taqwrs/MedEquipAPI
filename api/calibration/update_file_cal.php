<?php
include "../config/jwt.php";
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

try {
    $dbh->beginTransaction();

    $plan_id = $_POST['plan_id'] ?? null;
    if (!$plan_id) throw new Exception("plan_id ไม่พบ");

    $uploadDir = __DIR__ . "/../file-upload/file_cal/";
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    // ---------------- ลบไฟล์ ----------------
    if (!empty($_POST['file_ids_to_delete'])) {
        $ids = is_array($_POST['file_ids_to_delete']) ? $_POST['file_ids_to_delete'] : [$_POST['file_ids_to_delete']];
        foreach ($ids as $fid) {
            $stmt = $dbh->prepare("SELECT file_cal_url FROM file_cal WHERE file_cal_id = ? AND plan_id = ?");
            $stmt->execute([$fid, $plan_id]);
            $file = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($file) {
                $realPath = $uploadDir . basename($file['file_cal_url']);
                if (file_exists($realPath)) unlink($realPath);
                $del = $dbh->prepare("DELETE FROM file_cal WHERE file_cal_id = ? AND plan_id = ?");
                $del->execute([$fid, $plan_id]);
            }
        }
    }

    // ---------------- ลบ URL ----------------
    if (!empty($_POST['url_to_delete'])) {
        $urlsToDelete = is_array($_POST['url_to_delete']) ? $_POST['url_to_delete'] : [$_POST['url_to_delete']];
        foreach ($urlsToDelete as $url) {
            $stmt = $dbh->prepare("DELETE FROM file_cal WHERE plan_id = ? AND file_cal_url = ?");
            $stmt->execute([$plan_id, $url]);
        }
    }

    // ---------------- อัปโหลดไฟล์ใหม่ ----------------
    if (!empty($_FILES['file_cal']['name'][0])) {
        foreach ($_FILES['file_cal']['name'] as $key => $name) {
            if ($_FILES['file_cal']['error'][$key] === UPLOAD_ERR_OK) {
                $tmp = $_FILES['file_cal']['tmp_name'][$key];
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','gif','webp','pdf','docx','doc','xlsx','xls'];
                if (!in_array($ext, $allowed)) throw new Exception("รูปแบบไฟล์ไม่รองรับ: $name");
                $newName = uniqid('cal_', true) . '.' . $ext;
                $targetFile = $uploadDir . $newName;
                if (!move_uploaded_file($tmp, $targetFile)) throw new Exception("อัปโหลดล้มเหลว: $name");

                $url = "/file-upload/file_cal/$newName";
                $typeName = $_POST['cal_type_name'][$key] ?? "เอกสาร";

                $stmt = $dbh->prepare("INSERT INTO file_cal(plan_id, file_cal_name, file_cal_url, cal_type_name) VALUES (?, ?, ?, ?)");
                $stmt->execute([$plan_id, $name, $url, $typeName]);
            }
        }
    }

    // ---------------- เพิ่ม URL ----------------
    if (!empty($_POST['file_cal_url'])) {
        $urls = is_array($_POST['file_cal_url']) ? $_POST['file_cal_url'] : [$_POST['file_cal_url']];
        foreach ($urls as $key => $url) {
            if (!filter_var($url, FILTER_VALIDATE_URL)) continue;
            $stmtCheck = $dbh->prepare("SELECT COUNT(*) FROM file_cal WHERE plan_id = ? AND file_cal_url = ?");
            $stmtCheck->execute([$plan_id, $url]);
            if ($stmtCheck->fetchColumn() > 0) continue;

            $baseName = "ฟอร์มบันทึกผลการสอบเทียบ";
            $customName = $baseName;
            $counter = 1;
            while (true) {
                $stmt = $dbh->prepare("SELECT COUNT(*) FROM file_cal WHERE file_cal_name = ? AND plan_id = ?");
                $stmt->execute([$customName, $plan_id]);
                if ($stmt->fetchColumn() == 0) break;
                $counter++;
                $customName = $baseName . '-' . sprintf('%02d', $counter);
            }

            $typeIndex = count($_FILES['file_cal']['name'] ?? []) + $key;
            $typeName = $_POST['cal_type_name'][$typeIndex] ?? "ลิงก์";

            $stmt = $dbh->prepare("INSERT INTO file_cal(plan_id, file_cal_name, file_cal_url, cal_type_name) VALUES (?, ?, ?, ?)");
            $stmt->execute([$plan_id, $customName, $url, $typeName]);
        }
    }

    $dbh->commit();

    $stmt = $dbh->prepare("SELECT file_cal_id AS id, file_cal_name, file_cal_url, cal_type_name FROM file_cal WHERE plan_id = ? ORDER BY file_cal_id ASC");
    $stmt->execute([$plan_id]);
    $allFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(["status" => "success", "files" => $allFiles]);
} catch (Exception $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
