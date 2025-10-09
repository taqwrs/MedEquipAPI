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
            ht.updated_at,
            ht.transfer_date,
            ht.returned_date,
            ht.status_transfer,
            ht.transfer_user_id,
            ht.recipient_user_id,
            ht.old_location_department_id,
            ht.now_equip_location_department_id,
            tu.full_name AS transfer_user_name,
            tud.department_name AS transfer_user_department,
            ru.full_name AS recipient_user_name,
            rud.department_name AS recipient_user_department,
            od.department_name AS old_location_name,
            nd.department_name AS now_location_name
        FROM history_transfer ht
        LEFT JOIN users tu ON ht.transfer_user_id = tu.ID
        LEFT JOIN departments tud ON tu.department_id = tud.department_id
        LEFT JOIN users ru ON ht.recipient_user_id = ru.ID
        LEFT JOIN departments rud ON ru.department_id = rud.department_id
        LEFT JOIN departments od ON ht.old_location_department_id = od.department_id
        LEFT JOIN departments nd ON ht.now_equip_location_department_id = nd.department_id
        WHERE ht.equipment_id = :equipment_id
        ORDER BY ht.history_transfer_id DESC
    ";

    $stmt = $dbh->prepare($query);
    $stmt->execute([":equipment_id" => $equipmentId]);

    $timeline = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $transferUser = $row['transfer_user_name'] ?: "-";
        $recipientUser = $row['recipient_user_name'] ?: "-";

        $status = "-";
        if ($row['transfer_type'] === "โอนย้ายถาวร" && $row['status_transfer'] == 1) {
            $status = "ไม่ต้องคืน";
        } elseif ($row['transfer_type'] === "โอนย้ายชั่วคราว" && $row['status_transfer'] == 0) {
            $status = "ยังไม่คืน";
        } elseif ($row['transfer_type'] === "โอนย้ายชั่วคราว" && $row['status_transfer'] == 1) {
            $status = "คืนแล้ว";
        }

        $timeline[] = [
            "history_transfer_id" => $row['history_transfer_id'],
            "ประเภทการโอนย้าย" => $row['transfer_type'] ?? "-",
            "วันที่โอนย้าย" => $row['updated_at'] ? date("d/m/Y H:i:s", strtotime($row['updated_at'])) : "-",
            "ผู้โอนย้าย" => $transferUser,
            "แผนกผู้โอน" => $row['transfer_user_department'] ?? "-",
            "ผู้รับโอน" => $recipientUser,
            "แผนกผู้รับ" => $row['recipient_user_department'] ?? "-",
            "สถานที่ติดตั้ง" => $row['now_location_name'] ?? "-",
            "สถานะ" => $status
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
