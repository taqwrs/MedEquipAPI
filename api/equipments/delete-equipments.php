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
        
    $equipment_id = $input['equipment_id'] ?? null;
    if (!$equipment_id) throw new Exception("equipment_id required");

    $dbh->beginTransaction();
    $log = new LogModel($dbh);
    $user_id = $decoded->data->ID ?? null;
    if (!$user_id) throw new Exception("User ID not found");

    // ------------------ FETCH OLD DATA ------------------
    $stmt = $dbh->prepare("SELECT * FROM equipments WHERE equipment_id=:id");
    $stmt->execute([':id' => $equipment_id]);
    $oldEquipment = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $dbh->prepare("SELECT equipment_id FROM equipments WHERE main_equipment_id = :main_id");
    $stmt->execute([':main_id' => $equipment_id]);
    $oldChilds = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    $stmt = $dbh->prepare("SELECT spare_part_id FROM spare_parts WHERE equipment_id=:equip_id");
    $stmt->execute([':equip_id' => $equipment_id]);
    $oldSpares = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    $stmt = $dbh->prepare("SELECT file_equip_id, file_equip_name, equip_url FROM file_equip WHERE equipment_id=:id");
    $stmt->execute([':id'=>$equipment_id]);
    $oldFiles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ------------------ RESET RELATIONS ------------------
    $dbh->prepare("UPDATE equipments SET main_equipment_id = NULL WHERE main_equipment_id = :main_id")
        ->execute([':main_id' => $equipment_id]);

    $dbh->prepare("UPDATE spare_parts SET equipment_id = NULL WHERE equipment_id = :equip_id")
        ->execute([':equip_id' => $equipment_id]);

    // ------------------ DELETE FILES ------------------
    foreach ($oldFiles as $f) {
        $filePath = str_replace('http://localhost/back_equip/uploads/', $uploadDirectory, $f['equip_url']);
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }
    $dbh->prepare("DELETE FROM file_equip WHERE equipment_id = :id")
        ->execute([':id'=>$equipment_id]);

    // ------------------ DELETE EQUIPMENT ------------------
    $dbh->prepare("DELETE FROM equipments WHERE equipment_id = :id")
        ->execute([':id'=>$equipment_id]);

    // ------------------ INSERT LOG ------------------
    $log->insertLog(
        $user_id,
        'equipments',
        'DELETE',
        [
            'equipment' => $oldEquipment,
            'child_equipments' => $oldChilds,
            'spare_parts' => $oldSpares,
            'files' => $oldFiles
        ],
        null
        , 'register_logs'
    );

    $dbh->commit();
    echo json_encode(["status"=>"success","message"=>"Equipment deleted","id"=>$equipment_id]);

} catch (Exception $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
