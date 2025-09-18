<?php
include "../config/jwt.php";
// include "../config/LogModel.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status"=>"error","message"=>"POST method only"]);
    exit;
}

try {
    $dbh->beginTransaction();
    // $log = new LogModel($dbh);

    $type = $_POST['type'] ?? null;
    $file_id = $_POST['file_id'] ?? null;

    if (!$type || !$file_id) throw new Exception("Missing parameters");

    if ($type === 'equip') {
        $stmt = $dbh->prepare("SELECT equip_url FROM file_equip WHERE file_equip_id=?");
        $stmt->execute([$file_id]);
        $file = $stmt->fetchColumn();
        $stmt = $dbh->prepare("DELETE FROM file_equip WHERE file_equip_id=?");
    } else {
        $stmt = $dbh->prepare("SELECT spare_url FROM file_spare WHERE file_spare_id=?");
        $stmt->execute([$file_id]);
        $file = $stmt->fetchColumn();
        $stmt = $dbh->prepare("DELETE FROM file_spare WHERE file_spare_id=?");
    }

    $stmt->execute([$file_id]);
    if ($file) {
        $filePath = __DIR__ . "/../../" . ltrim($file, '/');
        if (file_exists($filePath)) unlink($filePath);
    }

    // $log->insertLog($employee_code, $type==='equip'?'file_equip':'file_spare','DELETE', null, ['file_id'=>$file_id]);
    $dbh->commit();

    echo json_encode(["status"=>"success"]);
} catch (Exception $e) {
    $dbh->rollBack();
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
?>
