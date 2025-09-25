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
        SELECT equipment_id, name, asset_code, main_equipment_id
        FROM equipments
        WHERE active = 1
        ORDER BY equipment_id DESC
    ";

    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $equipments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "data" => $equipments,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
