<?php
include "../config/jwt.php";
require_once "../vendor/autoload.php";
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

// รับ input
$input = json_decode(file_get_contents('php://input'), true);
$type = $input['type'] ?? null; // 'equipment' หรือ 'spare'
$id   = $input['id'] ?? null;

if (!$type || !$id) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Missing type or id']);
    exit;
}

try {
    if ($type === "equipment") {
        $stmt = $dbh->prepare("SELECT * FROM equipments WHERE equipment_id = :id");
    } else {
        $stmt = $dbh->prepare("SELECT sp.*, e.name AS equipment_name, e.asset_code AS equipment_asset_code
                               FROM spare_parts sp
                               LEFT JOIN equipments e ON e.equipment_id = sp.equipment_id
                               WHERE sp.spare_part_id = :id");
    }
    $stmt->bindValue(':id', $id);
    $stmt->execute();
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        echo json_encode(['status'=>'error','message'=>'Not found']);
        exit;
    }

    // URL สำหรับมือถือ
    $qrData = $type === "equipment"
        ? "https://yourserver/equipment-detail.php?id=".$id
        : "https://yourserver/spare-detail.php?id=".$id;

    $qrCode = QrCode::create($qrData)->setSize(300);
    $writer = new PngWriter();
    $result = $writer->write($qrCode);

    echo json_encode([
        'status'=>'success',
        'data'=>array_merge($data, ['qr_url'=>$qrData]),
        'qr_code'=>'data:image/png;base64,'.base64_encode($result->getString())
    ]);

} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
