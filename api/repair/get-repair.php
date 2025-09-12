<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method required"]);
    exit;
}

try {
    $query = "SELECT 
    r.repair_id,
    r.equipment_id,
    e.name AS equipment_name,
    e.asset_code,
    r.title,
    r.remark,
    r.request_date,
    r.location,
    r.status,
    u.full_name AS reporter,
    rt.name_type AS repair_type,
    gu.group_name
FROM repair r
LEFT JOIN equipments e ON r.equipment_id = e.equipment_id
LEFT JOIN users u ON r.user_id = u.user_id
LEFT JOIN repair_type rt ON r.repair_type_id = rt.repair_type_id
LEFT JOIN group_user gu ON rt.group_user_id = gu.group_user_id
WHERE r.status != 'เสร็จสิ้น'
ORDER BY r.request_date DESC";

    $stmt = $dbh->query($query);
    $repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $repairCount = [];
    foreach ($repairs as $row) {
        $equipId = $row['equipment_id'];
        if (!isset($repairCount[$equipId])) {
            $repairCount[$equipId] = 0;
        }
        $repairCount[$equipId]++;
    }

    foreach ($repairs as &$row) {
        $row['repair_count'] = $repairCount[$row['equipment_id']];
    }

    echo json_encode([
        "success" => true,
        "data" => $repairs
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
