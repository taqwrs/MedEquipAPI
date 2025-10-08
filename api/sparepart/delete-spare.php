<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

include "../config/jwt.php";
include "../config/LogModel.php"; 

$uploadDirectory = 'C:\xampp\htdocs\back_equip\uploads\\';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

try {
    $input = (strpos(strtolower(getenv("CONTENT_TYPE")), 'application/json') !== false)
        ? json_decode(file_get_contents('php://input'), true)
        : $_POST;

    $spare_part_id = $input['spare_part_id'] ?? null;
    $user_id = $decoded->data->ID ?? null; 
    $log = new LogModel($dbh); 

    if (!$spare_part_id) throw new Exception("spare_part_id required");

    $dbh->beginTransaction();

    // --- ดึงข้อมูลเก่าสำหรับ log ---
    $stmtOld = $dbh->prepare("SELECT * FROM spare_parts WHERE spare_part_id = :id");
    $stmtOld->execute([':id' => $spare_part_id]);
    $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

    // ------------------ CHECK EXISTS ------------------
    $stmt = $dbh->prepare("SELECT COUNT(*) FROM spare_parts WHERE spare_part_id = :id");
    $stmt->execute([':id' => $spare_part_id]);
    if ($stmt->fetchColumn() == 0) throw new Exception("Spare part not found");

    // ------------------ RESET RELATIONS ------------------
    $dbh->prepare("UPDATE spare_parts SET equipment_id = NULL WHERE spare_part_id = :id")
        ->execute([':id' => $spare_part_id]);

    // ------------------ DELETE FILES ------------------
    $stmt = $dbh->prepare("SELECT file_spare_id, spare_url FROM file_spare WHERE spare_part_id = :id");
    $stmt->execute([':id' => $spare_part_id]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($files as $f) {
        $filePath = str_replace('http://localhost/back_equip/uploads/', $uploadDirectory, $f['spare_url']);
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }
    $dbh->prepare("DELETE FROM file_spare WHERE spare_part_id = :id")
        ->execute([':id' => $spare_part_id]);

    // ------------------ DELETE SPARE ------------------
    $dbh->prepare("DELETE FROM spare_parts WHERE spare_part_id = :id")
        ->execute([':id' => $spare_part_id]);

    // --- Log delete spare part ---
    $log->insertLog($user_id, 'spare_parts', 'DELETE', $oldData, [], 'register_logs');

    $dbh->commit();
    echo json_encode(["status" => "success", "message" => "Spare part deleted", "id" => $spare_part_id]);

} catch (Exception $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
