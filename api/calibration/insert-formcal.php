<?php

include "../config/jwt.php";

$input = json_decode(file_get_contents('php://input'), true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "post method!!!"]);
    exit;
}

try {
    $dbh->beginTransaction();

    $startDate = new DateTime($input['start_date']);
    $endDate = new DateTime($input['end_date']);
    $intervalNumber = (int)$input['frequency_number'];
    $intervalUnit = (int)$input['frequency_unit'];

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
            default:
                throw new Exception("Invalid frequency_unit");
        }
    }
    $stmt = $dbh->prepare("
        INSERT INTO calibration_plans 
            (plan_name, user_id, group_user_id, company_id, frequency_number, frequency_unit, frequency_type, interval_count, start_waranty, start_date, end_date, cost_type, price, type_cal, is_active) 
        VALUES 
            (:plan_name, :user_id, :group_user_id, :company_id, :frequency_number, :frequency_unit, :frequency_type, :interval_count, :start_waranty, :start_date, :end_date, :cost_type, :price, :type_cal, :is_active)
    ");

    $costType = $input['cost_type'];
$typeCal = $input['type_cal'];

$stmt->execute([
    ':plan_name' => $input['plan_name'],
    ':user_id' => $input['user_id'],
    ':group_user_id' => $input['group_user_id'],
    ':company_id' => $input['company_id'],
    ':frequency_number' => $intervalNumber,
    ':frequency_unit' => $intervalUnit,
    ':frequency_type' => $input['frequency_type'],
    ':interval_count' => $intervalCount,
    ':start_waranty' => $input['start_waranty'],
    ':start_date' => $input['start_date'],
    ':end_date' => $input['end_date'],
    ':cost_type' => $costType,
    ':price' => $input['price'],
    ':type_cal' => $typeCal,
    ':is_active' => $input['is_active'] ?? 1,


    ]);

    $plan_id = $dbh->lastInsertId();
    $detailsStmt = $dbh->prepare("
        INSERT INTO details_calibration_plans (plan_id, start_date)
        VALUES (:plan_id, :start_date)
    ");

    $scheduledDate = clone $startDate;
    for ($i = 1; $i <= $intervalCount; $i++) {
        $detailsStmt->execute([
            ':plan_id' => $plan_id,
            ':start_date' => $scheduledDate->format('Y-m-d'),
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

    $dbh->commit();
    echo json_encode(["status" => "success", "plan_id" => $plan_id]);

} catch (Exception $e) {
    $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
