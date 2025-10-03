<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$equipmentId = isset($_GET['equipment_id']) ? intval($_GET['equipment_id']) : null;
$roundId = isset($_GET['round_id']) ? intval($_GET['round_id']) : null;
$planId = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : null;
$viewType = isset($_GET['viewType']) ? $_GET['viewType'] : null;

try {
    $result = [];

    if ($viewType === "allRoundsOfEquipment" && $equipmentId && $planId) {
        // ดึงชื่ออุปกรณ์
        $stmtEq = $dbh->prepare("SELECT asset_code, name FROM equipments WHERE equipment_id = :equipment_id");
        $stmtEq->execute([':equipment_id' => $equipmentId]);
        $eqData = $stmtEq->fetch(PDO::FETCH_ASSOC);
        $equipmentName = ($eqData['asset_code'] ?? "-") . " - " . ($eqData['name'] ?? $equipmentId);

        // ดึงประวัติบำรุงรักษา
        $stmtDetails = $dbh->prepare("
            SELECT dmp.details_ma_id, mr.ma_result_id, mr.result, mr.details, mr.reason, dmp.start_date, mr.performed_date, mp.plan_name
            FROM details_maintenance_plans dmp
            INNER JOIN maintenance_result mr 
                ON mr.details_ma_id = dmp.details_ma_id
            INNER JOIN maintenance_plans mp
                ON mp.plan_id = dmp.plan_id
            WHERE dmp.plan_id = :plan_id AND mr.equipment_id = :equipment_id
            ORDER BY dmp.details_ma_id ASC
        ");
        $stmtDetails->execute([
            ':plan_id' => $planId,
            ':equipment_id' => $equipmentId
        ]);
        $details = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);

        if ($details) {
            $rounds = [];
            foreach ($details as $idx => $row) {
                $rounds[] = [
                    'round' => $idx + 1,
                    'details_ma_id' => $row['details_ma_id'],
                    'ma_result_id' => $row['ma_result_id'],
                    'plan_name' => $row['plan_name'] ?? "-",
                    'status' => $row['result'],
                    'details' => $row['details'] ?: '-',
                    'reason' => $row['reason'] ?: '-',
                    'start_date' => $row['start_date'] ?: '-',
                    'performed_date' => $row['performed_date'] ?: '-'
                ];
            }

            $result[] = [
                'name' => $equipmentName,
                'rounds' => $rounds
            ];
        }

    } elseif ($viewType === "allEquipments" && $roundId) {
        // ตรวจสอบ plan_id จาก round
        $stmtCheck = $dbh->prepare("
            SELECT dmp.plan_id
            FROM details_maintenance_plans dmp
            WHERE dmp.details_ma_id = :round_id
        ");
        $stmtCheck->execute([':round_id' => $roundId]);
        $roundData = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($roundData) {
            $planId = $roundData['plan_id'];
            $stmtResult = $dbh->prepare("
                SELECT mr.ma_result_id, mr.equipment_id, mr.result, mr.details, mr.reason, dmp.start_date, mr.performed_date,
                       e.asset_code, e.name AS equipment_name, mp.plan_name
                FROM maintenance_result mr
                INNER JOIN details_maintenance_plans dmp ON mr.details_ma_id = dmp.details_ma_id
                INNER JOIN equipments e ON e.equipment_id = mr.equipment_id
                INNER JOIN maintenance_plans mp ON mp.plan_id = dmp.plan_id
                WHERE mr.details_ma_id = :round_id
                ORDER BY e.name ASC
            ");
            $stmtResult->execute([':round_id' => $roundId]);
            $roundResults = $stmtResult->fetchAll(PDO::FETCH_ASSOC);

            foreach ($roundResults as $row) {
                $equipmentName = ($row['asset_code'] ?? "-") . " - " . ($row['equipment_name'] ?? $row['equipment_id']);
                $result[] = [
                    'name' => $equipmentName,
                    'rounds' => [
                        [
                            'round' => 1,
                            'details_ma_id' => $roundId,
                            'ma_result_id' => $row['ma_result_id'],
                            'plan_name' => $row['plan_name'] ?? "Plan $planId",
                            'status' => $row['result'],
                            'details' => $row['details'] ?: '-',
                            'reason' => $row['reason'] ?: '-',
                            'start_date' => $row['start_date'] ?: '-',
                            'performed_date' => $row['performed_date'] ?: '-'
                        ]
                    ]
                ];
            }
        }
    }

    echo json_encode([
        'status' => 'success',
        'data' => $result
    ]);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
?>