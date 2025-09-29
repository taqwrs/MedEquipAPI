<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents("php://input"), true);

// รองรับ _method (ส่ง POST + _method)
if ($method === 'POST' && isset($input['_method'])) {
    $method = strtoupper($input['_method']);
}

try {
    if ($method === 'GET') {
        // ==== READ ====
        $search = trim($_GET['search'] ?? '');
        $page = (int) ($_GET['page'] ?? 1);
        $limit = (int) ($_GET['limit'] ?? 5);
        $offset = ($page - 1) * $limit;

        // นับจำนวน
        $countSql = "
            SELECT COUNT(DISTINCT c.category_id) as total
            FROM equipment_categories c
            WHERE c.name LIKE :search
        ";
        $countStmt = $dbh->prepare($countSql);
        $countStmt->bindValue(":search", "%$search%");
        $countStmt->execute();
        $totalItems = (int) $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        $totalPages = ceil($totalItems / $limit);

        // category_id ที่ต้องการ
        $categoryIdsSql = "
            SELECT category_id
            FROM equipment_categories
            WHERE name LIKE :search
            ORDER BY category_id DESC
            LIMIT :limit OFFSET :offset
        ";
        $categoryIdsStmt = $dbh->prepare($categoryIdsSql);
        $categoryIdsStmt->bindValue(":search", "%$search%");
        $categoryIdsStmt->bindValue(":limit", $limit, PDO::PARAM_INT);
        $categoryIdsStmt->bindValue(":offset", $offset, PDO::PARAM_INT);
        $categoryIdsStmt->execute();
        $categoryIds = $categoryIdsStmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($categoryIds)) {
            echo json_encode([
                "status" => "success",
                "data" => [],
                "pagination" => [
                    "totalItems" => $totalItems,
                    "totalPages" => $totalPages,
                    "currentPage" => $page,
                    "limit" => $limit
                ]
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // ข้อมูลเต็ม + subcategories
        $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
        $dataSql = "
            SELECT 
                c.category_id,
                c.name as category_name,
                s.subcategory_id,
                s.name as subcategory_name,
                s.type
            FROM equipment_categories c
            LEFT JOIN equipment_subcategories s ON c.category_id = s.category_id
            WHERE c.category_id IN ($placeholders)
            ORDER BY c.category_id DESC, s.subcategory_id
        ";
        $stmt = $dbh->prepare($dataSql);
        $stmt->execute($categoryIds);
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

    } elseif ($method === 'POST') {
        // ==== CREATE ====
        if (empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก name"]);
            exit;
        }

        $stmt = $dbh->prepare("INSERT INTO equipment_categories (name) VALUES (:name)");
        $stmt->bindParam(":name", $input['name']);
        $stmt->execute();

        echo json_encode(["status" => "ok", "message" => "เพิ่มข้อมูลเรียบร้อย"]);

    } elseif ($method === 'PUT') {
        // ==== UPDATE ====
        if (empty($input['category_id']) || empty($input['name'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก category_id และ name"]);
            exit;
        }

        $stmt = $dbh->prepare("UPDATE equipment_categories SET name = :name WHERE category_id = :id");
        $stmt->bindParam(":name", $input['name']);
        $stmt->bindParam(":id", $input['category_id']);
        $stmt->execute();

        echo json_encode(["status" => "ok", "message" => "แก้ไขข้อมูลเรียบร้อย"]);

    } elseif ($method === 'DELETE') {
        // ==== DELETE ====
        if (empty($input['category_id'])) {
            echo json_encode(["status" => "error", "message" => "กรุณากรอก category_id"]);
            exit;
        }

        $stmt = $dbh->prepare("DELETE FROM equipment_categories WHERE category_id = :id");
        $stmt->bindParam(":id", $input['category_id']);
        $stmt->execute();

        echo json_encode(["status" => "ok", "message" => "ลบข้อมูลเรียบร้อย"]);

    } else {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    }

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
