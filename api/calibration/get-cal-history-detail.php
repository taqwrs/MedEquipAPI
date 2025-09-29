<?php
include "../config/jwt.php"; 

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$cal_result_id = isset($_GET['cal_result_id']) ? intval($_GET['cal_result_id']) : 0;


error_log("get-cal-history-detail.php - Received cal_result_id: " . $cal_result_id);

try {
    if (!$cal_result_id) {
        throw new Exception("Missing cal_result_id parameter");
    }

    $stmt = $dbh->prepare("
        SELECT cr.cal_result_id,
               cr.details_cal_id,
               cr.equipment_id,
               cr.user_id,
               cr.performed_date,
               cr.result AS status,
               cr.remarks,
               cr.reason,
               cp.plan_id,
               cp.cost_type,
               cp.frequency_number,
               cp.frequency_unit,
               cp.start_date,
               cp.end_date,
               cp.price AS total_cost,
               e.name AS equipment_name,
               u.full_name AS user_name,
               c.name AS company_name
        FROM calibration_result cr
        LEFT JOIN details_calibration_plans dcp 
            ON cr.details_cal_id = dcp.details_cal_id
        LEFT JOIN calibration_plans cp 
            ON dcp.plan_id = cp.plan_id
        LEFT JOIN equipments e 
            ON cr.equipment_id = e.equipment_id
        LEFT JOIN users u 
            ON cr.user_id = u.user_id
        LEFT JOIN companies c 
            ON cp.company_id = c.company_id
        WHERE cr.cal_result_id = ?
    ");
    $stmt->execute([$cal_result_id]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    error_log("Query result: " . ($data ? "Found" : "Not found"));

    if (!$data) {
        throw new Exception("ไม่พบผลการสอบเทียบสำหรับ cal_result_id = $cal_result_id");
    }

    // ดึงไฟล์ที่เกี่ยวข้องจาก file_cal_result
    $fileStmt = $dbh->prepare("
        SELECT file_cal_result_id, file_cal_name, file_cal_url, cal_type_name
        FROM file_cal_result 
        WHERE cal_result_id = ?
        ORDER BY file_cal_result_id ASC
    ");
    $fileStmt->execute([$cal_result_id]);
    $files = $fileStmt->fetchAll(PDO::FETCH_ASSOC);

    error_log("Found " . count($files) . " files");

    // จัดรูปแบบไฟล์
    $fileData = [];
    $fileNames = [];
    foreach ($files as $file) {
        $fileData[] = [
            'file_cal_result_id' => $file['file_cal_result_id'],
            'file_name' => $file['file_cal_name'],
            'file_url' => $file['file_cal_url'],
            'cal_type_name' => $file['cal_type_name']
        ];
        $fileNames[] = $file['file_cal_name'];
    }

    $response = [
        "status" => "success",
        "plan_id" => $data['plan_id'],
        "details_cal_id" => $data['details_cal_id'],
        "equipment_id" => $data['equipment_id'],
        "equipment_name" => $data['equipment_name'] ?? "ไม่พบข้อมูล",
        "unitLabel" => $data['cost_type'] === "รวมตลอดทั้งสัญญา" ? "ภายนอก" : "ภายใน",
        "frequency" => "{$data['frequency_number']} " . ($data['frequency_unit'] == 2 ? "เดือน" : "วัน"),
        "user_name" => $data['user_name'] ?? "ไม่พบข้อมูล",
        "company_name" => $data['company_name'] ?? "ไม่พบข้อมูล",
        "total_cost" => $data['total_cost'],
        "start_date" => $data['start_date'],
        "end_date" => $data['end_date'],
        "status_result" => $data['status'],
        "performed_date" => $data['performed_date'],
        "remark" => $data['remarks'],
        "reason" => $data['reason'],
        "files" => $fileData,
        "file_name" => count($fileNames) > 0 ? implode(', ', $fileNames) : "ไม่พบไฟล์",
        "file_count" => count($fileData)
    ];

    error_log("Sending response: " . json_encode($response));
    echo json_encode($response);

} catch (Exception $e) {
    error_log("Exception in get-cal-history-detail.php: " . $e->getMessage());
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
?>
