<?php
include "../config/jwt.php";
require_once "../vendor/autoload.php";
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

$input = json_decode(file_get_contents('php://input'), true);
$equipmentId = $input['equipment_id'] ?? null;

if (!$equipmentId) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Missing equipment_id']);
    exit;
}

try {
    $stmt = $dbh->prepare("
        SELECT e.*, 
            COALESCE(CONCAT('[', GROUP_CONCAT(DISTINCT JSON_OBJECT(
                'equipment_id', ce.equipment_id,
                'name', ce.name,
                'asset_code', ce.asset_code
            )), ']'), '[]') AS child_equipments,
            COALESCE(CONCAT('[', GROUP_CONCAT(DISTINCT JSON_OBJECT(
                'spare_part_id', sp.spare_part_id,
                'name', sp.name,
                'asset_code', sp.asset_code
            )), ']'), '[]') AS spareParts
        FROM equipments e
        LEFT JOIN equipments ce ON ce.main_equipment_id = e.equipment_id
        LEFT JOIN spare_parts sp ON sp.equipment_id = e.equipment_id
        WHERE e.equipment_id = :id
        GROUP BY e.equipment_id
    ");
    $stmt->bindValue(':id', $equipmentId);
    $stmt->execute();
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        echo json_encode(['status'=>'error','message'=>'Not found']);
        exit;
    }

    // URL สำหรับสแกน
    $frontendBaseUrl = "https://medequipment.tsh"; // เปลี่ยนเป็น https://yourdomain.com เมื่อ deploy จริง
    $qrData = "{$frontendBaseUrl}/qr-detail/{$equipmentId}?type=equipment";
    // $qrData = "https://yourserver/equipment-detail.php?id=".$equipmentId;

    $qrCode = QrCode::create($qrData)->setSize(300);
    $writer = new PngWriter();
    $result = $writer->write($qrCode);

    echo json_encode([
        'status'=>'success',
        'data'=>array_merge($data, ['qr_url'=>$qrData]), // เพิ่ม URL สำหรับมือถือ
        'qr_code'=>'data:image/png;base64,'.base64_encode($result->getString())
    ]);

} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
