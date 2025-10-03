<?php
// get-ma-equipments-paginated.php
include "../config/jwt.php";
include "../config/pagination_helper.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$planId = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : null;
$roundId = isset($_GET['round_id']) ? intval($_GET['round_id']) : null;

try {
    if (!$planId) {
        throw new Exception("Missing plan_id");
    }

    $baseSql = "
        SELECT 
            e.equipment_id,
            e.name as equipment_name,
            e.asset_code,
            CONCAT(e.asset_code, ' - ', e.name) as display_name
        FROM equipments e
        JOIN plan_ma_equipments pe ON e.equipment_id = pe.equipment_id
        LEFT JOIN maintenance_result mr ON mr.equipment_id = e.equipment_id 
                                      AND mr.details_ma_id = :round_id
        WHERE pe.plan_id = :plan_id
        AND mr.ma_result_id IS NULL  -- อุปกรณ์ที่ยังไม่บันทึกผลในรอบนี้
    ";
    
    $countSql = "
        SELECT COUNT(*)
        FROM equipments e
        JOIN plan_ma_equipments pe ON e.equipment_id = pe.equipment_id
        LEFT JOIN maintenance_result mr ON mr.equipment_id = e.equipment_id 
                                      AND mr.details_ma_id = :round_id
        WHERE pe.plan_id = :plan_id
        AND mr.ma_result_id IS NULL
    ";
    
    $searchFields = ['e.name', 'e.asset_code'];
    $whereClause = "";
    $orderBy = "ORDER BY e.name ASC";
    
    $additionalParams = [
        ':plan_id' => $planId,
        ':round_id' => $roundId
    ];

    $response = handlePaginatedSearch(
        $dbh, 
        $_GET, 
        $baseSql, 
        $countSql, 
        $searchFields, 
        $orderBy, 
        $whereClause,
        $additionalParams
    );
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error', 
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>