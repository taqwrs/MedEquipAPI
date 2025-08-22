<?php
include "../config/jwt.php"; 
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method required"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

if (!isset($input['department']) || !isset($input['NHPWU'])) {
    echo json_encode(["status" => "error", "message" => "กรุณาระบุ department และ NHPWU"]);
    exit;
}

$department = $input['department'];
$NHPWU = floatval($input['NHPWU']);
$date = isset($input['date']) ? $input['date'] : date('Y-m-d'); 

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
    echo json_encode(["status" => "error", "message" => "รูปแบบวันที่ไม่ถูกต้อง กรุณาใช้รูปแบบ YYYY-MM-DD"]);
    exit;
}

try {
    // ค้นหา ward_id
    $stmt = $dbh->prepare("SELECT ward_id FROM ward WHERE department = ?");
    $stmt->execute([$department]);
    $ward = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$ward) {
        echo json_encode(["status" => "error", "message" => "ไม่พบแผนก: " . $department]);
        exit;
    }

    $ward_id = $ward['ward_id'];

    $stmt = $dbh->prepare("SELECT NH_id, NHPWU FROM nursing_hours WHERE ward_id = ? AND active = 1");
    $stmt->execute([$ward_id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        $oldNHPWU = $existing['NHPWU'];

        $stmt = $dbh->prepare("UPDATE nursing_hours SET NHPWU = ?, user_id = ?, date = ? WHERE NH_id = ?");
        $stmt->execute([$NHPWU, $user_id, $date, $existing['NH_id']]);
        $stmt = $dbh->prepare("INSERT INTO nursing_hours_log (NH_id, ward_id, department, old_NHPWU, new_NHPWU, user_id)
                               VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $existing['NH_id'],
            $ward_id,
            $department,
            $oldNHPWU,
            $NHPWU,
            $user_id
        ]);

        echo json_encode([
            "status" => "success",
            "message" => "อัปเดต NHPWU และวันที่สำหรับ " . $department . " เรียบร้อย",
            "data" => [
                "department" => $department,
                "NHPWU" => $NHPWU,
                "date" => $date,
                "NH_id" => $existing['NH_id']
            ]
        ]);
    } else {
        echo json_encode([
            "status" => "warning",
            "message" => "ไม่พบข้อมูล nursing_hours ของ " . $department . " ที่ active = 1"
        ]);
    }

} catch (PDOException $e) {
    echo json_encode([
        "status" => "error",
        "message" => "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . $e->getMessage()
    ]);
}
?>
