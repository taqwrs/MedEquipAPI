<?php
include "../config/jwt.php"; 
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];

try {
    // อนุญาตเฉพาะ GET
    if ($method !== 'GET') {
        echo json_encode(["status" => "error", "message" => "Method not allowed"]);
        exit;
    }

    // ดึง ENUM values ของ transfer_type
    $enumSql = "SHOW COLUMNS FROM equipment_transfers LIKE 'transfer_type'";
    $enumStmt = $dbh->prepare($enumSql);
    $enumStmt->execute();
    $enumRow = $enumStmt->fetch(PDO::FETCH_ASSOC);

    // regex แยกค่า ENUM('value1','value2',...)
    preg_match("/^enum\((.*)\)$/", $enumRow['Type'], $matches);
    $enumValues = [];
    if (!empty($matches[1])) {
        $enumValues = str_getcsv($matches[1], ',', "'");
    }

    // ส่งกลับ response
    echo json_encode([
        "status" => "ok",
        "transfer_type_enum" => $enumValues
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
