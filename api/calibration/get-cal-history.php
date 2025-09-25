<?php
include "../config/jwt.php"; 

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$equipmentId = isset($_GET['equipment_id']) ? intval($_GET['equipment_id']) : null;
$roundId = isset($_GET['round_id']) ? intval($_GET['round_id']) : null;
$planId = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : null;
$viewType = isset($_GET['viewType']) ? $_GET['viewType'] : null;

try {

    $result = [];

    if ($viewType === "allRoundsOfEquipment" && $equipmentId && $planId) {

        $stmtEq = $dbh->prepare("SELECT asset_code, name FROM equipments WHERE equipment_id = :equipment_id");
        $stmtEq->execute([':equipment_id' => $equipmentId]);
        $eqData = $stmtEq->fetch(PDO::FETCH_ASSOC);
        $equipmentName = ($eqData['asset_code'] ?? "-") . " - " . ($eqData['name'] ?? $equipmentId);


        $stmtDetails = $dbh->prepare("
            SELECT dcp.details_cal_id, cr.cal_result_id, cr.result, cr.remarks, dcp.start_date, cr.performed_date, cp.plan_name
            FROM details_calibration_plans dcp
            INNER JOIN calibration_result cr 
                ON cr.details_cal_id = dcp.details_cal_id
            INNER JOIN calibration_plans cp
                ON cp.plan_id = dcp.plan_id
            WHERE dcp.plan_id = :plan_id AND cr.equipment_id = :equipment_id
            ORDER BY dcp.details_cal_id ASC
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
                    'details_cal_id' => $row['details_cal_id'],
                    'cal_result_id' => $row['cal_result_id'],
                    'plan_name' => $row['plan_name'] ?? "-",
                    'status' => $row['result'],
                    'remark' => $row['remarks'] ?: '-',
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

        $stmtCheck = $dbh->prepare("
            SELECT dcp.plan_id
            FROM details_calibration_plans dcp
            WHERE dcp.details_cal_id = :round_id
        ");
        $stmtCheck->execute([':round_id' => $roundId]);
        $roundData = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($roundData) {
            $planId = $roundData['plan_id'];
            $stmtResult = $dbh->prepare("
                SELECT cr.cal_result_id, cr.equipment_id, cr.result, cr.remarks, dcp.start_date, cr.performed_date,
                       e.asset_code, e.name AS equipment_name, cp.plan_name
                FROM calibration_result cr
                INNER JOIN details_calibration_plans dcp ON cr.details_cal_id = dcp.details_cal_id
                INNER JOIN equipments e ON e.equipment_id = cr.equipment_id
                INNER JOIN calibration_plans cp ON cp.plan_id = dcp.plan_id
                WHERE cr.details_cal_id = :round_id
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
                            'details_cal_id' => $roundId,
                            'cal_result_id' => $row['cal_result_id'],
                            'plan_name' => $row['plan_name'] ?? "Plan $planId",
                            'status' => $row['result'],
                            'remark' => $row['remarks'] ?: '-',
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
