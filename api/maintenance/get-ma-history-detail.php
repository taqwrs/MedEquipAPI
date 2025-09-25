<?php
include "../config/jwt.php"; 

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$cal_result_id = isset($_GET['cal_result_id']) ? intval($_GET['cal_result_id']) : 0;

try {
    if (!$cal_result_id) {
        throw new Exception("Missing cal_result_id");
    }

    $stmt = $dbh->prepare("
        SELECT cr.cal_result_id, cr.details_cal_id, cr.equipment_id, cr.user_id, cr.performed_date, cr.result AS status, cr.remarks, cr.reason, 
               cp.plan_id, cp.cost_type, cp.frequency_number, cp.frequency_unit, cp.start_date, cp.end_date, cp.price AS total_cost,
               e.name AS equipment_name, u.full_name AS user_name, c.name AS company_name
        FROM calibration_result cr
        JOIN details_calibration_plans dcp ON cr.details_cal_id = dcp.details_cal_id
        JOIN calibration_plans cp ON dcp.plan_id = cp.plan_id
        JOIN equipments e ON cr.equipment_id = e.equipment_id
        JOIN users u ON cr.user_id = u.user_id
        JOIN companies c ON cp.company_id = c.company_id
        WHERE cr.cal_result_id = ?
    ");
    $stmt->execute([$cal_result_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        throw new Exception("ไม่พบผลการบำรุงรักษา");
    }

    echo json_encode([
        "status" => "success",
        "plan_id" => $data['plan_id'],
        "details_cal_id" => $data['details_cal_id'],
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
        "remark" => $data['remarks'],
        "reason" => $data['reason'],
        "file_name" => "ไม่พบไฟล์"
    ]);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
