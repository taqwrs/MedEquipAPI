<?php
include_once "../config/jwt.php";         // include_once ป้องกันซ้ำ
include_once "history_transfers.php";     // include ฟังก์ชัน history

$input = json_decode(file_get_contents('php://input'), true); // รับเป็น associative array

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method required"]);
    die();
}

try {
    // ตรวจสอบค่าที่จำเป็น
    $requiredFields = [
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

    foreach ($requiredFields as $field) {
        if (!isset($input[$field])) {
            throw new Exception("Missing field: $field");
        }
    }

    // Insert ลง equipment_transfers
    $query = "INSERT INTO equipment_transfers 
                (transfer_type, equipment_id, from_department_id, to_department_id, transfer_date, install_location_dep_id, reason, transfer_user_id, recipient_user_id, relation_group_id, detail_trans)
              VALUES 
                (:transfer_type, :equipment_id, :from_department_id, :to_department_id, :transfer_date, :install_location_dep_id, :reason, :transfer_user_id, :recipient_user_id, :relation_group_id, :detail_trans)";

    $stmt = $dbh->prepare($query);
    $stmt->execute([
        ':transfer_type' => $input['transfer_type'],
        ':equipment_id' => $input['equipment_id'],
        ':from_department_id' => $input['from_department_id'],
        ':to_department_id' => $input['to_department_id'],
        ':transfer_date' => $input['transfer_date'],
        ':install_location_dep_id' => $input['install_location_dep_id'],
        ':reason' => $input['reason'],
        ':transfer_user_id' => $input['transfer_user_id'],
        ':recipient_user_id' => $input['recipient_user_id'],
        ':relation_group_id' => $input['relation_group_id'],
        ':detail_trans' => $input['detail_trans']
    ]);

    // ดึง transfer_id ล่าสุด
    $lastTransferId = $dbh->lastInsertId();

    // เตรียมข้อมูลสำหรับ history
    $historyData = [
        'transfer_id' => $lastTransferId,
        'transfer_type' => $input['transfer_type'],
        'equipment_id' => $input['equipment_id'],
        'from_department_id' => $input['from_department_id'],
        'to_department_id' => $input['to_department_id'],
        'transfer_date' => $input['transfer_date'],
        'install_location_dep_id' => $input['install_location_dep_id'],
        'reason' => $input['reason'],
        'transfer_user_id' => $input['transfer_user_id'],
        'recipient_user_id' => $input['recipient_user_id'],
        'relation_group_id' => $input['relation_group_id'],
        'detail_trans' => $input['detail_trans']
    ];

    // Insert ลง history
    insertHistory($dbh, $historyData);

    echo json_encode(["status" => "ok", "message" => "Insert success"]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
