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

if (!$asset_code) {
    echo json_encode(["status" => "error", "message" => "Missing field: asset_code"]);
    exit;
}

$table = $type === 'spare' ? 'spare_parts' : 'equipments';

$stmt = $dbh->prepare("SELECT COUNT(*) as cnt FROM $table WHERE asset_code = :asset_code");
$stmt->execute([':asset_code' => $asset_code]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row['cnt'] > 0) {
    echo json_encode(["status" => "error", "message" => "รหัสทรัพย์สินนี้มีอยู่แล้ว"]);
} 
