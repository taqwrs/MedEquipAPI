<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$input = json_decode(file_get_contents("php://input"), true) ?? [];
$search = trim($input['search'] ?? $_GET['search'] ?? '');
$page   = (int)($input['page'] ?? $_GET['page'] ?? 1);
$limit  = (int)($input['limit'] ?? $_GET['limit'] ?? 5);
$offset = ($page - 1) * $limit;

try {
    $countSql = "
        SELECT COUNT(*) as total
        FROM equipment_categories
        WHERE name LIKE :search
    ";
    $countStmt = $dbh->prepare($countSql);
    $countStmt->bindValue(":search", "%$search%");
    $countStmt->execute();
    $totalItems = (int) $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = $limit > 0 ? ceil($totalItems / $limit) : 1;

    $sql = "
        SELECT category_id, name AS category_name
        FROM equipment_categories
        WHERE name LIKE :search
        ORDER BY category_id DESC
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $dbh->prepare($sql);
    $stmt->bindValue(":search", "%$search%");
    $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
    $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "ok",
        "data" => $results,
        "pagination" => [
            "totalItems" => $totalItems,
            "totalPages" => $totalPages,
            "currentPage" => $page,
            "limit" => $limit
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
