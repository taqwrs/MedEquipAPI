<?php
include "../config/jwt.php"; 

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];

$equipmentId = isset($_GET['equipment_id']) ? intval($_GET['equipment_id']) : null;
$roundId = isset($_GET['round_id']) ? intval($_GET['round_id']) : null;
$viewType = isset($_GET['viewType']) ? $_GET['viewType'] : null;

try {
    if (!$dbh) {
        throw new Exception("Database connection failed");
    }

    $result = [];

    if ($viewType === "allRoundsOfEquipment" && $equipmentId) {
        $stmtPlans = $dbh->prepare("
            SELECT cp.plan_id, cp.plan_name
            FROM calibration_plans cp
            JOIN plan_equipments pe ON cp.plan_id = pe.plan_id
            WHERE pe.equipment_id = :equipment_id
        ");
        $stmtPlans->execute([':equipment_id' => $equipmentId]);
        $plans = $stmtPlans->fetchAll(PDO::FETCH_ASSOC);

        $stmtEq = $dbh->prepare("SELECT name FROM equipments WHERE equipment_id = :equipment_id");
        $stmtEq->execute([':equipment_id' => $equipmentId]);
        $eqData = $stmtEq->fetch(PDO::FETCH_ASSOC);
        $equipmentName = $eqData['name'] ?? "เครื่องหมายเลข $equipmentId";

        foreach ($plans as $plan) {
            $stmtDetails = $dbh->prepare("
                SELECT dcp.details_cal_id, cr.result, cr.remarks,cr.performed_date
                FROM details_calibration_plans dcp
                LEFT JOIN calibration_result cr 
                    ON cr.details_cal_id = dcp.details_cal_id
                WHERE dcp.plan_id = :plan_id
                ORDER BY dcp.details_cal_id ASC
            ");
            $stmtDetails->execute([
                ':plan_id' => $plan['plan_id']
            ]);
            $details = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);

            $rounds = [];
            foreach ($details as $idx => $row) {
                $rounds[] = [
                    'round' => $idx + 1,
                    'details_cal_id' => $row['details_cal_id'],
                    'plan_name' => $plan['plan_name'],
                    'status' => $row['result'] ?: 'รอดำเนินการ',
                    'remark' => $row['remarks'] ?: '-',
                    'performed_date' => $row['performed_date'] ?: '-'
                ];
            }

            $result[] = [
                'name' => $equipmentName,
                'rounds' => $rounds
            ];
        }

    } elseif ($viewType === "allEquipments" && $roundId) {
        $stmtPlan = $dbh->prepare("
            SELECT plan_id
            FROM details_calibration_plans
            WHERE details_cal_id = :round_id
        ");
        $stmtPlan->execute([':round_id' => $roundId]);
        $roundData = $stmtPlan->fetch(PDO::FETCH_ASSOC);

        if ($roundData) {
            $planId = $roundData['plan_id'];
            $stmtPlanName = $dbh->prepare("SELECT plan_name FROM calibration_plans WHERE plan_id = :plan_id");
            $stmtPlanName->execute([':plan_id' => $planId]);
            $planName = $stmtPlanName->fetchColumn() ?: "Plan $planId";

            $stmtResult = $dbh->prepare("
                SELECT result, remarks,performed_date
                FROM calibration_result
                WHERE details_cal_id = :round_id
                LIMIT 1
            ");
            $stmtResult->execute([':round_id' => $roundId]);
            $roundResult = $stmtResult->fetch(PDO::FETCH_ASSOC);

            $status = $roundResult['result'] ?? 'ผ่าน';
            $remark = $roundResult['remarks'] ?? '-';
            $performed_date = $roundResult['performed_date'] ?? '-';

            $stmtEquip = $dbh->prepare("
                SELECT e.equipment_id, e.name AS equipment_name
                FROM plan_equipments pe
                JOIN equipments e ON pe.equipment_id = e.equipment_id
                WHERE pe.plan_id = :plan_id
            ");
            $stmtEquip->execute([':plan_id' => $planId]);
            $equipRows = $stmtEquip->fetchAll(PDO::FETCH_ASSOC);

            foreach ($equipRows as $eq) {
                $result[] = [
                    'name' => $eq['equipment_name'],
                    'rounds' => [
                        [
                            'round' => 1,
                            'details_cal_id' => $roundId,
                            'plan_name' => $planName,
                            'status' => $status,
                            'remark' => $remark,
                            'performed_date' => $performed_date
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
