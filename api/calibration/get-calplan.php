<?php
include "../config/jwt.php";
$input = json_decode(file_get_contents('php://input'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array("status" => "error", "message" => "post method!!!"));
    die();
}

try {

    $query = "
        SELECT 
            cp.*,
            e.name as equipment_name,
            e.asset_code,
            e.model,
            u.full_name as user_name,
            gu.group_name,
            c.name as company_name,
            d.department_name,
            COUNT(dcp.details_cal_id) as total_schedules,
            COUNT(cr.calibration_id) as completed_calibrations
        FROM calibration_plans cp
        LEFT JOIN equipments e ON cp.equipment_id = e.equipment_id
        LEFT JOIN users u ON cp.user_id = u.ID
        LEFT JOIN group_user gu ON cp.group_user_id = gu.group_user_id
        LEFT JOIN companies c ON cp.company_id = c.company_id
        LEFT JOIN departments d ON e.location_department_id = d.department_id
        LEFT JOIN details_calibration_plans dcp ON cp.plan_id = dcp.plan_id
        LEFT JOIN calibration_result cr ON dcp.details_cal_id = cr.details_cal_id
        GROUP BY cp.plan_id 
        ORDER BY cp.plan_id DESC
    ";

    $stmt = $dbh->prepare($query);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($results as &$result) {
        switch ($result['frequency_unit']) {
            case 1:
                $unit_text = 'วัน';
                break;
            case 2:
                $unit_text = 'เดือน';
                break;
            case 3:
                $unit_text = 'ปี';
                break;
            default:
                $unit_text = 'หน่วย';
        }
        $result['frequency_display'] = "ทุก {$result['frequency_number']} {$unit_text}";
        $result['status_display'] = $result['is_active'] ? 'ใช้งาน' : 'ไม่ใช้งาน';
        $total = (int) $result['total_schedules'];
        $completed = (int) $result['completed_calibrations'];
        $result['progress'] = $total > 0 ? round(($completed / $total) * 100, 2) : 0;
        $result['price'] = (float) $result['price'];
        $result['frequency_number'] = (int) $result['frequency_number'];
        $result['frequency_unit'] = (int) $result['frequency_unit'];
        $result['interval_count'] = (int) $result['interval_count'];
        $result['is_active'] = (int) $result['is_active'];
    }

    echo json_encode([
        "status" => "success",
        "data" => array_values($results)
    ]);

} catch (Exception $e) {
    echo json_encode(array("status" => "error", "message" => $e->getMessage()));
}
