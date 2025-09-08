<?php
include "../config/jwt.php"; 
include "../config/db.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(["message" => "Method Not Allowed"]);
    exit;
}

$cal_result_id = isset($_GET['cal_result_id']) ? intval($_GET['cal_result_id']) : 0;

if (!$cal_result_id) {
    http_response_code(400);
    echo json_encode(["message" => "Missing cal_result_id"]);
    exit;
}

// Query ข้อมูล
$sql = "
SELECT cr.cal_result_id, cr.details_cal_id, cr.equipment_id, cr.user_id, cr.performed_date, cr.result AS status, cr.remarks AS detail, cr.reason, 
       cp.plan_id, cp.cost_type, cp.frequency, cp.frequency_number, cp.frequency_unit, cp.start_date, cp.end_date, cp.price AS total_cost,
       e.name AS equipment_name, u.full_name AS user_name, c.name AS company_name
FROM calibration_result cr
JOIN details_calibration_plans dcp ON cr.details_cal_id = dcp.details_cal_id
JOIN calibration_plans cp ON dcp.plan_id = cp.plan_id
JOIN equipments e ON cr.equipment_id = e.equipment_id
JOIN users u ON cr.user_id = u.ID
JOIN companies c ON cp.company_id = c.company_id
WHERE cr.cal_result_id = ?
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $cal_result_id);
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_assoc();

if (!$data) {
    http_response_code(404);
    echo json_encode(["message" => "Result not found"]);
    exit;
}

// ส่งข้อมูลกลับ
echo json_encode([
    "plan_id" => $data['plan_id'],
    "equipment_id" => $data['equipment_id'],
    "name" => $data['equipment_name'],
    "unitLabel" => $data['cost_type'] === "รวมตลอดทั้งสัญญา" ? "ภายนอก" : "ภายใน",
    "frequency" => $data['frequency'] ?: "{$data['frequency_number']} " . ($data['frequency_unit'] == 2 ? "เดือน" : "วัน"),
    "user_name" => $data['user_name'],
    "company_name" => $data['company_name'],
    "total_cost" => $data['total_cost'],
    "start_date" => $data['start_date'],
    "end_date" => $data['end_date'],
    "status" => $data['status'],
    "performed_date" => $data['performed_date'],
    "remark" => $data['detail'],
    "detail" => $data['detail'] ?? "",
    "file_name" => "ไม่พบไฟล์" // คุณสามารถ join กับ table file_cal_result เพื่อดึงชื่อไฟล์ได้
]);
?>
