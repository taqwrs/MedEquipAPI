<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(['status' => 'error', 'message' => 'GET method required']);
    exit;
}

$equipmentId = isset($_GET['equipment_id']) ? intval($_GET['equipment_id']) : null;
$roundId     = isset($_GET['round_id']) ? intval($_GET['round_id']) : null;
$viewType    = $_GET['viewType'] ?? null;

try {
    if (!$dbh) {
        throw new Exception("Database connection failed");
    }

    $result = [];

    if ($viewType === "allRoundsOfEquipment" && $equipmentId) {
        $stmt = $dbh->prepare("
            SELECT 
                mp.plan_id, mp.plan_name,
                dmp.details_ma_id, dmp.start_date,
                mr.ma_result_id, mr.result, mr.details, mr.reason, mr.performed_date,
                e.name AS equipment_name
            FROM plan_ma_equipments pe
            INNER JOIN maintenance_plans mp ON mp.plan_id = pe.plan_id
            INNER JOIN details_maintenance_plans dmp ON dmp.plan_id = mp.plan_id
            INNER JOIN maintenance_result mr ON mr.details_ma_id = dmp.details_ma_id 
                                              AND mr.equipment_id = pe.equipment_id
            INNER JOIN equipments e ON e.equipment_id = pe.equipment_id
            WHERE pe.equipment_id = :equipment_id
            ORDER BY dmp.start_date ASC
        ");
        $stmt->execute([':equipment_id' => $equipmentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows) {
            $rounds = [];
            foreach ($rows as $idx => $row) {
                $rounds[] = [
                    'round'         => $idx + 1,
                    'details_ma_id' => $row['details_ma_id'],
                    'ma_result_id'  => $row['ma_result_id'],
                    'plan_name'     => $row['plan_name'],
                    'status'        => $row['result'],
                    'details'       => $row['details'] ?: '-',
                    'reason'        => $row['reason'] ?: '-',
                    'start_date'    => $row['start_date'] ?: '-',
                    'performed_date'=> $row['performed_date'] ?: '-'
                ];
            }

            $result[] = [
                'name'   => $rows[0]['equipment_name'] ?? "Equipment $equipmentId",
                'rounds' => $rounds
            ];
        }
    }
    elseif ($viewType === "allEquipments" && $roundId) {
        $stmt = $dbh->prepare("
            SELECT 
                mp.plan_id, mp.plan_name,
                dmp.details_ma_id, dmp.start_date,
                mr.ma_result_id, mr.result, mr.details, mr.reason, mr.performed_date,
                e.equipment_id, e.name AS equipment_name
            FROM details_maintenance_plans dmp
            INNER JOIN maintenance_plans mp ON mp.plan_id = dmp.plan_id
            INNER JOIN maintenance_result mr ON mr.details_ma_id = dmp.details_ma_id
            INNER JOIN equipments e ON e.equipment_id = mr.equipment_id
            WHERE dmp.details_ma_id = :round_id
            ORDER BY e.name ASC
        ");
        $stmt->execute([':round_id' => $roundId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $result[] = [
                'name'   => $row['equipment_name'],
                'rounds' => [[
                    'round'         => 1,
                    'details_ma_id' => $row['details_ma_id'],
                    'ma_result_id'  => $row['ma_result_id'],
                    'plan_name'     => $row['plan_name'],
                    'status'        => $row['result'],
                    'details'       => $row['details'] ?: '-',
                    'reason'        => $row['reason'] ?: '-',
                    'start_date'    => $row['start_date'] ?: '-',
                    'performed_date'=> $row['performed_date'] ?: '-'
                ]]
            ];
        }
    }

    if (empty($result)) {
        echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูล']);
        exit;
    }

    echo json_encode(['status' => 'success', 'data' => $result]);

} catch (Exception $e) {
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ]);
}
