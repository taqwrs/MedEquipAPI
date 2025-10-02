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
    $sqlCount = "SELECT COUNT(*) as total FROM writeoff_types WHERE name LIKE :search";
    $stmtCount = $dbh->prepare($sqlCount);
    $like = "%$search%";
    $stmtCount->bindParam(":search", $like);
    $stmtCount->execute();
    $total = (int)$stmtCount->fetch(PDO::FETCH_ASSOC)['total'];

    $sql = "
        SELECT writeoff_types_id, name
        FROM writeoff_types
        WHERE name LIKE :search
        ORDER BY writeoff_types_id DESC
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $dbh->prepare($sql);
    $stmt->bindParam(":search", $like);
    $stmt->bindParam(":limit", $limit, PDO::PARAM_INT);
    $stmt->bindParam(":offset", $offset, PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "ok",
        "data" => $results,
        "pagination" => [
            "totalItems" => $total,
            "totalPages" => $limit > 0 ? ceil($total / $limit) : 1,
            "currentPage" => $page,
            "limit" => $limit
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status"=>"error", "message"=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
