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
        $where .= " AND name LIKE :search";
        $params[':search'] = "%{$search}%";
    }

    $stmtCount = $dbh->prepare("
        SELECT COUNT(*) 
        FROM spare_categories c
        $where
    ");
    $stmtCount->execute($params);
    $totalItems = (int) $stmtCount->fetchColumn();
    $totalPages = ceil($totalItems / $limit);

    // ดึง categories ที่อยู่ในหน้านั้นๆ ก่อน แล้วค่อย JOIN เอา subcategories
    $stmt = $dbh->prepare("
        SELECT 
            c.spare_category_id,
            c.name AS category_name,
            s.spare_subcategory_id,
            s.name AS subcategory_name
        FROM (
            SELECT spare_category_id, name
            FROM spare_categories
            $where
            ORDER BY spare_category_id DESC
            LIMIT :limit OFFSET :offset
        ) c
        LEFT JOIN spare_subcategories s 
            ON c.spare_category_id = s.spare_category_id
        ORDER BY c.spare_category_id DESC, s.spare_subcategory_id
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