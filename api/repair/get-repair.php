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
    $input = json_decode(file_get_contents("php://input"), true);
    $search = isset($input['search']) ? trim($input['search']) : "";
    $statusFilter = isset($input['status_filter']) ? trim($input['status_filter']) : "";

    $where = [];
    $params = [];

    if ($search !== "") {
        $where[] = "(r.remark LIKE ? OR r.location LIKE ? OR u_reporter.full_name LIKE ? OR e.asset_code LIKE ? OR e.name LIKE ?)";
        $params = array_merge($params, array_fill(0, 5, "%$search%"));
    }

    if ($statusFilter !== "") {
        if ($statusFilter === "ซ่อมเสร็จ" || $statusFilter === "ซ่อมไม่ได้") {
            $where[] = "EXISTS (
                SELECT 1 FROM repair_result rr
                WHERE rr.repair_id = r.repair_id
                AND rr.status = ?
                ORDER BY rr.repair_result_id DESC
                LIMIT 1
            )";
            $params[] = $statusFilter;
        } else {
            $where[] = "r.status = ?";
            $params[] = $statusFilter;
        }
    }

    $whereSQL = $where ? "WHERE " . implode(" AND ", $where) : "";

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
    $whereSQL
    Where r.active = 1
    ORDER BY r.repair_id DESC";

    $stmt = $dbh->prepare($query);
    $stmt->execute($params);
    $repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);


    $sqlCounter = "SELECT 
                        e.equipment_id,
                        COUNT(rr.repair_result_id) AS repair_count
                   FROM equipments e
                   LEFT JOIN repair r ON e.equipment_id = r.equipment_id
                   LEFT JOIN repair_result rr ON r.repair_id = rr.repair_id
                   GROUP BY e.equipment_id";
    $stmtCounter = $dbh->prepare($sqlCounter);
    $stmtCounter->execute();
    $equipmentCounts = $stmtCounter->fetchAll(PDO::FETCH_ASSOC);

    $equipmentRepairs = [];
    foreach ($equipmentCounts as $eq) {
        $equipmentRepairs[$eq['equipment_id']] = $eq['repair_count'];
    }


    foreach ($repairs as &$repair) {
        $repair['counter'] = $equipmentRepairs[$repair['equipment_id']] ?? 0;

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
                 WHERE rr.repair_id = ?
                 ORDER BY rr.repair_result_id ASC";
        $stmt2 = $dbh->prepare($sql2);
        $stmt2->execute([$repair['repair_id']]);
        $repair['repair_results'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
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
