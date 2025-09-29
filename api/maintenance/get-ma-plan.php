<?php
include "../config/jwt.php"; 

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];

try {
    // ดึงแผนบำรุงรักษา
    $query = "
        SELECT 
            mp.*,
            u.full_name AS user_name,
            gu.group_name,
            c.name AS company_name,
            COUNT(DISTINCT dmp.details_ma_id) AS total_schedules
        FROM maintenance_plans mp
        LEFT JOIN users u ON mp.user_id = u.id
        LEFT JOIN group_user gu ON mp.group_user_id = gu.group_user_id
        LEFT JOIN companies c ON mp.company_id = c.company_id
        LEFT JOIN details_maintenance_plans dmp ON mp.plan_id = dmp.plan_id
        WHERE mp.is_active = 1
        GROUP BY mp.plan_id DESC
    ";
    $stmt = $dbh->prepare($query);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $planIds = array_column($results, 'plan_id');

    // ดึงอุปกรณ์ในแต่ละแผน
    $boundDevicesMap = [];
    if (!empty($planIds)) {
        $inQuery = implode(',', array_fill(0, count($planIds), '?'));
        $boundStmt = $dbh->prepare("
            SELECT pe.plan_id, e.equipment_id, e.name, e.asset_code
            FROM plan_ma_equipments pe
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

    // ดึงไฟล์เอกสาร MA
    $filesMap = [];
    if (!empty($planIds)) {
        $inQuery = implode(',', array_fill(0, count($planIds), '?'));
        $fileStmt = $dbh->prepare("
            SELECT plan_id, file_ma_id, file_ma_name, file_ma_url, ma_type_name
            FROM file_ma
            WHERE plan_id IN ($inQuery)
        ");
        $fileStmt->execute($planIds);
        $filesRaw = $fileStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($filesRaw as $file) {
            $filesMap[$file['plan_id']][] = [
                "file_ma_id" => (int)$file['file_ma_id'],
                "file_ma_name" => $file['file_ma_name'],
                "file_ma_url" => $file['file_ma_url'],
                "ma_type_name" => $file['ma_type_name']
            ];
        }
    }

    // จัดรูปแบบผลลัพธ์
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
        $result['equipments'] = $boundDevicesMap[$result['plan_id']] ?? [];
        $result['files'] = $filesMap[$result['plan_id']] ?? [];
    }

    echo json_encode([
        "status" => "success",
        "data" => array_values($results)
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>
