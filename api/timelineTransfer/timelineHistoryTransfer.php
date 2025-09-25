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

$historyTransferId = isset($_GET['history_transfer_id']) ? intval($_GET['history_transfer_id']) : 0;

if ($historyTransferId <= 0) {
    echo json_encode(["error" => "กรุณาระบุ history_transfer_id"]);
    exit;
}

try {
    // หา equipment_id
    $stmt = $dbh->prepare("SELECT equipment_id FROM history_transfer WHERE history_transfer_id = ?");
    $stmt->execute([$historyTransferId]);
    $equip = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$equip) {
        echo json_encode(["error" => "ไม่พบข้อมูล"]);
        exit;
    }

    $equipmentId = $equip['equipment_id'];

    // ดึง timeline
    $query = "
        SELECT 
            ht.history_transfer_id,
            ht.transfer_type,
            ht.transfer_date,
            ht.returned_date,
            ht.status_transfer,
            ht.transfer_user_id,
            ht.recipient_user_id,
            ht.now_equip_location_department_id,
            tu.full_name AS transfer_user_name,
            ru.full_name AS recipient_user_name,
            nd.department_name AS now_location_name
        FROM history_transfer ht
        LEFT JOIN users tu ON ht.transfer_user_id = tu.ID
        LEFT JOIN users ru ON ht.recipient_user_id = ru.ID
        LEFT JOIN departments nd ON ht.now_equip_location_department_id = nd.department_id
        WHERE ht.equipment_id = :equipment_id
          AND ht.history_transfer_id <= :history_id
        ORDER BY ht.history_transfer_id DESC
    ";

    $stmt = $dbh->prepare($query);
    $stmt->execute([
        ":equipment_id" => $equipmentId,
        ":history_id" => $historyTransferId
    ]);

    $timeline = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $transferUser = $row['transfer_user_name'] 
            ? $row['transfer_user_name'] 
            : "-";

        $recipientUser = $row['recipient_user_name'] 
            ? $row['recipient_user_name'] 
            : "-";

        $timeline[] = [
            "history_transfer_id" => $row['history_transfer_id'],
            "ประเภทการโอนย้าย"   => $row['transfer_type'] ?? "-",
            "วันที่โอนย้าย"       => $row['transfer_date'] ? date("d/m/Y", strtotime($row['transfer_date'])) : "-",
            "ผู้โอนย้าย"          => $transferUser,
            "ผู้รับโอน"           => $recipientUser,
            "สถานที่ติดตั้ง"       => $row['now_location_name'] ?? "-",
            "สถานะ"               => $row['status_transfer'] == 0 ? "ยังไม่คืน" : "โอนคืนแล้ว"
        ];
    }

    // ดึงรายละเอียดอุปกรณ์
    $equipQuery = "
        SELECT 
            e.equipment_id,
            e.name,
            e.asset_code,
            d.department_name AS current_department
        FROM equipments e
        LEFT JOIN equipment_subcategories es ON e.subcategory_id = es.subcategory_id
        LEFT JOIN departments d ON e.location_department_id = d.department_id
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
