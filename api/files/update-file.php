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

    $type = $_POST['type'] ?? null; // 'equip' or 'spare'
    $file_id = $_POST['file_id'] ?? null;
    $newName = $_POST['file_name'] ?? null;
    $newType = $_POST['file_type_name'] ?? null;

    if (!$type || !$file_id)
        throw new Exception("Missing parameters");

    $updates = [];
    $params = [];

    if ($newName !== null) {
        $updates[] = $type === 'equip' ? "file_equip_name=?" : "file_spare_name=?";
        $params[] = $newName;
    }
    if ($newType !== null) {
        $updates[] = $type === 'equip' ? "equip_type_name=?" : "spare_type_name=?";
        $params[] = $newType;
    }

    if (!$updates)
        throw new Exception("No fields to update");

    $params[] = $file_id;
    $sql = "UPDATE " . ($type === 'equip' ? "file_equip" : "file_spare") . " SET " . implode(",", $updates) . " WHERE " . ($type === 'equip' ? "file_equip_id=?" : "file_spare_id=?");

    $stmt = $dbh->prepare($sql);
    $stmt->execute($params);

    // $log->insertLog($employee_code, $type==='equip'?'file_equip':'file_spare','UPDATE', null, ['file_id'=>$file_id,'file_name'=>$newName]);

    $dbh->commit();
    echo json_encode(["status" => "success"]);
} catch (Exception $e) {
    $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}