<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

try {
    $input = json_decode(file_get_contents("php://input"), true);

    $search = trim($input['search'] ?? '');
    $page = (int) ($input['page'] ?? 1);
    $limit = (int) ($input['limit'] ?? 5);
    $offset = ($page - 1) * $limit;

    $where = "WHERE 1";
    $params = [];

    if ($search !== '') {
        $where .= " AND r.role_name LIKE :search";
        $params[':search'] = "%{$search}%";
    }

    $stmtCount = $dbh->prepare("
        SELECT COUNT(*) as total
        FROM roles r
        $where
    ");
    $stmtCount->execute($params);
    $totalItems = (int) $stmtCount->fetchColumn();
    $totalPages = ceil($totalItems / $limit);

    $stmt = $dbh->prepare("
        SELECT 
            r.role_id,
            r.role_name
        FROM roles r
        $where
        ORDER BY r.role_id DESC
        LIMIT :limit OFFSET :offset
    ");

    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "data" => $results,
        "pagination" => [
            "totalItems" => $totalItems,
            "totalPages" => $totalPages,
            "currentPage" => $page,
            "limit" => $limit
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
?>
