<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $input = $method === 'POST' ? json_decode(file_get_contents("php://input"), true) ?? [] : $_GET;

    $search = trim($input['search'] ?? '');
    $page = (int)($input['page'] ?? 1);
    $limit = (int)($input['limit'] ?? 10);
    $offset = ($page - 1) * $limit;
    $useLimit = $limit > 0;

    // Build WHERE conditions
    $where = ["cp.is_active IN (1)"];
    $params = [];

    if ($search !== '') {
        $where[] = "(cp.plan_name LIKE :search OR u.full_name LIKE :search OR c.name LIKE :search OR gu.group_name LIKE :search)";
        $params[':search'] = "%$search%";
    }

    $whereSQL = "WHERE " . implode(" AND ", $where);

    // Main query
    $query = "
        SELECT cp.*, u.full_name AS user_name, gu.group_name, c.name AS company_name,
               COUNT(DISTINCT dcp.details_cal_id) AS total_schedules
        FROM calibration_plans cp
        LEFT JOIN users u ON cp.user_id = u.user_id
        LEFT JOIN group_user gu ON cp.group_user_id = gu.group_user_id
        LEFT JOIN companies c ON cp.company_id = c.company_id
        LEFT JOIN details_calibration_plans dcp ON cp.plan_id = dcp.plan_id
        $whereSQL
        GROUP BY cp.plan_id
        ORDER BY cp.plan_id DESC
    ";


    $countQuery = "
        SELECT COUNT(DISTINCT cp.plan_id) 
        FROM calibration_plans cp
        LEFT JOIN users u ON cp.user_id = u.user_id
        LEFT JOIN group_user gu ON cp.group_user_id = gu.group_user_id
        LEFT JOIN companies c ON cp.company_id = c.company_id
        $whereSQL
    ";

  
    $countStmt = $dbh->prepare($countQuery);
    foreach ($params as $k => $v) $countStmt->bindValue($k, $v, PDO::PARAM_STR);
    $countStmt->execute();
    $totalItems = (int)$countStmt->fetchColumn();

    // Limit
    if ($useLimit) $query .= " LIMIT :limit OFFSET :offset";

    $stmt = $dbh->prepare($query);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
    if ($useLimit) {
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $planIds = array_column($results, 'plan_id');

    // ดึงอุปกรณ์
    $equipmentsMap = [];
    if (!empty($planIds)) {
        $inQuery = implode(',', array_fill(0, count($planIds), '?'));
        $equipStmt = $dbh->prepare("
            SELECT pe.plan_id, e.equipment_id, e.name, e.asset_code
            FROM plan_equipments pe
            LEFT JOIN equipments e ON pe.equipment_id = e.equipment_id
            WHERE pe.plan_id IN ($inQuery)
        ");
        $equipStmt->execute($planIds);
        $equipmentsRaw = $equipStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($equipmentsRaw as $eq) {
            $equipmentsMap[$eq['plan_id']][] = [
                "equipment_id" => $eq['equipment_id'] ? (int)$eq['equipment_id'] : null,
                "name" => $eq['name'] ?? "-",
                "asset_code" => $eq['asset_code'] ?? "-",
            ];
        }
    }

    // ดึงไฟล์แนบ
    $filesMap = [];
    if (!empty($planIds)) {
        $inQuery = implode(',', array_fill(0, count($planIds), '?'));
        $fileStmt = $dbh->prepare("
            SELECT plan_id, file_cal_id, file_cal_name, file_cal_url, cal_type_name
            FROM file_cal
            WHERE plan_id IN ($inQuery)
        ");
        $fileStmt->execute($planIds);
        $filesRaw = $fileStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($filesRaw as $file) {
            $filesMap[$file['plan_id']][] = [
                "file_cal_id" => (int)$file['file_cal_id'],
                "file_cal_name" => $file['file_cal_name'],
                "file_cal_url" => $file['file_cal_url'],
                "cal_type_name" => $file['cal_type_name']
            ];
        }
    }

    // Mapping
    foreach ($results as &$res) {
        $res['equipments'] = $equipmentsMap[$res['plan_id']] ?? [];
        $res['files'] = $filesMap[$res['plan_id']] ?? [];
        $res['frequency_number'] = (int)$res['frequency_number'];
        $res['interval_count'] = (int)$res['interval_count'];
        $res['is_active'] = (int)$res['is_active'];
        $res['price'] = isset($res['price']) ? (float)$res['price'] : 0;
        $res['frequency_display'] = "ทุก {$res['frequency_number']} " . 
            match ((int)$res['frequency_unit']) {
                1 => 'วัน',
                2 => 'สัปดาห์',
                3 => 'เดือน',
                4 => 'ปี',
                default => 'หน่วย',
            };
        $res['status_display'] = $res['is_active'] ? 'ใช้งาน' : 'ไม่ใช้งาน';
    }

    echo json_encode([
        "status" => "success",
        "data" => array_values($results),
        "pagination" => [
            "totalItems" => $totalItems,
            "totalPages" => $useLimit ? ceil($totalItems / $limit) : 1,
            "currentPage" => $page,
            "limit" => $limit
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
