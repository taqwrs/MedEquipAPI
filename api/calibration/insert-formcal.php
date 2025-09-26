<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method !!!"]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(["status" => "error", "message" => "Invalid JSON"]);
    exit;
}

$required_fields = ['plan_name', 'user_id', 'group_user_id', 'company_id', 'frequency_number', 'frequency_unit', 'frequency_type', 'start_date', 'end_date', 'type_cal'];

foreach ($required_fields as $field) {
    if (!array_key_exists($field, $input)) {
        echo json_encode(["status" => "error", "message" => "Missing field: $field"]);
        exit;
    }
}

$allowed_type_cal = ['ภายใน', 'ภายนอก'];
$allowed_cost_type = ['แยกรายรอบ', 'รวมตลอดทั้งสัญญา'];
$allowed_frequency_unit = [1, 2, 3, 4];

if (!in_array($input['type_cal'], $allowed_type_cal)) {
    echo json_encode(["status" => "error", "message" => "Invalid type_cal"]);
    exit;
}
if (!in_array($input['cost_type'], $allowed_cost_type)) {
    echo json_encode(["status" => "error", "message" => "Invalid cost_type"]);
    exit;
}
if (!in_array((int) $input['frequency_unit'], $allowed_frequency_unit)) {
    echo json_encode(["status" => "error", "message" => "Invalid frequency_unit"]);
    exit;
}

try {
    $dbh->beginTransaction();

    $startDate = new DateTime($input['start_date']);
    $endDate = new DateTime($input['end_date']);
    $intervalNumber = (int) $input['frequency_number'];
    $intervalUnit = (int) $input['frequency_unit'];

    if ($input['frequency_type'] === 'รอบเดียว') {
        $intervalCount = 1; 
    } else {
        $intervalCount = 0;
        $tempDate = clone $startDate;
        while ($tempDate <= $endDate) {
            $intervalCount++;
            switch ($intervalUnit) {
                case 1:
                    $tempDate->add(new DateInterval('P' . $intervalNumber . 'D'));
                    break;
                case 2:
                    $tempDate->add(new DateInterval('P' . ($intervalNumber * 7) . 'D'));
                    break;
                case 3:
                    $tempDate->add(new DateInterval('P' . $intervalNumber . 'M'));
                    break;
                case 4:
                    $tempDate->add(new DateInterval('P' . $intervalNumber . 'Y'));
                    break;
            }
        }
    }

    $stmt = $dbh->prepare("INSERT INTO calibration_plans 
    (plan_name, user_id, group_user_id, company_id, frequency_number, frequency_unit, frequency_type, interval_count, contract, start_waranty, start_date, end_date, cost_type, price, type_cal, is_active)
    VALUES (:plan_name, :user_id, :group_user_id, :company_id, :frequency_number, :frequency_unit, :frequency_type, :interval_count, :contract, :start_waranty, :start_date, :end_date, :cost_type, :price, :type_cal, :is_active)
");

    $stmt->execute([
        ':plan_name' => $input['plan_name'],
        ':user_id' => $input['user_id'],
        ':group_user_id' => $input['group_user_id'],
        ':company_id' => $input['company_id'],
        ':frequency_number' => $intervalNumber,
        ':frequency_unit' => $intervalUnit,
        ':frequency_type' => $input['frequency_type'],
        ':interval_count' => $intervalCount,
        ':contract' => !empty($input['contract']) ? $input['contract'] : null,
        ':start_waranty' => !empty($input['start_waranty']) ? $input['start_waranty'] : null,
        ':start_date' => $input['start_date'],
        ':end_date' => $input['end_date'],
        ':cost_type' => $input['cost_type'],
        ':price' => $input['price'],
        ':type_cal' => $input['type_cal'],
        ':is_active' => $input['is_active'] ?? 1
    ]);

    $plan_id = $dbh->lastInsertId();

    $detailsStmt = $dbh->prepare("INSERT INTO details_calibration_plans (plan_id,start_date) VALUES (:plan_id,:start_date)");

    if ($input['frequency_type'] === 'รอบเดียว') {
        $detailsStmt->execute([
            ':plan_id' => $plan_id,
            ':start_date' => $startDate->format('Y-m-d')
        ]);
    } else {
        $scheduledDate = clone $startDate;
        for ($i = 1; $i <= $intervalCount; $i++) {
            $detailsStmt->execute([
                ':plan_id' => $plan_id,
                ':start_date' => $scheduledDate->format('Y-m-d')
            ]);
            switch ($intervalUnit) {
                case 1:
                    $scheduledDate->add(new DateInterval('P' . $intervalNumber . 'D'));
                    break;
                case 2:
                    $scheduledDate->add(new DateInterval('P' . ($intervalNumber * 7) . 'D'));
                    break;
                case 3:
                    $scheduledDate->add(new DateInterval('P' . $intervalNumber . 'M'));
                    break;
                case 4:
                    $scheduledDate->add(new DateInterval('P' . $intervalNumber . 'Y'));
                    break;
            }
        }
    }

    // ลบส่วนการอัปโหลดไฟล์ออก - จะใช้ upload-file-calplan.php แทน

    $dbh->commit();
    echo json_encode(["status" => "success", "plan_id" => $plan_id]);

} catch (Exception $e) {
    if ($dbh->inTransaction())
        $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>