<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

include "../config/jwt.php";

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "GET method only"]);
    exit;
}

try {
    $equipmentId = isset($_GET['equipment_id']) ? intval($_GET['equipment_id']) : null;

    // ดึง spare_subcategory_id ที่เชื่อมกับเครื่องมือนั้นๆ
    $sql = "
        SELECT DISTINCT
            s.spare_part_id,
            s.equipment_id,
            s.name AS name,
            s.asset_code AS asset_code,
            s.spare_subcategory_id,
            sc.name AS subcategory_name,
            e.name AS equipment_name,
            e.asset_code AS equipment_asset_code
        FROM spare_parts s
        LEFT JOIN equipments e ON s.equipment_id = e.equipment_id
        LEFT JOIN spare_subcategories sc ON s.spare_subcategory_id = sc.spare_subcategory_id
        WHERE s.status = 'คลัง'
    ";

    $params = [];

    if ($equipmentId) {
        // ดึงอะไหล่ที่มี spare_subcate_id เดียวกันกับเครื่องมือนั้น
        $sql .= " AND s.spare_subcategory_id IN (
            SELECT DISTINCT sp.spare_subcategory_id
            FROM spare_parts sp 
            WHERE sp.equipment_id = :equipment_id
        )";
        $params[':equipment_id'] = $equipmentId;
    }

    $sql .= " ORDER BY s.name ASC";

    $stmt = $dbh->prepare($sql);
    $stmt->execute($params);
    $spareParts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "data" => $spareParts,
        "count" => count($spareParts)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}