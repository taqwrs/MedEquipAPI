<?php
include "../config/jwt.php";
include "../config/pagination_helper.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$roundId = isset($_GET['round_id']) ? intval($_GET['round_id']) : null;

try {
    if (!$roundId) {
        echo json_encode(buildApiResponse('error', null, null, 'round_id is required'));
        exit;
    }

    // หา plan_id ของ round
    $stmt = $dbh->prepare("SELECT plan_id, start_date FROM details_maintenance_plans WHERE details_ma_id = :round_id");
    $stmt->execute([':round_id' => $roundId]);
    $roundRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$roundRow) {
        echo json_encode(buildApiResponse('error', null, null, 'round not found'));
        exit;
    }

    $planId = intval($roundRow['plan_id']);
    $roundStart = $roundRow['start_date'] ?? null;

    // ดึงอุปกรณ์พร้อมสถานะบันทึก
    $baseSql = "
        SELECT e.equipment_id, e.asset_code, e.name AS equipment_name,
               CASE WHEN mr.ma_result_id IS NULL THEN 'รอ' ELSE 'บันทึกแล้ว' END AS status,
               mr.performed_date,
               dmp.details_ma_id AS round_id, dmp.start_date
        FROM plan_ma_equipments pe
        JOIN equipments e ON pe.equipment_id = e.equipment_id
        JOIN details_maintenance_plans dmp ON dmp.plan_id = pe.plan_id
        LEFT JOIN maintenance_result mr 
            ON mr.details_ma_id = dmp.details_ma_id 
            AND mr.equipment_id = e.equipment_id
        WHERE pe.plan_id = :plan_id
          AND dmp.details_ma_id = :round_id
    ";

    $countSql = "
        SELECT COUNT(*) FROM plan_ma_equipments pe
        JOIN equipments e ON pe.equipment_id = e.equipment_id
        JOIN details_maintenance_plans dmp ON dmp.plan_id = pe.plan_id
        LEFT JOIN maintenance_result mr 
            ON mr.details_ma_id = dmp.details_ma_id 
            AND mr.equipment_id = e.equipment_id
        WHERE pe.plan_id = :plan_id
          AND dmp.details_ma_id = :round_id
    ";

    $searchFields = ['e.name', 'e.asset_code'];
    $orderBy = 'ORDER BY e.name ASC';
    $additionalParams = [':plan_id' => $planId, ':round_id' => $roundId];

    $response = handlePaginatedSearch($dbh, $_GET, $baseSql, $countSql, $searchFields, $orderBy, '', $additionalParams);

    if ($response['status'] !== 'success') {
        echo json_encode($response);
        exit;
    }

    $flat = $response['data'] ?? [];
    $result = [];
    foreach ($flat as $row) {
        $result[] = [
            'equipment_id' => (int)$row['equipment_id'],
            'asset_code' => $row['asset_code'] ?? '-',
            'name' => $row['equipment_name'] ?? '-',
            'rounds' => [[
                'roundId' => (int)$row['round_id'],
                'start_date' => $row['start_date'] ?? $roundStart,
                'status' => $row['status'],
                'performed_date' => $row['performed_date'] ?? ''
            ]],
            'plan_name' => '',
            'file_equip' => []
        ];
    }

    echo json_encode(buildApiResponse('success', $result, $response['pagination'] ?? null));
} catch (Exception $e) {
    echo json_encode(buildApiResponse('error', null, null, $e->getMessage()));
}
