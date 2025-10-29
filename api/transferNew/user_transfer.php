<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit;
}

try {
    $currentUserId = null;
    if (isset($decoded)) {
        if (isset($decoded->data->ID)) {
            $currentUserId = $decoded->data->ID;
        } elseif (isset($decoded->ID)) {
            $currentUserId = $decoded->ID;
        }
    }

    if (!$currentUserId) {
        throw new Exception("ไม่พบข้อมูลผู้ใช้งาน (JWT ไม่ถูกต้อง)");
    }

    $search = "";
    $page = 1;
    $limit = 10;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents("php://input"), true);
        $search = isset($input['search']) ? trim($input['search']) : "";
        $page = isset($input['page']) ? max(1, (int)$input['page']) : 1;
        $limit = isset($input['limit']) ? max(1, min(100, (int)$input['limit'])) : 10;
    }

    $offset = ($page - 1) * $limit;

    $sqlWhere = "WHERE u.ID != :currentUserId";
    $params = [':currentUserId' => $currentUserId];

    if (!empty($search)) {
        $sqlWhere .= " AND (
            u.user_id LIKE :search 
            OR u.full_name LIKE :search 
            OR d.department_name LIKE :search
        )";
        $params[':search'] = "%{$search}%";
    }

    // นับจำนวนทั้งหมด
    $sqlCount = "
        SELECT COUNT(*) as total
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.department_id
        {$sqlWhere}
    ";

    $stmtCount = $dbh->prepare($sqlCount);
    foreach ($params as $key => $value) {
        $stmtCount->bindValue($key, $value);
    }
    $stmtCount->execute();
    $totalRecords = (int)$stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalRecords / $limit);

    // ดึงข้อมูลผู้ใช้ตามหน้า
    $sql = "
        SELECT 
            u.ID,
            u.user_id,
            u.full_name,
            u.department_id,
            d.department_name
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.department_id
        {$sqlWhere}
        ORDER BY d.department_name, u.full_name ASC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $dbh->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $users = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $users[] = [
            "ID" => (int)$row["ID"],
            "user_id" => (int)$row["user_id"],
            "full_name" => $row["full_name"],
            "department_id" => $row["department_id"] ? (int)$row["department_id"] : null,
            "department_name" => $row["department_name"] ?? "ไม่มีแผนก"
        ];
    }

    echo json_encode([
        "status" => "success",
        "data" => $users,
        "pagination" => [
            "currentPage" => $page,
            "totalPages" => $totalPages,
            "totalRecords" => $totalRecords,
            "limit" => $limit,
            "hasMore" => $page < $totalPages
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Database error: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>