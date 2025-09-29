<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$planId = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : null;

try {
    if (!$dbh) {
        throw new Exception("Database connection failed");
    }

    $result = [];

    if ($planId) {
        // ดึงข้อมูลแผน
        $stmtPlan = $dbh->prepare("
            SELECT plan_id, plan_name, frequency_number, frequency_unit, start_date, end_date
            FROM maintenance_plans
            WHERE plan_id = :plan_id
        ");
        $stmtPlan->execute([':plan_id' => $planId]);
        $plan = $stmtPlan->fetch(PDO::FETCH_ASSOC);

        if ($plan) {
            // ดึงอุปกรณ์ที่เชื่อมโยงกับแผน
            $stmtEquipments = $dbh->prepare("
                SELECT e.equipment_id, e.name AS equipment_name, e.asset_code
                FROM equipments e
                JOIN plan_ma_equipments pe ON e.equipment_id = pe.equipment_id
                WHERE pe.plan_id = :plan_id
            ");
            $stmtEquipments->execute([':plan_id' => $planId]);
            $equipments = $stmtEquipments->fetchAll(PDO::FETCH_ASSOC);

            // ดึงรอบการบำรุงรักษาของแผน
            $stmtRounds = $dbh->prepare("
                SELECT details_ma_id, start_date
                FROM details_maintenance_plans
                WHERE plan_id = :plan_id
                ORDER BY details_ma_id ASC
            ");
            $stmtRounds->execute([':plan_id' => $planId]);
            $rounds = $stmtRounds->fetchAll(PDO::FETCH_ASSOC);

            // สร้างข้อมูลรอบที่ยังไม่มีการบันทึกผล
            $pendingRounds = [];
            foreach ($rounds as $round) {
                $roundData = [
                    'round_id' => $round['details_ma_id'],
                    'start_date' => $round['start_date'],
                    'equipments' => []
                ];

                foreach ($equipments as $equipment) {
                    // ตรวจสอบว่ามีการบันทึกผลสำหรับอุปกรณ์นี้ในรอบนี้หรือไม่
                    $stmtResult = $dbh->prepare("
                        SELECT COUNT(*) as count
                        FROM maintenance_result
                        WHERE details_ma_id = :details_ma_id
                        AND equipment_id = :equipment_id
                    ");
                    $stmtResult->execute([
                        ':details_ma_id' => $round['details_ma_id'],
                        ':equipment_id' => $equipment['equipment_id']
                    ]);
                    $hasResult = $stmtResult->fetchColumn() > 0;

                    if (!$hasResult) {
                        $roundData['equipments'][] = [
                            'equipment_id' => $equipment['equipment_id'],
                            'equipment_name' => $equipment['equipment_name'],
                            'asset_code' => $equipment['asset_code'],
                            'status' => 'รอการบันทึก'
                        ];
                    }
                }

                if (!empty($roundData['equipments'])) {
                    $pendingRounds[] = $roundData;
                }
            }

            $result = [
                'plan' => $plan,
                'pending_rounds' => $pendingRounds
            ];
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
