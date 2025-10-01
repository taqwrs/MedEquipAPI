<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

try {
    $input = json_decode(file_get_contents("php://input"), true);

    $search = trim($input['search'] ?? '');
    $groupType = trim($input['type'] ?? '');
    $page = (int) ($input['page'] ?? 1);
    $limit = (int) ($input['limit'] ?? 5);
    $offset = ($page - 1) * $limit;

    $where = "WHERE 1";
    $params = [];

    if ($search !== '') {
        $where .= " AND gu.group_name LIKE :search";
        $params[':search'] = "%{$search}%";
    }

    if ($groupType !== '') {
        $where .= " AND gu.type = :groupType";
        $params[':groupType'] = $groupType;
    }

    // นับจำนวนทั้งหมด
    $stmtCount = $dbh->prepare("
        SELECT COUNT(*) as total
        FROM group_user gu
        $where
    ");
    $stmtCount->execute($params);
    $totalItems = (int) $stmtCount->fetchColumn();
    $totalPages = ceil($totalItems / $limit);

    // ดึงข้อมูล
    $stmt = $dbh->prepare("
        SELECT 
            gu.group_user_id,
            gu.group_name,
            gu.type AS group_type
        FROM group_user gu
        $where
        ORDER BY gu.group_user_id DESC
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
