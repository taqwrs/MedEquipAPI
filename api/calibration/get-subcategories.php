<?php
include "../config/jwt.php"; 

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {

        $stmt = $dbh->prepare("SELECT subcategory_id, name, category_id, type FROM equipment_subcategories ORDER BY name");
        $stmt->execute();
        $subcategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

  
        $stmt = $dbh->prepare("
            SELECT equipment_id, name, brand, model, asset_code, status, location_details, subcategory_id
            FROM equipments
            ORDER BY subcategory_id
        ");
        $stmt->execute();
        $equipments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $subcategoriesWithEquipments = array_map(function($sub) use ($equipments) {
            $sub['equipments'] = array_values(array_filter($equipments, fn($eq) => $eq['subcategory_id'] == $sub['subcategory_id']));
            return $sub;
        }, $subcategories);

        echo json_encode([
            "status" => "success",
            "data" => [
                "subcategories" => $subcategoriesWithEquipments,
                "equipments" => $equipments
            ]
        ]);
        exit;
    }

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

        try {
            $dbh->beginTransaction();


            $stmt = $dbh->prepare("SELECT equipment_id FROM plan_equipments WHERE plan_id = :plan_id");
            $stmt->execute([':plan_id' => $plan_id]);
            $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);


            $toAdd = array_diff($newEquipmentIds, $existing);
            if (!empty($toAdd)) {
                $stmtInsert = $dbh->prepare("INSERT INTO plan_equipments (plan_id, equipment_id) VALUES (:plan_id, :equipment_id)");
                foreach ($toAdd as $equipment_id) {
                    $stmtInsert->execute([
                        ':plan_id' => $plan_id,
                        ':equipment_id' => $equipment_id
                    ]);
                }
            }


            $toDelete = array_diff($existing, $newEquipmentIds);
            if (!empty($toDelete)) {
                $inQuery = implode(',', array_fill(0, count($toDelete), '?'));
                $stmtDelete = $dbh->prepare("DELETE FROM plan_equipments WHERE plan_id = ? AND equipment_id IN ($inQuery)");
                $stmtDelete->execute(array_merge([$plan_id], $toDelete));
            }

            $dbh->commit();

            echo json_encode([
                "status" => "success",
                "message" => "อุปกรณ์ถูกอัปเดตเรียบร้อยแล้ว"
            ]);
            exit;
        } catch (Exception $e) {
            if ($dbh->inTransaction()) $dbh->rollBack();
            echo json_encode([
                "status" => "error",
                "message" => $e->getMessage()
            ]);
            exit;
        }
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
