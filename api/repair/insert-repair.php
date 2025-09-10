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

try {

    $data = json_decode(file_get_contents("php://input"), true);

    $equipment_id = $data['equipment_id'] ?? null;
    $user_id = $data['user_id'] ?? null;
    $symptom = $data['symptom'] ?? '';
    $request_date = $data['request_date'] ;
    $location = $data['location'] ?? '';
    $status = $data['status'] ?? 'pending';
    $repair_type_id = $data['repair_type_id'] ?? null;

    if (!$equipment_id || !$user_id || !$repair_type_id) {
        echo json_encode(["success" => false, "message" => "Missing required fields"]);
        exit;
    }

    $query = "INSERT INTO repair 
                (equipment_id, user_id, symptom, request_date, location, status, repair_type_id) 
              VALUES 
                (:equipment_id, :user_id, :symptom, :request_date, :location, :status, :repair_type_id)";

    $stmt = $dbh->prepare($query);
    $stmt->bindParam(':equipment_id', $equipment_id);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->bindParam(':symptom', $symptom);
    $stmt->bindParam(':request_date', $request_date);
    $stmt->bindParam(':location', $location);
    $stmt->bindParam(':status', $status);
    $stmt->bindParam(':repair_type_id', $repair_type_id);

    $stmt->execute();

    echo json_encode([
        "success" => true,
        "message" => "Repair request inserted successfully",
        "repair_id" => $dbh->lastInsertId()
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
?>

