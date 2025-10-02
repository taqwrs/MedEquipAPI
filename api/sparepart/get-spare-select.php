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
        SELECT
            s.spare_part_id,
            s.equipment_id,
            s.name AS name,
            s.asset_code AS asset_code,
            s.brand,
            s.model,
            s.status,
            e.name AS equipment_name
        FROM spare_parts s
        LEFT JOIN equipments e ON s.equipment_id = e.equipment_id
    ";
    
    $countSql = "
        SELECT COUNT(*) 
        FROM spare_parts s
        LEFT JOIN equipments e ON s.equipment_id = e.equipment_id
    ";
    
    $searchFields = ['s.name', 's.asset_code', 's.brand', 's.model', 's.status', 'e.name'];
    $whereClause = "WHERE s.active = 1";
    $orderBy = "ORDER BY s.spare_part_id DESC";
    
    $response = handlePaginatedSearch(
        $dbh, 
        $input, 
        $baseSql, 
        $countSql, 
        $searchFields, 
        $orderBy, 
        $whereClause
    );
    
    echo json_encode($response);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
