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

try {
    global $dbh;
    $where = "WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $where .= " AND es.name LIKE :search";
        $params[':search'] = "%$search%";
    }

    if (!empty($input['type'])) {
        $where .= " AND es.type = :type";
        $params[':type'] = $input['type'];
    }

    $countSql = "
        SELECT COUNT(*) AS total
        FROM equipment_subcategories es
        JOIN equipment_categories ec 
            ON es.category_id = ec.category_id
        $where
    ";
    $countStmt = $dbh->prepare($countSql);
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v);
    }
    $countStmt->execute();
    $totalItems = (int) $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $sql = "
        SELECT 
            sub.subcategory_id,
            sub.category_id,
            sub.subcategory_name,
            sub.subcategory_type,
            sub.category_name,
            rg.relation_group_id,
            rg.group_user_id,
            gu.group_name,
            gu.type AS group_type
        FROM (
            SELECT 
                es.subcategory_id,
                es.category_id,
                es.name AS subcategory_name,
                es.type AS subcategory_type,
                ec.name AS category_name
            FROM equipment_subcategories es
            JOIN equipment_categories ec 
                ON es.category_id = ec.category_id
            $where
            ORDER BY es.subcategory_id DESC
            LIMIT :limit OFFSET :offset
        ) sub
        LEFT JOIN relation_group rg 
            ON sub.subcategory_id = rg.subcategory_id
        LEFT JOIN group_user gu 
            ON rg.group_user_id = gu.group_user_id
        ORDER BY sub.subcategory_id DESC
    ";

    $stmt = $dbh->prepare($sql);

    // bind ค่าตัวแปรค้นหา
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }

    $stmt->bindValue(":limit", $limit, PDO::PARAM_INT);
    $stmt->bindValue(":offset", $offset, PDO::PARAM_INT);

    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $response = [
        "status" => "ok",
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
