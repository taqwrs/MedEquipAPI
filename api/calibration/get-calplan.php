<?php
include "../config/jwt.php";

$input = json_decode(file_get_contents('php://input'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "post method!!!"]);
    exit;
}

try {
    $query = "
        SELECT 
            cp.*,
            u.full_name AS user_name,
            gu.group_name,
            c.name AS company_name,
            COUNT(DISTINCT dcp.details_cal_id) AS total_schedules,
            COUNT(DISTINCT cr.calibration_id) AS completed_calibrations
        FROM calibration_plans cp
        LEFT JOIN users u ON cp.user_id = u.ID
        LEFT JOIN group_user gu ON cp.group_user_id = gu.group_user_id
        LEFT JOIN companies c ON cp.company_id = c.company_id
        LEFT JOIN details_calibration_plans dcp ON cp.plan_id = dcp.plan_id
        LEFT JOIN calibration_result cr ON dcp.details_cal_id = cr.details_cal_id
        GROUP BY cp.plan_id
    ";
    $stmt = $dbh->prepare($query);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $planIds = array_column($results, 'plan_id');
    $boundDevicesMap = [];

    if (!empty($planIds)) {
        $inQuery = implode(',', array_fill(0, count($planIds), '?'));
        $boundStmt = $dbh->prepare("
            SELECT pe.plan_id, e.equipment_id, e.name, e.asset_code, e.model, e.location_department_id, e.location_details
            FROM plan_equipments pe
            LEFT JOIN equipments e ON pe.equipment_id = e.equipment_id
            WHERE pe.plan_id IN ($inQuery)
        ");
        $boundStmt->execute($planIds);
        $boundDevicesRaw = $boundStmt->fetchAll(PDO::FETCH_ASSOC);
        file_put_contents('php://stderr', print_r($boundDevicesRaw, true));

        foreach ($boundDevicesRaw as $bd) {
            $boundDevicesMap[$bd['plan_id']][] = [
                "equipment_id" => $bd['equipment_id'] !== null ? (int)$bd['equipment_id'] : null,
                "name" => $bd['name'] ?? "-",
                "asset_code" => $bd['asset_code'] ?? "-",
            ];
        }
    }

    // 3️⃣ จัดการข้อมูล plan
    foreach ($results as &$result) {
        switch ((int)$result['frequency_unit']) {
            case 1: $unit_text = 'วัน'; break;
            case 2: $unit_text = 'เดือน'; break;
            case 3: $unit_text = 'ปี'; break;
            default: $unit_text = 'หน่วย';
        }
        $result['frequency_display'] = "ทุก {$result['frequency_number']} {$unit_text}";
        $result['status_display'] = $result['is_active'] ? 'ใช้งาน' : 'ไม่ใช้งาน';

        $total = (int)$result['total_schedules'];
        $completed = (int)$result['completed_calibrations'];
        $result['progress'] = $total > 0 ? round(($completed / $total) * 100, 2) : 0;

        $result['price'] = isset($result['price']) ? (float)$result['price'] : 0;
        $result['frequency_number'] = (int)$result['frequency_number'];
        $result['frequency_unit'] = (int)$result['frequency_unit'];
        $result['interval_count'] = (int)$result['interval_count'];
        $result['is_active'] = (int)$result['is_active'];
        $result['equipments'] = $boundDevicesMap[$result['plan_id']] ?? [];
    }
    $users = $dbh->query("SELECT ID AS user_id, full_name FROM users")->fetchAll(PDO::FETCH_ASSOC);
    $companies = $dbh->query("SELECT company_id, name FROM companies")->fetchAll(PDO::FETCH_ASSOC);
    $departments = $dbh->query("SELECT department_id, department_name AS name FROM departments")->fetchAll(PDO::FETCH_ASSOC);
    $groups = $dbh->query("SELECT group_user_id, group_name FROM group_user")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode([
        "status" => "success",
        "data" => array_values($results),
        "users" => $users,
        "companies" => $companies,
        "departments" => $departments,
        "groups" => $groups
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
