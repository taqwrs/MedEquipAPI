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

    // ลบเรคคอร์ดใน DB
    $stmt->execute([$file_id]);

    // บันทึก Log
    $log->insertLog(
        $user_id,
        $table,
        'DELETE',
        ['file_id' => $file_id, 'file_url' => $file],
        null
    );

    // ลบไฟล์จริงบน server
    if ($file) {
        $filePath = __DIR__ . "/../../" . ltrim($file, '/');
        if (file_exists($filePath))
            unlink($filePath);
    }

    $dbh->commit();
    echo json_encode(["status" => "success"]);

} catch (Exception $e) {
    $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
