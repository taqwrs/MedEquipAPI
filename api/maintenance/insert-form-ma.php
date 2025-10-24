<?php
include "../config/jwt.php";
include "../config/LogModel.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method !!!"]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(["status" => "error", "message" => "Invalid JSON"]);
    exit;
}

$required_fields = ['plan_name', 'group_user_id', 'company_id', 'frequency_number', 'frequency_unit', 'frequency_type', 'start_date', 'end_date', 'type_ma'];
foreach ($required_fields as $field) {
    if (!array_key_exists($field, $input)) {
        echo json_encode(["status" => "error", "message" => "Missing field: $field"]);
        exit;
    }
}

$allowed_type_ma = ['ภายใน', 'ภายนอก'];
$allowed_cost_type = ['แยกรายรอบ', 'รวมตลอดทั้งสัญญา'];
$allowed_frequency_unit = [1, 2, 3, 4];

if (!in_array($input['type_ma'], $allowed_type_ma)) {
    echo json_encode(["status" => "error", "message" => "Invalid type_ma"]);
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
    $log = new LogModel($dbh);
    $user_id = $decoded->data->ID ?? null;
    if (!$user_id)
        throw new Exception("User ID not found");

    // คำนวณ interval_count
    $startDate = new DateTime($input['start_date']);
    $endDate = new DateTime($input['end_date']);
    $intervalNumber = (int) $input['frequency_number'];
    $intervalUnit = (int) $input['frequency_unit'];
    if ($endDate < $startDate) {
        echo json_encode(["status" => "error", "message" => "วันที่สิ้นสุดต้องไม่น้อยกว่าวันที่เริ่มต้น"]);
        exit;
    }

    $intervalCount = 1;
    if ($input['frequency_type'] !== 'รอบเดียว') {
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

    // เตรียมค่า insert
    $insertFields = [
        'plan_name',
        'user_id',
        'group_user_id',
        'company_id',
        'frequency_number',
        'frequency_unit',
        'frequency_type',
        'interval_count',
        'contract',
        'start_waranty',
        'start_date',
        'end_date',
        'cost_type',
        'price',
        'type_ma',
        'is_active'
    ];

    $insertData = [];
    foreach ($insertFields as $f) {
        if ($f === 'interval_count') {
            $insertData[$f] = $intervalCount;
        } else {
            $value = $input[$f] ?? null;
            if (in_array($f, ['start_waranty', 'start_date', 'end_date', 'contract']) && $value === '') {
                $value = null;
            }
            $insertData[$f] = $value;
        }
    }
    if (!isset($insertData['is_active']))
        $insertData['is_active'] = 1;
    // Force user_id from JWT (ignore any user_id sent from client)
    $insertData['user_id'] = $user_id;

    // ตรวจชื่อซ้ำ
    $stmtCheck = $dbh->prepare("SELECT COUNT(*) FROM maintenance_plans WHERE plan_name = :plan_name AND is_active = 1");
    $stmtCheck->execute([':plan_name' => $insertData['plan_name']]);
    if ($stmtCheck->fetchColumn() > 0) {
        echo json_encode(["status" => "error", "message" => "ชื่อแผนซ้ำ กรุณาเปลี่ยนชื่อแผนใหม่"]);
        exit;
    }
    // ตรวจ contract ซ้ำเฉพาะภายในบริษัทเดียว หากมีค่า contract ส่งมา
    if (!empty($insertData['contract'])) {
        $stmtContract = $dbh->prepare("
        SELECT COUNT(*) 
        FROM maintenance_plans 
        WHERE contract = :contract 
          AND company_id = :company_id
    ");
        $stmtContract->execute([
            ':contract' => $insertData['contract'],
            ':company_id' => $insertData['company_id']
        ]);
        if ($stmtContract->fetchColumn() > 0) {
            echo json_encode([
                "status" => "error",
                "message" => "เลขที่สัญญา ซ้ำกับแผนอื่นในบริษัทเดียวกัน"
            ]);
            exit;
        }
    }

    // Insert แผน MA
    $cols = implode(',', $insertFields);
    $placeholders = ':' . implode(',:', $insertFields);
    $stmt = $dbh->prepare("INSERT INTO maintenance_plans($cols) VALUES($placeholders)");
    $stmt->execute($insertData);
    $plan_id = $dbh->lastInsertId();

    // Insert details MA
    $detailsStmt = $dbh->prepare("INSERT INTO details_maintenance_plans (plan_id,start_date) VALUES (:plan_id,:start_date)");
    $allDates = [];

    if ($input['frequency_type'] === 'รอบเดียว') {
        $detailsStmt->execute([':plan_id' => $plan_id, ':start_date' => $startDate->format('Y-m-d')]);
        $allDates[] = $startDate->format('Y-m-d');
    } else {
        $scheduledDate = clone $startDate;
        for ($i = 1; $i <= $intervalCount; $i++) {
            $detailsStmt->execute([':plan_id' => $plan_id, ':start_date' => $scheduledDate->format('Y-m-d')]);
            $allDates[] = $scheduledDate->format('Y-m-d');

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

    // log row เดียว สำหรับทุก start_date
    $log->insertLog($user_id, 'details_maintenance_plans', 'INSERT', null, [
        'plan_id' => $plan_id,
        'start_dates' => $allDates
    ]);

    // Log auto-generate จาก array
    $logData = $insertData;
    $logData['plan_id'] = $plan_id;
    $log->insertLog($user_id, 'maintenance_plans', 'INSERT', null, $logData);

    $dbh->commit();
    echo json_encode(["status" => "success", "plan_id" => $plan_id]);
} catch (Exception $e) {
    if ($dbh->inTransaction())
        $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
