<?php
include "../config/jwt.php";
include "../config/LogModel.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method required"]);
    exit;
}

try {
    $dbh->beginTransaction();

    $logModel = new LogModel($dbh);

    $data = json_decode(file_get_contents("php://input"), true);

    $equipment_id   = $data['equipment_id']   ?? null;
    $user_id        = $data['user_id']        ?? null;
    $remark         = $data['remark']         ?? '';
    $title          = $data['title']          ?? '';
    $request_date   = $data['request_date']   ?? null;
    $location       = $data['location']       ?? '';
    $status         = $data['status']         ?? 'รอดำเนินการ';
    $repair_type_id = $data['repair_type_id'] ?? null;

    if (!$equipment_id || !$user_id || !$repair_type_id || !$request_date) {
        echo json_encode([
            "success" => false,
            "message" => "Missing required fields"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmtOld = $dbh->prepare("SELECT equipment_id, status FROM equipments WHERE equipment_id = :equipment_id");
    $stmtOld->execute([':equipment_id' => $equipment_id]);
    $oldEquipmentData = $stmtOld->fetch(PDO::FETCH_ASSOC);

    $query = "INSERT INTO repair 
                (equipment_id, user_id, remark, title, request_date, location, status, repair_type_id, active) 
              VALUES 
                (:equipment_id, :user_id, :remark, :title, :request_date, :location, :status, :repair_type_id, :active)";

    $stmt = $dbh->prepare($query);
    $stmt->bindParam(':equipment_id', $equipment_id);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->bindParam(':remark', $remark);
    $stmt->bindParam(':title', $title);
    $stmt->bindParam(':request_date', $request_date);
    $stmt->bindParam(':location', $location);
    $stmt->bindParam(':status', $status);
    $stmt->bindParam(':repair_type_id', $repair_type_id);

    $active = 1;
    $stmt->bindParam(':active', $active);

    $stmt->execute();
    $repair_id = $dbh->lastInsertId();

    $newRepairData = [
        'repair_id' => $repair_id,
        'equipment_id' => $equipment_id,
        'user_id' => $user_id,
        'remark' => $remark,
        'title' => $title,
        'request_date' => $request_date,
        'location' => $location,
        'status' => $status,
        'repair_type_id' => $repair_type_id,
        'active' => $active
    ];

    $logModel->insertLog(
        $user_id,
        'repair',
        'INSERT',
        null,
        $newRepairData
    );

    $update = "UPDATE equipments 
               SET status = 'ซ่อม' 
               WHERE equipment_id = :equipment_id";
    $stmt2 = $dbh->prepare($update);
    $stmt2->bindParam(':equipment_id', $equipment_id);
    $stmt2->execute();

    $newEquipmentData = [
        'equipment_id' => $equipment_id,
        'status' => 'ซ่อม',
        'reason' => 'เปลี่ยนสถานะเนื่องจากสร้างรายการซ่อม',
        'repair_id' => $repair_id
    ];

    $logModel->insertLog(
        $user_id,
        'equipments',
        'UPDATE',
        $oldEquipmentData,
        $newEquipmentData
    );

    $dbh->commit();

    echo json_encode([
        "success" => true,
        "message" => "ok",
        "repair_id" => $repair_id,
        "repair_status" => $status  
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }

    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>