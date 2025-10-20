<?php
include "../config/jwt.php";
include "../config/LogModel.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // ดึง subcategories และอุปกรณ์ทั้งหมด
        $stmt = $dbh->prepare("SELECT subcategory_id, name, category_id, type FROM equipment_subcategories ORDER BY name");
        $stmt->execute();
        $subcategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $dbh->prepare("
            SELECT equipment_id, name, brand, model, asset_code, status, location_details, subcategory_id
            FROM equipments
            WHERE status IN ('ใช้งาน', 'คลัง') AND active = 1
            ORDER BY subcategory_id
        ");
        $stmt->execute();
        $equipments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // จัดกลุ่มตาม subcategory
        $subcategoriesWithEquipments = array_map(function ($sub) use ($equipments) {
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
            $log = new LogModel($dbh);
            $user_id = $decoded->data->ID ?? null;
            if (!$user_id)
                throw new Exception("User ID not found");

            // ดึงอุปกรณ์ที่ผูกกับ MA Plan อยู่แล้ว
            $stmt = $dbh->prepare("SELECT equipment_id FROM plan_ma_equipments WHERE plan_id = :plan_id");
            $stmt->execute([':plan_id' => $plan_id]);
            $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // หารายการที่เพิ่มและลบ
            $toAdd = array_values(array_diff($newEquipmentIds, $existing));
            $toDelete = array_values(array_diff($existing, $newEquipmentIds));

            // ลบรายการที่ไม่ได้เลือก
            if (!empty($toDelete)) {
                $inQuery = implode(',', array_fill(0, count($toDelete), '?'));
                $stmtDelete = $dbh->prepare("DELETE FROM plan_ma_equipments WHERE plan_id = ? AND equipment_id IN ($inQuery)");
                $stmtDelete->execute(array_merge([$plan_id], $toDelete));
            }

            // เพิ่มรายการใหม่
            if (!empty($toAdd)) {
                $stmtInsert = $dbh->prepare("INSERT INTO plan_ma_equipments (plan_id, equipment_id) VALUES (:plan_id, :equipment_id)");
                foreach ($toAdd as $equipment_id) {
                    $stmtInsert->execute([
                        ':plan_id' => $plan_id,
                        ':equipment_id' => $equipment_id
                    ]);
                }
            }

            // สร้าง log แบบ UPDATE เดียว
            $oldData = ['plan_id' => $plan_id, 'equipment_ids' => $existing];
            $newData = ['plan_id' => $plan_id, 'equipment_ids' => $newEquipmentIds, 'added' => $toAdd, 'deleted' => $toDelete];
            $log->insertLog($user_id, 'plan_ma_equipments', 'UPDATE', $oldData, $newData);

            $dbh->commit();

            echo json_encode([
                "status" => "success",
                "message" => "อุปกรณ์ MA Plan ถูกอัปเดตเรียบร้อยแล้ว"
            ]);
            exit;

        } catch (Exception $e) {
            if ($dbh->inTransaction())
                $dbh->rollBack();
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
    if ($dbh->inTransaction())
        $dbh->rollBack();
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
