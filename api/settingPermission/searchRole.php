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

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
?>
