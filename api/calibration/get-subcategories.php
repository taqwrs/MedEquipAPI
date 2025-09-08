<?php
include "../config/jwt.php"; 

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'POST') {
        $data = json_decode(file_get_contents("php://input"), true);
        if (!isset($data['plan_id']) || !isset($data['equipment_ids']) || !is_array($data['equipment_ids'])) {
            echo json_encode([
                "status" => "error",
                "message" => "Missing plan_id or equipment_ids"
            ]);
            exit;
        }

        $plan_id = $data['plan_id'];
        $newEquipmentIds = $data['equipment_ids'];

        $dbh->beginTransaction();


        $stmt = $dbh->prepare("SELECT equipment_id FROM plan_equipments WHERE plan_id = :plan_id");
        $stmt->execute([':plan_id' => $plan_id]);
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);


        $toAdd = array_diff($newEquipmentIds, $existing);

        if (!empty($toAdd)) {
            $stmt = $dbh->prepare("INSERT INTO plan_equipments (plan_id, equipment_id) VALUES (:plan_id, :equipment_id)");
            foreach ($toAdd as $equipment_id) {
                $stmt->execute([
                    ':plan_id' => $plan_id,
                    ':equipment_id' => $equipment_id
                ]);
            }
        }

        $dbh->commit();

        echo json_encode([
            "status" => "success",
            "message" => "Added equipments successfully",
            "added_ids" => $toAdd
        ]);
        exit;
    }

    echo json_encode([
        "status" => "error",
        "message" => "Invalid request method"
    ]);
} catch (Exception $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
