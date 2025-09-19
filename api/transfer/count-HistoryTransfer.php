<?php
include "../config/jwt.php"; 
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

try {
    $u_id = $decoded->data->ID ?? null;
    if (!$u_id) throw new Exception("User ID not found");

    // นับจำนวนโอนย้ายแต่ละประเภทและบทบาท (ผู้โอน/ผู้รับ)
    $sql = "
        SELECT
            SUM(CASE WHEN transfer_type='โอนย้ายถาวร' AND transfer_user_id=:u_id THEN 1 ELSE 0 END) AS permanent_transfer_by_me,
            SUM(CASE WHEN transfer_type='โอนย้ายถาวร' AND recipient_user_id=:u_id THEN 1 ELSE 0 END) AS permanent_received_by_me,
            SUM(CASE WHEN transfer_type='โอนย้ายชั่วคราว' AND transfer_user_id=:u_id AND status_transfer=0 THEN 1 ELSE 0 END) AS temporary_transfer_by_me,
            SUM(CASE WHEN transfer_type='โอนย้ายชั่วคราว' AND recipient_user_id=:u_id AND status_transfer=0 THEN 1 ELSE 0 END) AS temporary_received_by_me
        FROM history_transfer
        WHERE transfer_user_id=:u_id OR recipient_user_id=:u_id
    ";

    $stmt = $dbh->prepare($sql);
    $stmt->bindValue(':u_id', $u_id, PDO::PARAM_INT);
    $stmt->execute();
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "data" => [
            "โอนย้ายถาวร_ผู้โอน" => (int)$summary['permanent_transfer_by_me'],
            "โอนย้ายถาวร_ผู้รับ" => (int)$summary['permanent_received_by_me'],
            "โอนย้ายชั่วคราว_ผู้โอน" => (int)$summary['temporary_transfer_by_me'],
            "โอนย้ายชั่วคราว_ผู้รับ" => (int)$summary['temporary_received_by_me']
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status"=>"error","message"=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
