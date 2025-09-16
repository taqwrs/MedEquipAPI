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
        r.status AS repair_status,
        u_reporter.full_name AS reporter,
        rt.name_type AS repair_type,
        gu.group_name AS responsible_group
    FROM repair r
    LEFT JOIN equipments e ON r.equipment_id = e.equipment_id
    LEFT JOIN users u_reporter ON r.user_id = u_reporter.user_id
    LEFT JOIN repair_type rt ON r.repair_type_id = rt.repair_type_id
    LEFT JOIN group_user gu ON rt.group_user_id = gu.group_user_id
    ORDER BY r.repair_id DESC";

    $stmt = $dbh->query($query);
    $repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($repairs as &$repair) {
        $sql2 = "SELECT 
                    rr.repair_result_id,
                    rr.user_id AS responsible_id,
                    u_responsible.full_name AS responsible_name,
                    rr.performed_date,
                    rr.solution,
                    rr.cost,
                    rr.status,
                    rr.remark,
                    sp.spare_part_id,
                    sp.name AS spare_name
                 FROM repair_result rr
                 LEFT JOIN users u_responsible ON rr.user_id = u_responsible.user_id
                 LEFT JOIN spare_parts_used spu ON rr.repair_result_id = spu.repair_result_id
                 LEFT JOIN spare_parts sp ON spu.spare_part_id = sp.spare_part_id
                 WHERE rr.repair_id = ?";
        $stmt2 = $dbh->prepare($sql2);
        $stmt2->execute([$repair['repair_id']]);
        $repairResults = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($repairResults as $row) {

            $stmtFiles = $dbh->prepare("SELECT repair_file_name, repair_file_url, repair_type_name
                                        FROM file_repair_result
                                        WHERE repair_result_id = ?");
            $stmtFiles->execute([$row['repair_result_id']]);
            $files = $stmtFiles->fetchAll(PDO::FETCH_ASSOC);

            $results[] = [
                "repair_result_id" => $row["repair_result_id"],
                "responsible_id"   => $row["responsible_id"],
                "responsible_name" => $row["responsible_name"],
                "performed_date"   => $row["performed_date"],
                "solution"         => $row["solution"],
                "cost"             => $row["cost"],
                "status"           => $row["status"],
                "remark"           => $row["remark"],
                "spare_part_id"    => $row["spare_part_id"],
                "spare_name"       => $row["spare_name"],
                "files"            => $files
            ];
        }

        $repair['repair_results'] = $results;
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
