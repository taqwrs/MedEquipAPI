<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit;
}

$equipmentId = isset($_GET['equipment_id']) ? intval($_GET['equipment_id']) : 0;
if ($equipmentId <= 0) {
    echo json_encode(["error" => "กรุณาระบุ equipment_id"]);
    exit;
}

try {
    $query = "
        SELECT 
            ht.history_transfer_id,
            ht.transfer_type,
            ht.transfer_date,
            ht.returned_date,
            ht.status_transfer,
            ht.transfer_user_id,
            ht.recipient_user_id,
            ht.old_location_department_id,
            ht.now_equip_location_department_id,
            ht.old_subcategory_id,
            ht.new_subcategory_id,
            tu.full_name AS transfer_user_name,
            td.department_name AS transfer_user_department_name,
            ru.full_name AS recipient_user_name,
            rd.department_name AS recipient_user_department_name,
            od.department_name AS old_location_name,
            nd.department_name AS now_location_name,
            os.name AS old_subcategory_name,
            ns.name AS new_subcategory_name
        FROM history_transfer ht
        LEFT JOIN users tu ON ht.transfer_user_id = tu.ID
        LEFT JOIN departments td ON tu.department_id = td.department_id
        LEFT JOIN users ru ON ht.recipient_user_id = ru.ID
        LEFT JOIN departments rd ON ru.department_id = rd.department_id
        LEFT JOIN departments od ON ht.old_location_department_id = od.department_id
        LEFT JOIN departments nd ON ht.now_equip_location_department_id = nd.department_id
        LEFT JOIN equipment_subcategories os ON ht.old_subcategory_id = os.subcategory_id
        LEFT JOIN equipment_subcategories ns ON ht.new_subcategory_id = ns.subcategory_id
        WHERE ht.equipment_id = :equipment_id
        ORDER BY ht.history_transfer_id DESC
    ";

    $stmt = $dbh->prepare($query);
    $stmt->execute([":equipment_id" => $equipmentId]);

    $timeline = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $timeline[] = [
            "history_transfer_id" => $row['history_transfer_id'],
            "ประเภทการโอนย้าย" => $row['transfer_type'] ?? "-",
            "วันที่โอนย้าย" => $row['transfer_date'] ? date("d/m/Y", strtotime($row['transfer_date'])) : "-",
            "ผู้โอนย้าย" => $row['transfer_user_name']
                ? $row['transfer_user_name'] . " (" . ($row['transfer_user_department_name'] ?: "-") . ")"
                : "-",
            "ผู้รับโอน" => $row['recipient_user_name']
                ? $row['recipient_user_name'] . " (" . ($row['recipient_user_department_name'] ?: "-") . ")"
                : "-",
            "สถานที่ติดตั้ง" => $row['now_location_name'] ?: "-",
            "สถานะ" => $row['status_transfer'] == 0 ? "ยังไม่คืน" : "โอนคืนแล้ว"
        ];
    }
    $equipQuery = "
        SELECT 
            e.equipment_id,
            e.name,
            e.asset_code,
            d.department_name AS current_department,
            es.name AS subcategory_name
        FROM equipments e
        LEFT JOIN departments d ON e.location_department_id = d.department_id
        LEFT JOIN equipment_subcategories es ON e.subcategory_id = es.subcategory_id
        WHERE e.equipment_id = :equipment_id
    ";
    $stmtEquip = $dbh->prepare($equipQuery);
    $stmtEquip->execute([":equipment_id" => $equipmentId]);
    $equipmentInfo = $stmtEquip->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "equipment" => $equipmentInfo,
        "timeline" => $timeline
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
