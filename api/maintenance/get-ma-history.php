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

        // ดึงแผนบำรุงรักษาของอุปกรณ์
        $stmtPlans = $dbh->prepare("
            SELECT mp.plan_id, mp.plan_name
            FROM maintenance_plans mp
            JOIN plan_ma_equipments pe ON mp.plan_id = pe.plan_id
            WHERE pe.equipment_id = :equipment_id
        ");
        $stmtPlans->execute([':equipment_id' => $equipmentId]);
        $plans = $stmtPlans->fetchAll(PDO::FETCH_ASSOC);

        $stmtEq = $dbh->prepare("SELECT name FROM equipments WHERE equipment_id = :equipment_id");
        $stmtEq->execute([':equipment_id' => $equipmentId]);
        $eqData = $stmtEq->fetch(PDO::FETCH_ASSOC);
        $equipmentName = $eqData['name'] ?? $equipmentId;

        foreach ($plans as $plan) {
            // ดึงผลลัพธ์ของรอบบำรุงรักษา
            $stmtDetails = $dbh->prepare("
                SELECT dmp.details_ma_id, mr.ma_result_id, mr.result, mr.details, mr.reason, dmp.start_date, mr.performed_date
                FROM details_maintenance_plans dmp
                INNER JOIN maintenance_result mr 
                    ON mr.details_ma_id = dmp.details_ma_id
                WHERE dmp.plan_id = :plan_id
                AND mr.equipment_id = :equipment_id
                ORDER BY dmp.details_ma_id ASC
            ");
            $stmtDetails->execute([
                ':plan_id' => $plan['plan_id'],
                ':equipment_id' => $equipmentId
            ]);
            $details = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);

            if (!$details)
                continue;

            $rounds = [];
            foreach ($details as $idx => $row) {
                $rounds[] = [
                    'round' => $idx + 1,
                    'details_ma_id' => $row['details_ma_id'],
                    'ma_result_id' => $row['ma_result_id'],
                    'plan_name' => $plan['plan_name'],
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

        // แสดงอุปกรณ์ทั้งหมดของรอบ
    } elseif ($viewType === "allEquipments" && $roundId) {

        $stmtPlan = $dbh->prepare("
            SELECT dmp.plan_id
            FROM details_maintenance_plans dmp
            WHERE dmp.details_ma_id = :round_id
        ");
        $stmtPlan->execute([':round_id' => $roundId]);
        $roundData = $stmtPlan->fetch(PDO::FETCH_ASSOC);

        if ($roundData) {
            $planId = $roundData['plan_id'];

            $stmtPlanName = $dbh->prepare("SELECT plan_name FROM maintenance_plans WHERE plan_id = :plan_id");
            $stmtPlanName->execute([':plan_id' => $planId]);
            $planName = $stmtPlanName->fetchColumn() ?: "Plan $planId";

            // ดึงผลลัพธ์ของรอบพร้อม start_date และ performed_date
            $stmtResult = $dbh->prepare("
                SELECT mr.ma_result_id, mr.equipment_id, mr.result, mr.details, mr.reason, dmp.start_date, mr.performed_date, e.name AS equipment_name
                FROM maintenance_result mr
                INNER JOIN details_maintenance_plans dmp ON mr.details_ma_id = dmp.details_ma_id
                INNER JOIN equipments e ON e.equipment_id = mr.equipment_id
                WHERE mr.details_ma_id = :round_id
            ");
            $stmtResult->execute([':round_id' => $roundId]);
            $roundResults = $stmtResult->fetchAll(PDO::FETCH_ASSOC);

            foreach ($roundResults as $row) {
                $result[] = [
                    'name' => $row['equipment_name'],
                    'rounds' => [
                        [
                            'round' => 1,
                            'details_ma_id' => $roundId,
                            'ma_result_id' => $row['ma_result_id'],
                            'plan_name' => $planName,
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
