<?php
include "../config/jwt.php"; 

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== "POST") {
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
$search = trim($input['search'] ?? '');
$page = (int) ($input['page'] ?? 1);
$limit = (int) ($input['limit'] ?? 5);
$offset = ($page - 1) * $limit;
$useLimit = $limit > 0;

try {
    global $dbh;
    $where = "WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $where .= " AND (name LIKE :search OR tax_number LIKE :search)";
        $params[':search'] = "%$search%";
    }

    $countStmt = $dbh->prepare("
        SELECT COUNT(*) AS total
        FROM companies
        $where
    ");
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v);
    }
    $countStmt->execute();
    $totalItems = (int) $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $stmt = $dbh->prepare("
        SELECT 
            company_id,
            name,
            tax_number,
            address,
            phone,
            email,
            line_id,
            details
        FROM companies
        $where
        ORDER BY company_id DESC
        " . ($useLimit ? "LIMIT :limit OFFSET :offset" : "")
    );

    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }

    if ($useLimit) {
        $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
        $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);
    }

    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response = [
        "status" => "success",
        "data" => $results,
        "pagination" => [
            "totalItems" => $totalItems,
            "totalPages" => $limit > 0 ? ceil($totalItems / $limit) : 1,
            "currentPage" => $page,
            "limit" => $limit
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
