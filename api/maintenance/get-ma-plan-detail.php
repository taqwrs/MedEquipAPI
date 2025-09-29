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

    echo json_encode([
        "status" => "success",
        "plan" => $plan,
        "details" => $details,
        "equipments" => $equipments
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
