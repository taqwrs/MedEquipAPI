<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["success" => false, "message" => "POST method required"]);
    exit;
}

try {
    $dbh->beginTransaction();
    $data = json_decode(file_get_contents("php://input"), true);
    $repair_id = $data['repair_id'] ?? null;

    if (!$repair_id) {
        echo json_encode(["success" => false, "message" => "Missing repair_id"]);
        exit;
    }

    $updatableFields = ['equipment_id', 'remark', 'title', 'location', 'status', 'repair_type_id'];
    $fields = [];
    $params = [':repair_id' => $repair_id];

    foreach ($updatableFields as $field) {
        if (array_key_exists($field, $data)) {
            $fields[] = "$field = :$field";
            $params[":$field"] = $data[$field];
        }
    }

    // ตรวจสอบว่ามีค่า active ส่งมาหรือไม่
    if (array_key_exists('active', $data)) {
        $fields[] = "active = :active";
        $params[':active'] = $data['active'] ? 1 : 0; // แปลงเป็น 1 หรือ 0
    }

    if (!empty($fields)) {
        $query = "UPDATE repair SET " . implode(", ", $fields) . " WHERE repair_id = :repair_id";
        $stmt = $dbh->prepare($query);
        $stmt->execute($params);
    }

    // ดึงข้อมูล repair ล่าสุด
    $query = "SELECT 
        r.repair_id,
        r.equipment_id,
        e.name AS equipment_name,
        e.asset_code,
        r.title,
        r.remark,
        r.request_date,
        r.location,
        r.status AS repair_status,
        r.active,
        u_reporter.full_name AS reporter,
        rt.repair_type_id,                
        rt.name_type AS repair_type,
        gu.group_user_id,                 
        gu.group_name AS responsible_group
    FROM repair r
    LEFT JOIN equipments e ON r.equipment_id = e.equipment_id
    LEFT JOIN users u_reporter ON r.user_id = u_reporter.user_id
    LEFT JOIN repair_type rt ON r.repair_type_id = rt.repair_type_id
    LEFT JOIN group_user gu ON rt.group_user_id = gu.group_user_id
    WHERE r.repair_id = :repair_id";

    $stmt = $dbh->prepare($query);
    $stmt->execute([':repair_id' => $repair_id]);
    $repair = $stmt->fetch(PDO::FETCH_ASSOC);

    $dbh->commit();

    if ($repair) {
        echo json_encode([
            "success" => true,
            "message" => "Repair updated successfully",
            "data" => $repair
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(["success" => false, "message" => "Repair not found"]);
    }

} catch (Exception $e) {
    if ($dbh->inTransaction())
        $dbh->rollBack();
    
    echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
?>
