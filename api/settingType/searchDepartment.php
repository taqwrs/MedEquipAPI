<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$input = json_decode(file_get_contents("php://input"), true);
$search = trim($input['search'] ?? '');
$page   = (int) ($input['page'] ?? 1);
$limit  = (int) ($input['limit'] ?? 5);
$offset = ($page - 1) * $limit;
$useLimit = $limit > 0;

try {
    $sqlCount = "SELECT COUNT(*) FROM departments WHERE department_name LIKE :search";
    $stmtCount = $dbh->prepare($sqlCount);
    $stmtCount->bindValue(":search", "%$search%");
    $stmtCount->execute();
    $totalItems = $stmtCount->fetchColumn();

    $sql = "SELECT department_id, department_name 
            FROM departments 
            WHERE department_name LIKE :search 
            ORDER BY department_id DESC";
    if ($useLimit) {
        $sql .= " LIMIT :offset, :limit";
    }
    $stmt = $dbh->prepare($sql);
    $stmt->bindValue(":search", "%$search%");
    if ($useLimit) {
        $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
        $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
    }
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "ok",
        "data" => $results,
        "pagination" => [
            "totalItems" => $totalItems,
            "totalPages" => $useLimit ? ceil($totalItems / $limit) : 1,
            "currentPage" => $page,
            "limit" => $limit
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
