<?php
include "../config/jwt.php"; 

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method required"]);
    exit;
}

try {
    $data = json_decode(file_get_contents("php://input"), true);

    if (!isset($data["repair_id"])) {
        echo json_encode(["status" => "error", "message" => "repair_id is required"]);
        exit;
    }

    $repair_id = intval($data["repair_id"]);


    $sql = "SELECT r.*, e.name AS equipment_name, e.location_details, 
                   u.full_name AS user_name,
                   rt.name_type AS repair_type_name,
                   gu.group_name
            FROM repair r
            LEFT JOIN equipments e ON e.equipment_id = r.equipment_id
            LEFT JOIN users u ON u.user_id = r.user_id
            LEFT JOIN repair_type rt ON rt.repair_type_id = r.repair_type_id
            LEFT JOIN group_user gu ON gu.group_user_id = rt.group_user_id
            WHERE r.repair_id = ?";
    $stmt = $dbh->prepare($sql);
    $stmt->execute([$repair_id]);
    $repair = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$repair) {
        echo json_encode(["status" => "error", "message" => "Repair not found"]);
        exit;
    }

    $sql2 = "SELECT rr.*, sp.spare_part_id, sp.name AS spare_name
             FROM repair_result rr
             LEFT JOIN spare_parts sp ON sp.spare_part_id = rr.spare_part_id
             WHERE rr.repair_id = ?";
    $stmt2 = $dbh->prepare($sql2);
    $stmt2->execute([$repair_id]);
    $repairResults = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $spares = [];
    foreach ($repairResults as $row) {
        $spares[] = [
            "spare_part_id" => $row["spare_part_id"],
            "spare_name"    => $row["spare_name"],
            "performed_date"=> $row["performed_date"],
            "solution"      => $row["solution"],
            "cost"          => $row["cost"],
            "status"        => $row["status"]
        ];
    }

    // ================== Response ==================
    $response = [
        "repair" => [
            "repair_id" => $repair["repair_id"],
            "equipment_id" => $repair["equipment_id"],
            "equipment_name" => $repair["equipment_name"],
            "location" => $repair["location_details"] ?? $repair["location"],
            "user" => $repair["user_name"],
            "request_date" => $repair["request_date"],
            "repair_type" => $repair["repair_type_name"],
            "responsible_group" => $repair["group_name"],
            "status" => $repair["status"],
            "remark" => $repair["remark"]
        ],
        "repair_results" => $spares
    ];

    echo json_encode(["status" => "success", "data" => $response], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
