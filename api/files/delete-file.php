<?php
include "../config/jwt.php";
include "../config/LogModel.php";

header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

try {
    $dbh->beginTransaction();
    $log = new LogModel($dbh);

    // รองรับทั้ง JSON และ FormData
    $input = json_decode(file_get_contents('php://input'), true);
    $type = $_POST['type'] ?? $input['type'] ?? null;
    $file_id = $_POST['file_id'] ?? $input['file_id'] ?? null;
    $user_id = $decoded->data->ID ?? null;
    if (!$user_id)
        throw new Exception("User ID not found");
    if (!$type || !$file_id)
        throw new Exception("Missing parameters");

    switch ($type) {
        case 'equip':
            $table = 'file_equip';
            $stmt = $dbh->prepare("SELECT equip_url FROM file_equip WHERE file_equip_id=?");
            $stmt->execute([$file_id]);
            $file = $stmt->fetchColumn();
            $stmt = $dbh->prepare("DELETE FROM file_equip WHERE file_equip_id=?");
            break;

        case 'spare':
            $table = 'file_spare';
            $stmt = $dbh->prepare("SELECT spare_url FROM file_spare WHERE file_spare_id=?");
            $stmt->execute([$file_id]);
            $file = $stmt->fetchColumn();
            $stmt = $dbh->prepare("DELETE FROM file_spare WHERE file_spare_id=?");
            break;

        case 'ma':
            $table = 'file_ma';
            $stmt = $dbh->prepare("SELECT file_ma_url FROM file_ma WHERE file_ma_id=?");
            $stmt->execute([$file_id]);
            $file = $stmt->fetchColumn();
            $stmt = $dbh->prepare("DELETE FROM file_ma WHERE file_ma_id=?");
            break;

        case 'ma-result':
            $table = 'file_ma_result';
            $stmt = $dbh->prepare("SELECT file_ma_url FROM file_ma_result WHERE file_ma_result_id=?");
            $stmt->execute([$file_id]);
            $file = $stmt->fetchColumn();
            $stmt = $dbh->prepare("DELETE FROM file_ma_result WHERE file_ma_result_id=?");
            break;

        default:
            throw new Exception("Unknown type: $type");
    }

    // ลบ record ออกจากฐานข้อมูล
    $stmt->execute([$file_id]);
    // Log การลบ
    $log->insertLog(
        $user_id,
        $table,
        'DELETE',
        ['file_id' => $file_id, 'file_url' => $file],
        null
    );
    // ย้ายไฟล์จริงไป trash folder
    if ($file) {
        $basePath = realpath(__DIR__ . "/../file-upload");
        $trashDir = $basePath . "/file_trash";

        // ถ้าโฟลเดอร์ trash ยังไม่มี ให้สร้าง
        if (!is_dir($trashDir)) {
            mkdir($trashDir, 0777, true);
        }

        // Normalize path ให้ถูกต้องทุกกรณี
        $relativePath = $file;
        // ลบ prefix ที่ไม่จำเป็น
        $relativePath = preg_replace('#^(uploads/|/uploads/|file-upload/|/file-upload/)#', '', $relativePath);
        // กำหนด full path
        $filePath = $basePath . '/' . $relativePath;

        if (file_exists($filePath)) {
            $fileName = basename($filePath);
            $trashPath = $trashDir . "/" . date('Ymd_His') . "_" . $fileName;

            // ย้ายไฟล์ไปโฟลเดอร์ trash
            if (!rename($filePath, $trashPath)) {
                throw new Exception("Failed to move file to trash: $fileName");
            }

            //การย้ายไฟล์
            $log->insertLog(
                $user_id,
                $table,
                'MOVE_TO_TRASH',
                ['old_path' => $filePath],
                ['trash_path' => $trashPath]
            );
        } else {
            // ถ้าไม่เจอไฟล์จริง ก็ให้ log ไว้เพื่อ debug ทีหลัง
            $log->insertLog(
                $user_id,
                $table,
                'MISSING_FILE',
                ['expected_path' => $filePath],
                null
            );
        }
    }

    $dbh->commit();
    echo json_encode(["status" => "success"], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($dbh->inTransaction())
        $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}