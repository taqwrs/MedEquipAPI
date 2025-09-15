<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

include "../config/jwt.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

try {
    $input = $_POST;
    $equipment_id = $input['equipment_id'] ?? null;
    if (!$equipment_id) throw new Exception("equipment_id required");

    $dbh->beginTransaction();

    // reset child equipments
    $dbh->prepare("UPDATE equipments SET main_equipment_id = NULL WHERE main_equipment_id = :main_id")
        ->execute([':main_id' => $equipment_id]);

    // reset spare parts
    $dbh->prepare("UPDATE spare_parts SET equipment_id = NULL WHERE equipment_id = :equip_id")
        ->execute([':equip_id' => $equipment_id]);

    // delete equipment
    $dbh->prepare("DELETE FROM equipments WHERE equipment_id = :equipment_id")
        ->execute([':equipment_id' => $equipment_id]);

    $dbh->commit();
    echo json_encode(["status" => "success"]);

} catch (Exception $e) {
    $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
