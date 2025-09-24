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

    $sql = "
        SELECT
            s.spare_part_id,
            s.equipment_id,
            s.name AS name,
            s.asset_code AS asset_code,
            e.name AS equipment_name
        FROM spare_parts s
        LEFT JOIN equipments e ON s.equipment_id = e.equipment_id
        WHERE 1=1
    ";

    $params = [];

    if ($equipmentId) {
        $sql .= " AND s.equipment_id = :equipment_id";
        $params[':equipment_id'] = $equipmentId;
    }

    $stmt = $dbh->prepare($sql);
    $stmt->execute($params);
    $spareParts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "data" => $spareParts,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
