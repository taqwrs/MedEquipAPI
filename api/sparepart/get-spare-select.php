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
    $sql = "
  SELECT
    s.spare_part_id,
    s.equipment_id,
    s.name AS name,
    s.asset_code AS asset_code,
    e.name AS equipment_name
  FROM spare_parts s
  LEFT JOIN equipments e ON s.equipment_id = e.equipment_id
  WHERE s.active = 1
  ORDER BY s.spare_part_id DESC
";

    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $spareParts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "data" => $spareParts,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
