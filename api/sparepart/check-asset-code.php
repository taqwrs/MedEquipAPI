<?php
include "../config/jwt.php";

$isJsonRequest = (strpos(strtolower(getenv("CONTENT_TYPE")), 'application/json') !== false);
$input = $isJsonRequest ? json_decode(file_get_contents('php://input'), true) : $_POST;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

$type = $input['type'] ?? 'equipment'; // 'equipment' หรือ 'spare'
$asset_code = trim($input['asset_code'] ?? '');
$id = $input['id'] ?? null; // id ถ้ามี

if (!$asset_code) {
    echo json_encode(["status" => "error", "message" => "Missing field: asset_code"]);
    exit;
}

$table = $type === 'spare' ? 'spare_parts' : 'equipments';
$idField = $type === 'spare' ? 'spare_part_id' : 'equipment_id';

// Query เดิม
$sql = "SELECT COUNT(*) as cnt FROM $table WHERE asset_code = :asset_code";

// ถ้ามี id = กำลังอัปเดต → ไม่เช็คตัวเอง
$params = [":asset_code" => $asset_code];

if ($id) {
    $sql .= " AND $idField != :id";
    $params[":id"] = $id;
}

$stmt = $dbh->prepare($sql);
$stmt->execute($params);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row['cnt'] > 0) {
    echo json_encode(["status" => "error", "message" => "รหัสทรัพย์สินนี้มีอยู่แล้ว"]);
    exit;
}

echo json_encode(["status" => "success", "message" => "asset_code OK"]);
exit;
