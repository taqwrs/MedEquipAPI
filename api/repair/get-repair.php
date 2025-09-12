<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method required"]);
    exit;
}

try {

    $query = "SELECT 
        r.repair_id,
        r.equipment_id,
        e.name AS equipment_name,
        e.asset_code,
        r.title,
        r.remark,
        r.request_date,
        r.location,
        r.status,
        u.full_name AS reporter,
        rt.name_type AS repair_type,
        gu.group_name
    FROM repair r
    LEFT JOIN equipments e ON r.equipment_id = e.equipment_id
    LEFT JOIN users u ON r.user_id = u.user_id
    LEFT JOIN repair_type rt ON r.repair_type_id = rt.repair_type_id
    LEFT JOIN group_user gu ON rt.group_user_id = gu.group_user_id
    ORDER BY r.request_date DESC";

    $stmt = $dbh->query($query);
    $repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($repairs as &$repair) {
        $sql2 = "SELECT rr.*, sp.name AS spare_name
                 FROM repair_result rr
                 LEFT JOIN spare_parts sp ON sp.spare_part_id = rr.spare_part_id
                 WHERE rr.repair_id = ?";
        $stmt2 = $dbh->prepare($sql2);
        $stmt2->execute([$repair['repair_id']]);
        $repairResults = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        $spares = [];
        foreach ($repairResults as $row) {
            $spares[] = [
                "spare_part_id" => $row["spare_part_id"],
                "spare_name"    => $row["spare_name"],
                "performed_date"=> $row["performed_date"],
                "solution"      => $row["solution"],
                "cost"          => $row["cost"],
                "status"        => $row["status"],
                "remark"        => $row["remark"]
            ];
        }

        $repair['repair_results'] = $spares;
    }

    echo json_encode([
        "status" => "success",
        "data" => $repairs
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
