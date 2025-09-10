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

    $repair_id = $data['repair_id'] ?? null;
    $equipment_id = $data['equipment_id'] ?? null;
    $symptom = $data['symptom'] ?? null;
    $location = $data['location'] ?? null;
    $status = $data['status'] ?? null;
    $repair_type_id = $data['repair_type_id'] ?? null;

    if (!$repair_id) {
        echo json_encode(["success" => false, "message" => "Missing repair_id"]);
        exit;
    }


    $fields = [];
    $params = [':repair_id' => $repair_id];

    if ($equipment_id !== null) {
        $fields[] = "equipment_id = :equipment_id";
        $params[':equipment_id'] = $equipment_id;
    }
    if ($symptom !== null) {
        $fields[] = "symptom = :symptom";
        $params[':symptom'] = $symptom;
    }
    if ($location !== null) {
        $fields[] = "location = :location";
        $params[':location'] = $location;
    }
    if ($status !== null) {
        $fields[] = "status = :status";
        $params[':status'] = $status;
    }
    if ($repair_type_id !== null) {
        $fields[] = "repair_type_id = :repair_type_id";
        $params[':repair_type_id'] = $repair_type_id;
    }

    if (empty($fields)) {
        echo json_encode(["success" => false, "message" => "No fields to update"]);
        exit;
    }

    $query = "UPDATE repair SET " . implode(", ", $fields) . " WHERE repair_id = :repair_id";
    $stmt = $dbh->prepare($query);

    $stmt->execute($params);

    echo json_encode([
        "success" => true,
        "message" => "Repair updated successfully"
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ]);
}
?>
