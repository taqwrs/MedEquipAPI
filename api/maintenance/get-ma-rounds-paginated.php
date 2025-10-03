<?php
// get-ma-rounds-paginated.php
include "../config/jwt.php";
include "../config/pagination_helper.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$planId = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : null;
$equipmentId = isset($_GET['equipment_id']) ? intval($_GET['equipment_id']) : null;

try {
    if (!$planId) {
        throw new Exception("Missing plan_id");
    }

    // Base query สำหรับดึงรอบ
    $baseSql = "
        SELECT 
            dmp.details_ma_id as round_id,
            dmp.start_date,
            ROW_NUMBER() OVER (ORDER BY dmp.details_ma_id ASC) as round_number,
            e.name as equipment_name,
            e.asset_code
        FROM details_maintenance_plans dmp
        LEFT JOIN maintenance_result mr ON mr.details_ma_id = dmp.details_ma_id 
                                      AND mr.equipment_id = :equipment_id
        LEFT JOIN equipments e ON e.equipment_id = :equipment_id
        WHERE dmp.plan_id = :plan_id
        AND mr.ma_result_id IS NULL  -- รอบที่ยังไม่บันทึกผล
    ";
    
    $countSql = "
        SELECT COUNT(*)
        FROM details_maintenance_plans dmp
        LEFT JOIN maintenance_result mr ON mr.details_ma_id = dmp.details_ma_id 
                                      AND mr.equipment_id = :equipment_id
        WHERE dmp.plan_id = :plan_id
        AND mr.ma_result_id IS NULL
    ";
    
    $searchFields = [];
    $whereClause = "";
    $orderBy = "ORDER BY dmp.details_ma_id ASC";
    
    $additionalParams = [
        ':plan_id' => $planId,
        ':equipment_id' => $equipmentId
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