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

    // Query หลัก + subquery ดึงไฟล์
    $stmt = $dbh->prepare("
        SELECT mr.ma_result_id, mr.details_ma_id, mr.equipment_id, mr.user_id, mr.performed_date, mr.result AS status, mr.details, mr.reason, mr.send_repair,
               mp.plan_id, mp.plan_name, mp.cost_type, mp.frequency_number, mp.frequency_unit, mp.start_date, mp.end_date, mp.price AS total_cost,
               e.name AS equipment_name, e.asset_code ,
               u.full_name AS user_name, c.name AS company_name,
               mp.type_ma,
               f.files
        FROM maintenance_result mr
        LEFT JOIN details_maintenance_plans dmp ON mr.details_ma_id = dmp.details_ma_id
        LEFT JOIN maintenance_plans mp ON dmp.plan_id = mp.plan_id
        LEFT JOIN equipments e ON mr.equipment_id = e.equipment_id
        LEFT JOIN users u ON mr.user_id = u.ID
        LEFT JOIN companies c ON mp.company_id = c.company_id
        LEFT JOIN (
            SELECT ma_result_id,
                   JSON_ARRAYAGG(JSON_OBJECT(
                        'id', file_ma_result_id,
                       'name', file_ma_name,
                       'url', file_ma_url,
                       'type', ma_type_name
                   )) AS files
            FROM file_ma_result
            GROUP BY ma_result_id
        ) f ON mr.ma_result_id = f.ma_result_id
        WHERE mr.ma_result_id = ?
    ");

    $stmt->execute([$ma_result_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception("ไม่พบผลการบำรุงรักษา");
    }

    // แปลงไฟล์จาก JSON string เป็น array
    $files = !empty($row['files']) ? json_decode($row['files'], true) : [];

    echo json_encode([
        "status" => "success",
        "plan_id" => $row['plan_id'],
        "plan_name" => $row['plan_name'],
        "details_ma_id" => $row['details_ma_id'],
        "equipment_id" => $row['equipment_id'],
        "asset_code" => $row['asset_code'],
        "equipment_name" => $row['equipment_name'],
        "type_ma" => $row['type_ma'],              // ประเภทผู้ทำ MA (ภายใน/ภายนอก)
        "cost_type" => $row['cost_type'],          // วิธีคิดค่าใช้จ่าย (รวมตลอดสัญญา / ตามรอบ)
        "frequency" => "{$row['frequency_number']} " . ($row['frequency_unit'] == 2 ? "เดือน" : "วัน"),
        "user_name" => $row['user_name'],
        "company_name" => $row['company_name'],
        "total_cost" => $row['total_cost'],
        "start_date" => $row['start_date'],
        "end_date" => $row['end_date'],
        "status_result" => $row['status'],
        "performed_date" => $row['performed_date'],
        "details" => $row['details'],
        "reason" => $row['reason'],
        "send_repair" => $row['send_repair'],
        "files" => $files
    ]);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
