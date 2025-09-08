<?php
include "../config/jwt.php"; 

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // GET: ดึง subcategories + equipments
        $stmt = $dbh->prepare("SELECT subcategory_id, name, category_id, type FROM equipment_subcategories ORDER BY name");
        $stmt->execute();
        $subcategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $dbh->prepare("
            SELECT equipment_id, name, brand, model, asset_code, status, location_details, subcategory_id
            FROM equipments
            ORDER BY name
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

        $dbh->beginTransaction();

        // ดึงรายการอุปกรณ์เดิม
        $stmt = $dbh->prepare("SELECT equipment_id FROM plan_equipments WHERE plan_id = :plan_id");
        $stmt->execute([':plan_id' => $plan_id]);
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // หาเครื่องมือที่ต้องลบและต้องเพิ่ม
        $toDelete = array_diff($existing, $newEquipmentIds);
        $toAdd = array_diff($newEquipmentIds, $existing);

        if (!empty($toDelete)) {
            $in = implode(',', array_fill(0, count($toDelete), '?'));
            $stmt = $dbh->prepare("DELETE FROM plan_equipments WHERE plan_id = ? AND equipment_id IN ($in)");
            $stmt->execute(array_merge([$plan_id], $toDelete));
        }

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
            "message" => "Plan equipments updated successfully"
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
