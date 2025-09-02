<?php
include "../config/jwt.php";
include "history_transfers.php"; // เรียกใช้ฟังก์ชัน insertHistory

$input = json_decode(file_get_contents('php://input'), true); // รับเป็น associative array

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method required"]);
    die();
}

try {
    if (!isset($input['transfer_id'])) {
        throw new Exception("Missing field: transfer_id");
    }

    $transferId = $input['transfer_id'];

    // ฟิลด์ที่สามารถอัปเดต
    $updateFields = [
        'transfer_type',
        'equipment_id',
        'from_department_id',
        'to_department_id',
        'transfer_date',
        'install_location_dep_id',
        'reason',
        'transfer_user_id',
        'recipient_user_id',
        'relation_group_id',
        'detail_trans'
    ];

    $setParts = [];
    $params = [':transfer_id' => $transferId];

    foreach ($updateFields as $field) {
        if (isset($input[$field])) {
            $setParts[] = "$field = :$field";
            $params[":$field"] = $input[$field];
        }
    }

    if (empty($setParts)) {
        throw new Exception("No fields to update");
    }

    // Update equipment_transfers
    $query = "UPDATE equipment_transfers SET " . implode(", ", $setParts) . " WHERE transfer_id = :transfer_id";
    $stmt = $dbh->prepare($query);
    $stmt->execute($params);

    // เตรียมข้อมูลสำหรับ history (ดึงข้อมูลล่าสุดจากตาราง)
    $stmt = $dbh->prepare("SELECT * FROM equipment_transfers WHERE transfer_id = :transfer_id");
    $stmt->execute([':transfer_id' => $transferId]);
    $updatedData = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($updatedData) {
        insertHistory($dbh, $updatedData); // Insert ลง history
    }

    echo json_encode(["status" => "ok", "message" => "Update success"]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
