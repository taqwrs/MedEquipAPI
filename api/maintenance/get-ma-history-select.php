<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$planId = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : null;
$viewType = isset($_GET['viewType']) ? $_GET['viewType'] : null;
$equipmentId = isset($_GET['equipment_id']) ? intval($_GET['equipment_id']) : null;

try {
    if ($viewType === "allRoundsOfEquipment" && $planId && $equipmentId) {
        // ดึงรอบทั้งหมดของแผน (ไม่ต้องสน equipment_id เพราะรอบเป็นของแผน)
        $stmt = $dbh->prepare("
            SELECT dmp.details_ma_id AS round_id, dmp.start_date
            FROM details_maintenance_plans dmp
            WHERE dmp.plan_id = :plan_id
            ORDER BY dmp.details_ma_id ASC
        ");
        $stmt->execute([':plan_id' => $planId]);
        $rounds = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'data' => array_map(function ($r, $i) {
                // แปลงวันที่ให้อยู่ในรูป d/m/Y
                $startDate = isset($r['start_date']) && $r['start_date'] !== null
                    ? date('d/m/Y', strtotime($r['start_date']))
                    : '-';
                return [
                    'label' => "รอบที่ " . ($i + 1) . " วันที่ $startDate ",
                    'value' => $r['round_id']
                ];
            }, $rounds, array_keys($rounds))
        ]);
        exit;

    } elseif ($viewType === "allEquipments" && $planId) {
        // ดึงอุปกรณ์ทั้งหมดที่อยู่ในแผน จากตาราง plan_ma_equipments
        $stmt = $dbh->prepare("
            SELECT e.equipment_id, e.asset_code, e.name
            FROM equipments e
            INNER JOIN plan_ma_equipments pme ON e.equipment_id = pme.equipment_id
            WHERE pme.plan_id = :plan_id
            ORDER BY e.name ASC
        ");
        $stmt->execute([':plan_id' => $planId]);
        $equipments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'status' => 'success',
            'data' => array_map(function ($e) {
                return [
                    'label' => $e['asset_code'] . " - " . $e['name'],
                    'value' => (string) $e['equipment_id']
                ];
            }, $equipments)
        ]);

        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid parameters']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}