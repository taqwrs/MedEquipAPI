<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$ma_result_id = isset($_GET['ma_result_id']) ? intval($_GET['ma_result_id']) : 0;

try {
    if (!$ma_result_id) {
        throw new Exception("Missing ma_result_id");
    }

    $stmt = $dbh->prepare("
    SELECT mr.ma_result_id, mr.details_ma_id, mr.equipment_id, mr.user_id, mr.performed_date, mr.result AS status, mr.details, mr.reason, 
           cp.plan_id, cp.cost_type, cp.frequency_number, cp.frequency_unit, cp.start_date, cp.end_date, cp.price AS total_cost,
           e.name AS equipment_name, u.full_name AS user_name, c.name AS company_name
    FROM maintenance_result mr
    LEFT JOIN details_maintenance_plans dcp ON mr.details_ma_id = dcp.details_ma_id
    LEFT JOIN maintenance_plans cp ON dcp.plan_id = cp.plan_id
    LEFT JOIN equipments e ON mr.equipment_id = e.equipment_id
    LEFT JOIN users u ON mr.user_id = u.user_id
    LEFT JOIN companies c ON cp.company_id = c.company_id
    WHERE mr.ma_result_id = ?
");

    $stmt->execute([$ma_result_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        throw new Exception("ไม่พบผลการบำรุงรักษา");
    }

    echo json_encode([
        "status" => "success",
        "plan_id" => $data['plan_id'],
        "details_ma_id" => $data['details_ma_id'],
        "equipment_id" => $data['equipment_id'],
        "equipment_name" => $data['equipment_name'],
        "unitLabel" => $data['cost_type'] === "รวมตลอดทั้งสัญญา" ? "ภายนอก" : "ภายใน",
        "frequency" => "{$data['frequency_number']} " . ($data['frequency_unit'] == 2 ? "เดือน" : "วัน"),
        "user_name" => $data['user_name'],
        "company_name" => $data['company_name'],
        "total_cost" => $data['total_cost'],
        "start_date" => $data['start_date'],
        "end_date" => $data['end_date'],
        "status_result" => $data['status'],
        "performed_date" => $data['performed_date'],
        "details" => $data['details'],
        "reason" => $data['reason'],
        "file_name" => "ไม่พบไฟล์"
    ]);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
