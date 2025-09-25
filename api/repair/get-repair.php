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

    // รับ input สำหรับค้นหาและแบ่งหน้า
    $search = trim($input['search'] ?? '');
    $statusFilter = trim($input['status_filter'] ?? '');
    $page = (int)($input['page'] ?? 1);
    $limit = (int)($input['limit'] ?? 5);
    $offset = ($page - 1) * $limit;
    $useLimit = $limit > 0;

    // -----------------------------
    // เงื่อนไข WHERE แบบ dynamic
    $where = ["r.active = 1"];
    $params = [];

    if ($search !== "") {
        $where[] = "(r.remark LIKE :search OR r.location LIKE :search OR u_reporter.full_name LIKE :search OR e.asset_code LIKE :search OR e.name LIKE :search)";
        $params[':search'] = "%$search%";
    }

    if ($statusFilter !== "") {
        if ($statusFilter === "ซ่อมเสร็จ" || $statusFilter === "ซ่อมไม่ได้") {
            $where[] = "EXISTS (
                SELECT 1 FROM repair_result rr
                WHERE rr.repair_id = r.repair_id
                AND rr.status = :statusFilter
                ORDER BY rr.repair_result_id DESC
                LIMIT 1
            )";
            $params[':statusFilter'] = $statusFilter;
        } else {
            $where[] = "r.status = :statusFilter";
            $params[':statusFilter'] = $statusFilter;
        }
    }

    $whereSQL = "WHERE " . implode(" AND ", $where);

    // -----------------------------
    // Query หลัก + pagination
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
    ORDER BY r.repair_id DESC";

    if ($useLimit) {
        $query .= " LIMIT :limit OFFSET :offset";
    }

    $stmt = $dbh->prepare($query);

    // Bind parameters
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    if ($useLimit) {
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    }

    $stmt->execute();
    $repairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // -----------------------------
    // นับจำนวนทั้งหมดสำหรับ pagination
    $countQuery = "SELECT COUNT(*) FROM repair r
                   LEFT JOIN equipments e ON r.equipment_id = e.equipment_id
                   LEFT JOIN users u_reporter ON r.user_id = u_reporter.user_id
                   LEFT JOIN repair_type rt ON r.repair_type_id = rt.repair_type_id
                   LEFT JOIN group_user gu ON rt.group_user_id = gu.group_user_id
                   $whereSQL";
    $countStmt = $dbh->prepare($countQuery);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $totalItems = (int)$countStmt->fetchColumn();

    // -----------------------------
    // เพิ่ม counter และ repair_results
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

    // -----------------------------
    // Summary สถานะซ่อม
    $summaryQuery = "SELECT 
                        SUM(CASE WHEN rr.status = 'ซ่อมเสร็จ' THEN 1 ELSE 0 END) AS completed,
                        SUM(CASE WHEN rr.status = 'ซ่อมไม่ได้' THEN 1 ELSE 0 END) AS failed,
                        SUM(CASE WHEN rr.status NOT IN ('ซ่อมเสร็จ','ซ่อมไม่ได้') THEN 1 ELSE 0 END) AS in_progress
                     FROM repair_result rr
                     LEFT JOIN repair r ON rr.repair_id = r.repair_id
                     WHERE r.active = 1";
    $summaryStmt = $dbh->prepare($summaryQuery);
    $summaryStmt->execute();
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

    // -----------------------------
    // ส่งผลลัพธ์รวม
    echo json_encode([
        "status" => "success",
        "data" => $repairs,
        "summary" => [
            "completed" => (int)$summary['completed'],
            "failed" => (int)$summary['failed'],
            "in_progress" => (int)$summary['in_progress']
        ],
        "pagination" => [
            "totalItems" => $totalItems,
            "totalPages" => $limit > 0 ? ceil($totalItems / $limit) : 1,
            "currentPage" => $page,
            "limit" => $limit
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>
