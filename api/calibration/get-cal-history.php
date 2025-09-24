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

    // แสดงรอบทั้งหมดของอุปกรณ์
    if ($viewType === "allRoundsOfEquipment" && $equipmentId) {

        // ดึงแผนสอบเทียบของอุปกรณ์
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
        $equipmentName = $eqData['name'] ?? $equipmentId;

        foreach ($plans as $plan) {
            // ดึงผลลัพธ์พร้อม start_date และ performed_date
            $stmtDetails = $dbh->prepare("
                SELECT dcp.details_cal_id, cr.cal_result_id, cr.result, cr.remarks, dcp.start_date, cr.performed_date
                FROM details_calibration_plans dcp
                INNER JOIN calibration_result cr 
                    ON cr.details_cal_id = dcp.details_cal_id
                WHERE dcp.plan_id = :plan_id
                ORDER BY dcp.details_cal_id ASC
            ");
            $stmtDetails->execute([':plan_id' => $plan['plan_id']]);
            $details = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);

            if (!$details) continue;

            $rounds = [];
            foreach ($details as $idx => $row) {
                $rounds[] = [
                    'round' => $idx + 1,
                    'details_cal_id' => $row['details_cal_id'],
                    'cal_result_id' => $row['cal_result_id'],
                    'plan_name' => $plan['plan_name'],
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

    // แสดงอุปกรณ์ทั้งหมดของรอบ
    } elseif ($viewType === "allEquipments" && $roundId) {

        $stmtPlan = $dbh->prepare("
            SELECT dcp.plan_id
            FROM details_calibration_plans dcp
            WHERE dcp.details_cal_id = :round_id
        ");
        $stmtPlan->execute([':round_id' => $roundId]);
        $roundData = $stmtPlan->fetch(PDO::FETCH_ASSOC);

        if ($roundData) {
            $planId = $roundData['plan_id'];

            $stmtPlanName = $dbh->prepare("SELECT plan_name FROM calibration_plans WHERE plan_id = :plan_id");
            $stmtPlanName->execute([':plan_id' => $planId]);
            $planName = $stmtPlanName->fetchColumn() ?: "Plan $planId";

            // ดึงผลลัพธ์ของรอบพร้อม start_date และ performed_date
            $stmtResult = $dbh->prepare("
                SELECT cr.cal_result_id, cr.equipment_id, cr.result, cr.remarks, dcp.start_date, cr.performed_date, e.name AS equipment_name
                FROM calibration_result cr
                INNER JOIN details_calibration_plans dcp ON cr.details_cal_id = dcp.details_cal_id
                INNER JOIN equipments e ON e.equipment_id = cr.equipment_id
                WHERE cr.details_cal_id = :round_id
            ");
            $stmtResult->execute([':round_id' => $roundId]);
            $roundResults = $stmtResult->fetchAll(PDO::FETCH_ASSOC);

            foreach ($roundResults as $row) {
                $result[] = [
                    'name' => $row['equipment_name'],
                    'rounds' => [
                        [
                            'round' => 1,
                            'details_cal_id' => $roundId,
                            'cal_result_id' => $row['cal_result_id'], 
                            'plan_name' => $planName,
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
