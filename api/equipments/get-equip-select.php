<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

include "../config/jwt.php"; 
include "../config/pagination_helper.php";

// Support both GET and POST methods
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Get input data
$input = [];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $input = $_GET;
}

try {
    $baseSql = "
        SELECT equipment_id, name, asset_code, main_equipment_id, status, brand, model
        FROM equipments
    ";
    
    $searchFields = ['name', 'asset_code', 'brand', 'model', 'status'];
    $whereClause = "WHERE active = 1"; // มีฟิลด์ active จริง
    $orderBy = "ORDER BY equipment_id DESC";
    // ถ้ามี exclude_id ให้กรองออก
    if (!empty($input['exclude_id'])) {
        $excludeId = intval($input['exclude_id']);
        $whereClause .= " AND equipment_id != $excludeId";
    }
    // เรียกเฉพาะ search ฝั่ง backend
    $response = handleSearchOnly(
        $dbh, 
        $input, 
        $baseSql, 
        $searchFields, 
        $orderBy, 
        $whereClause
    );
    
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
