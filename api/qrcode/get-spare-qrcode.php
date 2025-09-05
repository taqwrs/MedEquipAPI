<?php
include "../config/jwt.php";
require_once "../vendor/autoload.php";
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

$input = json_decode(file_get_contents('php://input'), true);
$sparePartId = $input['spare_part_id'] ?? null;

if (!$sparePartId) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Missing spare_part_id']);
    exit;
}

try {
    $stmt = $dbh->prepare("
        SELECT sp.*, e.name AS equipment_name, e.asset_code AS equipment_asset_code
        FROM spare_parts sp
        LEFT JOIN equipments e ON e.equipment_id = sp.equipment_id
        WHERE sp.spare_part_id = :id
    ");
    $stmt->bindValue(':id', $sparePartId);
    $stmt->execute();
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        echo json_encode(['status'=>'error','message'=>'Not found']);
        exit;
    }

    // URL สำหรับสแกนมือถือ
    $qrData = "https://yourserver/spare-detail.php?id=".$sparePartId;

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
