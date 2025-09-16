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
    $dbh->beginTransaction();
    $data = json_decode(file_get_contents("php://input"), true);
    $equipment_id = $data['equipment_id'] ?? null;
    $user_id = $data['user_id'] ?? null;
    $remark = $data['remark'] ?? '';
    $title = $data['title'] ?? '';
    $request_date = $data['request_date'] ;
    $location = $data['location'] ?? '';
    $status = $data['status'] ?? 'รอดำเนินการ';
    $repair_type_id = $data['repair_type_id'] ?? null;

    if (!$equipment_id || !$user_id || !$repair_type_id) {
        echo json_encode(["success" => false, "message" => "Missing required fields"]);
        exit;
    }

    // insert repair request
    $query = "INSERT INTO repair 
                (equipment_id, user_id, remark, title, request_date, location, status, repair_type_id) 
              VALUES 
                (:equipment_id, :user_id, :remark, :title, :request_date, :location, :status, :repair_type_id)";

    $stmt = $dbh->prepare($query);
    $stmt->bindParam(':equipment_id', $equipment_id);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->bindParam(':remark', $remark);
    $stmt->bindParam(':title', $title);
    $stmt->bindParam(':request_date', $request_date);
    $stmt->bindParam(':location', $location);
    $stmt->bindParam(':status', $status);
    $stmt->bindParam(':repair_type_id', $repair_type_id);

    $stmt->execute();

    $update = "UPDATE equipments SET status = 'ซ่อม' WHERE equipment_id = :equipment_id";
    $stmt2 = $dbh->prepare($update);
    $stmt2->bindParam(':equipment_id', $equipment_id);
    $stmt2->execute();

    $dbh->commit();

    echo json_encode([
        "success" => true,
        "message" => "Repair request inserted successfully และอัปเดตสถานะอุปกรณ์เรียบร้อย",
        "repair_id" => $dbh->lastInsertId(),
        "repair_status" => $status  
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($dbh->inTransaction())
        $dbh->rollBack();
    
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
