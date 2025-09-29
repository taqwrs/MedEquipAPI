<?php
include "../config/jwt.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

try {
    $dbh->beginTransaction();

    $type = $_POST['type'] ?? null;
    $file_id = $_POST['file_id'] ?? null;

    if (!$type || !$file_id)
        throw new Exception("Missing parameters");

    switch ($type) {
        case 'equip':
            $stmt = $dbh->prepare("SELECT equip_url FROM file_equip WHERE file_equip_id=?");
            $stmt->execute([$file_id]);
            $file = $stmt->fetchColumn();
            $stmt = $dbh->prepare("DELETE FROM file_equip WHERE file_equip_id=?");
            break;

        case 'spare':
            $stmt = $dbh->prepare("SELECT spare_url FROM file_spare WHERE file_spare_id=?");
            $stmt->execute([$file_id]);
            $file = $stmt->fetchColumn();
            $stmt = $dbh->prepare("DELETE FROM file_spare WHERE file_spare_id=?");
            break;

        case 'ma':
            $stmt = $dbh->prepare("SELECT file_ma_url FROM file_ma WHERE file_ma_id=?");
            $stmt->execute([$file_id]);
            $file = $stmt->fetchColumn();
            $stmt = $dbh->prepare("DELETE FROM file_ma WHERE file_ma_id=?");
            break;

        case 'ma-result':
            // ดึง URL ของไฟล์ก่อนลบ
            $stmt = $dbh->prepare("SELECT file_ma_url FROM file_ma_result WHERE file_ma_result_id=?");
            $stmt->execute([$file_id]);
            $file = $stmt->fetchColumn();

            // ลบเรคคอร์ดใน DB
            $stmt = $dbh->prepare("DELETE FROM file_ma_result WHERE file_ma_result_id=?");
            break;


        default:
            throw new Exception("Unknown type: $type");
    }

    $stmt->execute([$file_id]);

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