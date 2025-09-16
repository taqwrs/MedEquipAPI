<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

include "../config/jwt.php";

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
    if (!$equipment_id)
        throw new Exception("equipment_id required");

    $dbh->beginTransaction();

    // ------------------ CHECK EXISTS ------------------
    $stmt = $dbh->prepare("SELECT COUNT(*) FROM equipments WHERE equipment_id = :id");
    $stmt->execute([':id' => $equipment_id]);
    if ($stmt->fetchColumn() == 0)
        throw new Exception("Equipment not found");

    // ------------------ RESET RELATIONS ------------------
    $dbh->prepare("UPDATE equipments SET main_equipment_id = NULL WHERE main_equipment_id = :main_id")
        ->execute([':main_id' => $equipment_id]);

    $dbh->prepare("UPDATE spare_parts SET equipment_id = NULL WHERE equipment_id = :equip_id")
        ->execute([':equip_id' => $equipment_id]);
    // ------------------ DELETE FILES ------------------
    $stmt = $dbh->prepare("SELECT file_equip_id, equip_url FROM file_equip WHERE equipment_id = :id");
    $stmt->execute([':id' => $equipment_id]);
    $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($files as $f) {
        $filePath = str_replace('http://localhost/back_equip/uploads/', $uploadDirectory, $f['equip_url']);
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }

    // ลบ record จากตาราง file_equip
    $dbh->prepare("DELETE FROM file_equip WHERE equipment_id = :id")
        ->execute([':id' => $equipment_id]);


    // ------------------ DELETE EQUIPMENT ------------------
    $dbh->prepare("DELETE FROM equipments WHERE equipment_id = :id")
        ->execute([':id' => $equipment_id]);

    $dbh->commit();
    echo json_encode(["status" => "success", "message" => "Equipment deleted", "id" => $equipment_id]);

} catch (Exception $e) {
    if ($dbh->inTransaction())
        $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
