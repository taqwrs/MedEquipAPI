<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];

try {
    // Get input parameters
    $input = [];
    if ($method === 'POST') {
        $input = json_decode(file_get_contents("php://input"), true) ?? [];
    } else {
        $input = $_GET;
    }
    
    $search = trim($input['search'] ?? '');
    $statusFilter = trim($input['status'] ?? '');
    $page = (int)($input['page'] ?? 1);
    $limit = (int)($input['limit'] ?? 5);
    $offset = ($page - 1) * $limit;
    $useLimit = $limit > 0;

    // Build WHERE conditions
    $where = ["cp.is_active = 1"];
    $params = [];

    // Search functionality
    if ($search !== '') {
        $where[] = "(cp.plan_name LIKE :search OR u.full_name LIKE :search OR c.name LIKE :search OR gu.group_name LIKE :search)";
        $params[':search'] = "%$search%";
    }

    // Status filter
    // if ($statusFilter !== '') {
    //     if ($statusFilter === 'active') {
    //         $where[] = "cp.is_active = 1";
    //     } elseif ($statusFilter === 'inactive') {
    //         $where[] = "cp.is_active = 0";
    //         $where = array_filter($where, function($condition) {
    //             return $condition !== "cp.is_active = 1";
    //         });
    //     }
    // }

    $whereSQL = "WHERE " . implode(" AND ", $where);

    $query = "
        SELECT 
            cp.*,
            u.full_name AS user_name,
            gu.group_name,
            c.name AS company_name,
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

    // Execute count query
    $countStmt = $dbh->prepare($countQuery);
    foreach ($params as $key => $val) {
        $countStmt->bindValue($key, $val, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $totalItems = (int)$countStmt->fetchColumn();

    if ($useLimit) {
        $query .= " LIMIT :limit OFFSET :offset";
    }
    $stmt = $dbh->prepare($query);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val, PDO::PARAM_STR);
    }
    if ($useLimit) {
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    }
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $planIds = array_column($results, 'plan_id');

    $boundDevicesMap = [];
    if (!empty($planIds)) {
        $inQuery = implode(',', array_fill(0, count($planIds), '?'));
        $boundStmt = $dbh->prepare("
            SELECT pe.plan_id, e.equipment_id, e.name, e.asset_code
            FROM plan_equipments pe
            LEFT JOIN equipments e ON pe.equipment_id = e.equipment_id
            WHERE pe.plan_id IN ($inQuery)
        ");
        $boundStmt->execute($planIds);
        $boundDevicesRaw = $boundStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($boundDevicesRaw as $bd) {
            $boundDevicesMap[$bd['plan_id']][] = [
                "equipment_id" => $bd['equipment_id'] !== null ? (int)$bd['equipment_id'] : null,
                "name" => $bd['name'] ?? "-",
                "asset_code" => $bd['asset_code'] ?? "-",
            ];
        }
    }


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


    foreach ($results as &$result) {
        switch ((int)$result['frequency_unit']) {
            case 1: $unit_text = 'วัน'; break;
            case 2: $unit_text = 'สัปดาห์'; break;
            case 3: $unit_text = 'เดือน'; break;
            case 4: $unit_text = 'ปี'; break;
            default: $unit_text = 'หน่วย';
        }
        $result['frequency_display'] = "ทุก {$result['frequency_number']} {$unit_text}";
        $result['status_display'] = $result['is_active'] ? 'ใช้งาน' : 'ไม่ใช้งาน';
        $result['price'] = isset($result['price']) ? (float)$result['price'] : 0;
        $result['frequency_number'] = (int)$result['frequency_number'];
        $result['frequency_unit'] = (int)$result['frequency_unit'];
        $result['interval_count'] = (int)$result['interval_count'];
        $result['is_active'] = (int)$result['is_active'];
        $result['total_schedules'] = (int)$result['total_schedules'];
        $result['equipments'] = $boundDevicesMap[$result['plan_id']] ?? [];
        $result['files'] = $filesMap[$result['plan_id']] ?? [];
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