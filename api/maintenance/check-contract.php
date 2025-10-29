<?php
include "../config/jwt.php";

$isJsonRequest = (strpos(strtolower(getenv("CONTENT_TYPE")), 'application/json') !== false);
$input = $isJsonRequest ? json_decode(file_get_contents('php://input'), true) : $_POST;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

// รับค่า input
$contract   = $input['contract'] ?? null;
$company_id = $input['company_id'] ?? null;
$plan_id    = $input['plan_id'] ?? 0;

if (empty($contract) || empty($company_id)) {
    echo json_encode(["status" => "error", "message" => "contract และ company_id required"]);
    exit;
}

try {
    $stmt = $dbh->prepare("
        SELECT COUNT(*) 
        FROM maintenance_plans 
        WHERE contract = :contract 
          AND company_id = :company_id
          AND plan_id != :plan_id
    ");
    $stmt->execute([
        ':contract' => $contract,
        ':company_id' => $company_id,
        ':plan_id' => $plan_id
    ]);
    $count = $stmt->fetchColumn();

    if ($count > 0) {
        echo json_encode([
            "status" => "error",
            "message" => "เลขที่สัญญาซ้ำในบริษัทเดียวกัน"
        ]);
    } 
} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}