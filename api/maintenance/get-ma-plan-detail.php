<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$plan_id = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : 0;

try {

    // ดึงแผนบำรุงรักษาพร้อม user_name
    $stmt = $dbh->prepare("
        SELECT mp.*, u.full_name AS user_name
        FROM maintenance_plans mp
        LEFT JOIN users u ON mp.user_id = u.id
        WHERE mp.plan_id = ?
    ");
    $stmt->execute([$plan_id]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$plan)
        throw new Exception("ไม่พบแผนบำรุงรักษา");

    // ดึงรายละเอียดรอบบำรุงรักษา
    $stmt = $dbh->prepare("SELECT details_ma_id, start_date FROM details_maintenance_plans WHERE plan_id = ?");
    $stmt->execute([$plan_id]);
    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ดึงอุปกรณ์ในแผนบำรุงรักษา
    $stmt = $dbh->prepare("
        SELECT e.* 
        FROM equipments e
        INNER JOIN plan_ma_equipments pe ON pe.equipment_id = e.equipment_id
        WHERE pe.plan_id = ?
    ");
    $stmt->execute([$plan_id]);
    $equipments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // ดึงไฟล์เอกสาร MA
    $fileStmt = $dbh->prepare("
    SELECT file_ma_id, file_ma_name, file_ma_url, ma_type_name
    FROM file_ma
    WHERE plan_id = ?
");
    $fileStmt->execute([$plan_id]);
    $filesRaw = $fileStmt->fetchAll(PDO::FETCH_ASSOC);

    // map ไฟล์ไปยังรูปแบบ React form
    $filesInfo = [];
    $urls = [];
    foreach ($filesRaw as $f) {
        $filesInfo[] = [
            "file_ma_id" => (int)$f['file_ma_id'],
            "file_ma_name" => $f['file_ma_name'],
            "file_ma_url" => $f['file_ma_url'],
            "ma_type_name" => $f['ma_type_name']
        ];
        $urls[] = $f['file_ma_url'];
    }

    echo json_encode([
        "status" => "success",
        "plan" => [
            "basicInfo" => [
                "plan_name" => $plan['plan_name'],
                "frequency_number" => $plan['frequency_number'],
                "frequency_unit" => $plan['frequency_unit'],
                "type_ma" => $plan['type_ma'],
                "frequency_type" => $plan['frequency_type'],
                "user_id" => $plan['user_id']
            ],
            "group_user_id" => $plan['group_user_id'],
            "company_id" => $plan['company_id'] ?? null,
            "contractNumber" => $plan['contract'] ?? "",
            "costInfo" => [
                "price" => $plan['price'],
                "cost_type" => $plan['cost_type']
            ],
            "dateInfo" => [
                "start_date" => $plan['start_date'],
                "end_date" => $plan['end_date'],
                "start_waranty" => $plan['start_waranty'] ?? ""
            ],
            "type_ma" => $plan['type_ma'],
            "urls" => $urls,
            "filesInfo" => $filesInfo
        ],
        "details" => $details,
        "equipments" => $equipments
    ]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
