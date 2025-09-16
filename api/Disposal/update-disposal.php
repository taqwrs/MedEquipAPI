<?php
include "../config/jwt.php"; 
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['writeoff_id']) || !isset($data['user_id']) || !isset($data['status'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
    exit;
}

$writeoff_id = $data['writeoff_id'];
$user_id = $data['user_id'];
$status = $data['status'];

try {

    $dbh->beginTransaction();

    if ($status === "approved") {
        $stmt = $dbh->prepare("
            UPDATE write_offs 
            SET status = 'อนุมัติแล้ว', 
                approved_by = ?, 
                approved_date = NOW()
            WHERE writeoff_id = ?
        ");
        $stmt->execute([$user_id, $writeoff_id]);


        $stmt = $dbh->prepare("SELECT equipment_id FROM write_offs WHERE writeoff_id = ?");
        $stmt->execute([$writeoff_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $equipment_id = $row['equipment_id'];

        $stmt = $dbh->prepare("UPDATE equipments SET active = 0 WHERE equipment_id = ?");
        $stmt->execute([$equipment_id]);

        $message = "อนุมัติสำเร็จ";

    } else if ($status === "rejected") {
        $stmt = $dbh->prepare("
            UPDATE write_offs 
            SET status = 'ไม่อนุมัติ', 
                approved_by = ?, 
                approved_date = NOW()
            WHERE writeoff_id = ?
        ");
        $stmt->execute([$user_id, $writeoff_id]);

        $message = "ไม่อนุมัติสำเร็จ";
    } else {
        throw new Exception("สถานะไม่ถูกต้อง");
    }


    $dbh->commit();

    echo json_encode(['status' => 'success', 'message' => $message]);

} catch (Exception $e) {
    $dbh->rollBack();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
