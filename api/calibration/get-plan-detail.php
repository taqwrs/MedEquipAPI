<?php
include "../config/jwt.php"; 

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$plan_id = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : 0;

try {
    // ================== แผนหลัก ==================
    $stmt = $dbh->prepare("SELECT * FROM calibration_plans WHERE plan_id = ?");
    $stmt->execute([$plan_id]); 
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan) throw new Exception("ไม่พบแผนสอบเทียบ");

    // ================== รอบสอบเทียบ (details) ==================
    $stmt = $dbh->prepare("SELECT details_cal_id, start_date FROM details_calibration_plans WHERE plan_id = ?");
    $stmt->execute([$plan_id]);
    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $detailIds = array_column($details, 'details_cal_id');

    // ================== อุปกรณ์ในแผน ==================
    $stmt = $dbh->prepare("
        SELECT e.* 
        FROM equipments e
        INNER JOIN plan_equipments pe ON pe.equipment_id = e.equipment_id
        WHERE pe.plan_id = ?
    ");
    $stmt->execute([$plan_id]);
    $equipments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ================== ผลสอบเทียบ (results) ==================
    $resultsMap = [];
    if (!empty($detailIds)) {
        $inQuery = implode(',', array_fill(0, count($detailIds), '?'));
        $stmt = $dbh->prepare("
            SELECT cr.*, u.full_name 
            FROM calibration_result cr
            LEFT JOIN users u ON cr.user_id = u.ID
            WHERE cr.details_cal_id IN ($inQuery)
        ");
        $stmt->execute($detailIds);
        $resultsRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($resultsRaw as $r) {
            $resultsMap[$r['details_cal_id']][] = [
                "cal_result_id" => (int)$r['cal_result_id'],
                "equipment_id"  => (int)$r['equipment_id'],
                "user_id"       => (int)$r['user_id'],
                "user_name"     => $r['full_name'] ?? null,
                "performed_date"=> $r['performed_date'],
                "result"        => $r['result'],
                "remarks"       => $r['remarks'],
                "reason"        => $r['reason'],
                "send_repair"   => $r['send_repair'],
            ];
        }
    }

    // map results เข้าไปใน details
    foreach ($details as &$d) {
        $d['results'] = $resultsMap[$d['details_cal_id']] ?? [];
    }

    echo json_encode([
        "status" => "success",
        "plan" => $plan,
        "details" => $details,
        "equipments" => $equipments
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
