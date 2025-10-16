<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$planId = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : null;

try {
    if (!$planId) throw new Exception("plan_id is required");

    // แผน
    $stmtPlan = $dbh->prepare("SELECT * FROM maintenance_plans WHERE plan_id = :plan_id");
    $stmtPlan->execute([':plan_id' => $planId]);
    $plan = $stmtPlan->fetch(PDO::FETCH_ASSOC);

    if (!$plan) throw new Exception("Plan not found");

    // อุปกรณ์ของแผน
    $stmtEquipments = $dbh->prepare("
        SELECT e.equipment_id, e.name AS equipment_name, e.asset_code
        FROM equipments e
        JOIN plan_ma_equipments pe ON e.equipment_id = pe.equipment_id
        WHERE pe.plan_id = :plan_id
    ");
    $stmtEquipments->execute([':plan_id' => $planId]);
    $equipments = $stmtEquipments->fetchAll(PDO::FETCH_ASSOC);

    // รอบทั้งหมด
    $stmtRounds = $dbh->prepare("
        SELECT details_ma_id, start_date
        FROM details_maintenance_plans
        WHERE plan_id = :plan_id
        ORDER BY details_ma_id ASC
    ");
    $stmtRounds->execute([':plan_id' => $planId]);
    $allRounds = $stmtRounds->fetchAll(PDO::FETCH_ASSOC);

    $rounds = [];
    foreach ($allRounds as $index => $round) {
        $roundData = [
            'round_id' => $round['details_ma_id'],
            'round_number' => $index + 1,
            'start_date' => $round['start_date'],
            'equipments' => []
        ];

        foreach ($equipments as $eq) {
            // ตรวจสอบผลบันทึก
            $stmtResult = $dbh->prepare("
                SELECT performed_date
                FROM maintenance_result
                WHERE details_ma_id = :details_ma_id AND equipment_id = :equipment_id
                LIMIT 1
            ");
            $stmtResult->execute([
                ':details_ma_id' => $round['details_ma_id'],
                ':equipment_id' => $eq['equipment_id']
            ]);
            $performedDate = $stmtResult->fetchColumn();

            $roundData['equipments'][] = [
                'equipment_id' => $eq['equipment_id'],
                'equipment_name' => $eq['equipment_name'],
                'asset_code' => $eq['asset_code'],
                'status' => $performedDate ? 'บันทึกแล้ว' : 'รอ',
                'performed_date' => $performedDate ?: ''
            ];
        }

        $rounds[] = $roundData;
    }

    echo json_encode(['status' => 'success', 'data' => [
        'plan' => $plan,
        'rounds' => $rounds
    ]]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
