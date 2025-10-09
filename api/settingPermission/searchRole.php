<?php
include "../config/jwt.php";
include "../config/pagination_helper.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

try {
    $input = json_decode(file_get_contents("php://input"), true);

    $baseSql = "
        SELECT 
            r.role_id,
            r.role_name
        FROM roles r
    ";

    $countSql = "
        SELECT COUNT(*) 
        FROM roles r
    ";

    $searchFields = ['role_name'];

    $response = handlePaginatedSearch(
        $dbh,
        $input,
        $baseSql,
        $countSql,
        $searchFields,
        'ORDER BY r.role_id DESC'
    );

    if (isset($response['pagination'])) {
        $response['pagination'] = [
            'current_page' => $response['pagination']['current_page'] ?? 1,
            'total_pages' => $response['pagination']['total_pages'] ?? 1,
            'total_items' => $response['pagination']['total_items'] ?? 0,
            'limit' => $response['pagination']['limit'] ?? 5
        ];
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
?>