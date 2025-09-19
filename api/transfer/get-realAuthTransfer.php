<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit;
}

try {
    // Get user ID from JWT token
    $u_id = $decoded->data->ID ?? null;
    if (!$u_id) {
        throw new Exception("User ID not found");
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $search = trim($input['search'] ?? '');
    $page = max(1, (int)($input['page'] ?? 1));
    $limit = max(1, (int)($input['limit'] ?? 5));
    $offset = ($page - 1) * $limit;
    
    // Base query conditions
    $baseWhere = "
        ru.u_id = :u_id 
        AND gu.type = 'ผู้ดูแลหลัก'
        AND e.active = 1
    ";
    
    $searchWhere = '';
    $params = [':u_id' => $u_id];
    
    if ($search) {
        $searchWhere = " AND (
            e.name LIKE :search 
            OR e.asset_code LIKE :search 
            OR d.department_name LIKE :search 
            OR e.location_details LIKE :search
        )";
        $params[':search'] = '%' . $search . '%';
    }
    
    $joinTables = "
        FROM equipments e
        INNER JOIN equipment_subcategories es ON e.subcategory_id = es.subcategory_id
        INNER JOIN relation_group rg ON es.subcategory_id = rg.subcategory_id
        INNER JOIN group_user gu ON rg.group_user_id = gu.group_user_id
        INNER JOIN relation_user ru ON gu.group_user_id = ru.group_user_id
        LEFT JOIN departments d ON e.location_department_id = d.department_id
        WHERE $baseWhere $searchWhere
    ";
    
    // Count total items
    $countStmt = $dbh->prepare("SELECT COUNT(DISTINCT e.equipment_id) as total $joinTables");
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalItems = (int)$countStmt->fetchColumn();
    
    // Get paginated data
    $dataStmt = $dbh->prepare("
        SELECT DISTINCT
            e.equipment_id,
            e.name,
            e.asset_code,
            e.subcategory_id,
            es.name as subcategory_name,
            e.location_department_id,
            e.location_details,
            e.brand,
            e.model,
            e.serial_number,
            e.status,
            d.department_name as location_department_name,
            e.updated_at
            
        $joinTables
        ORDER BY e.updated_at DESC
        LIMIT :limit OFFSET :offset
    ");
    
    foreach ($params as $key => $value) {
        $dataStmt->bindValue($key, $value);
    }
    $dataStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $dataStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $dataStmt->execute();
    
    $equipment_list = [];
    while ($row = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
        $equipment_list[] = [
            "equipment_id" => (int)$row['equipment_id'],
            "name" => $row['name'],
            "asset_code" => $row['asset_code'],
            "subcategory_id" => (int)$row['subcategory_id'],
            "subcategory_name" => $row['subcategory_name'],
            "location_department_id" => $row['location_department_id'] ? (int)$row['location_department_id'] : null,
            "location_department_name" => $row['location_department_name'],
            "location_details" => $row['location_details'],
            "brand" => $row['brand'],
            "model" => $row['model'],
            "serial_number" => $row['serial_number'],
            "status" => $row['status']
        ];
    }
    
    echo json_encode([
        "status" => "success",
        "data" => $equipment_list,
        "pagination" => [
            "totalItems" => $totalItems,
            "totalPages" => (int)ceil($totalItems / $limit),
            "currentPage" => $page,
            "limit" => $limit
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error", 
        "message" => "Database error: " . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>