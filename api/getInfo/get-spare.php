<?php
include "../config/jwt.php";
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$equipment_id = isset($data['equipment_id']) ? intval($data['equipment_id']) : 0;
$search = isset($data['search']) ? trim($data['search']) : '';

if ($equipment_id <= 0) {
    echo json_encode(["status" => "error", "message" => "Invalid equipment_id"]);
    exit;
}

try {
    $sql = "
        SELECT 
            spare_part_id,
            asset_code,
            name,
            status
        FROM spare_parts
        WHERE equipment_id = :equipment_id
    ";

   if (!empty($search)) {
    $sql .= " AND (name LIKE :search OR asset_code LIKE :search)";
}


    $stmt = $dbh->prepare($sql);
    $stmt->bindParam(':equipment_id', $equipment_id, PDO::PARAM_INT);

    if (!empty($search)) {
        $searchParam = "%$search%";
        $stmt->bindParam(':search', $searchParam, PDO::PARAM_STR);
    }

    $stmt->execute();
    $spareParts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(["status" => "success", "data" => $spareParts]);

} catch (PDOException $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
