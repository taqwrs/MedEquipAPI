<?php
include "../config/jwt.php"; 

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method required"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!isset($input['writeoff_id'])) {
    echo json_encode(["status" => "error", "message" => "writeoff_id is required"]);
    exit;
}

$writeoff_id = intval($input['writeoff_id']);

try {
    $query = "
        SELECT w.*, e.name AS equipment_name, u.full_name AS requester_name, a.full_name AS approver_name, wt.name AS writeoff_type_name
        FROM write_offs w
        LEFT JOIN equipments e ON w.equipment_id = e.equipment_id
        LEFT JOIN users u ON w.user_id = u.user_id
        LEFT JOIN users a ON w.approved_by = a.user_id
        LEFT JOIN writeoff_types wt ON w.writeoff_types_id = wt.writeoff_types_id
        WHERE w.writeoff_id = :writeoff_id
    ";

    $stmt = $dbh->prepare($query);
    $stmt->bindParam(':writeoff_id', $writeoff_id, PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$result) {
        echo json_encode(["status" => "error", "message" => "Data not found"]);
        exit;
    }

    echo json_encode(["status" => "success", "data" => $result]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
