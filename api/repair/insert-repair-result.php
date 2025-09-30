<?php
include "../config/jwt.php";
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // รับข้อมูล JSON
    $input = json_decode(file_get_contents("php://input"), true);
    
    $repair_id      = $input['repair_id'] ?? null;
    $performed_date = $input['performed_date'] ?? null;
    $solution       = $input['solution'] ?? null;
    $cost           = $input['cost'] ?? null;
    $status         = $input['status'] ?? null; 
    $remark         = $input['remark'] ?? null;
    $spareParts     = $input['spareParts'] ?? [];
    $next_action    = $input['next_action'] ?? null;

    if (!$repair_id || !$performed_date || !$solution || $cost === null || !$status) {
        throw new Exception("ข้อมูลไม่ครบถ้วน");
    }

    $user_id = $input['user_id'] ?? null;
    if (!$user_id) throw new Exception("ไม่พบ user_id");

    if ($status === "ซ่อมไม่ได้" && !$next_action) {
        throw new Exception("กรุณาเลือกการดำเนินการต่อไป (next_action)");
    }

    $dbh->beginTransaction();

    $stmt = $dbh->prepare("
        INSERT INTO repair_result
        (repair_id, user_id, performed_date, solution, cost, status, remark, next_action)
        VALUES (:repair_id, :user_id, :performed_date, :solution, :cost, :status, :remark, :next_action)
    ");
    $stmt->execute([
        ':repair_id'      => $repair_id,
        ':user_id'        => $user_id,
        ':performed_date' => $performed_date,
        ':solution'       => $solution,
        ':cost'           => $cost,
        ':status'         => $status,
        ':remark'         => $remark,
        ':next_action'    => $status === "ซ่อมไม่ได้" ? $next_action : null
    ]);
    $repair_result_id = $dbh->lastInsertId();

    if (!empty($spareParts)) {
        $stmt_spare = $dbh->prepare("
            INSERT INTO spare_parts_used (repair_result_id, spare_part_id)
            VALUES (:repair_result_id, :spare_part_id)
        ");
        foreach ($spareParts as $spare_id) {
            if ($spare_id !== "") {
                $stmt_spare->execute([
                    ':repair_result_id' => $repair_result_id,
                    ':spare_part_id'    => $spare_id
                ]);
            }
        }
    }

    $stmtUpdate = $dbh->prepare("
        UPDATE repair 
        SET status = 'เสร็จสิ้น' 
        WHERE repair_id = :repair_id
    ");
    $stmtUpdate->execute([':repair_id' => $repair_id]);

    if ($status === 'ซ่อมเสร็จ') {
        $stmtEquip = $dbh->prepare("
            UPDATE equipments 
            SET status = 'ใช้งาน'
            WHERE equipment_id = (
                SELECT equipment_id FROM repair WHERE repair_id = :repair_id
            )
        ");
        $stmtEquip->execute([':repair_id' => $repair_id]);
    } 

    $dbh->commit();

    echo json_encode([
        "status" => "success",
        "message" => "บันทึกผลการซ่อมเรียบร้อย",
        "repair_result_id" => $repair_result_id
    ]);
} catch (Exception $e) {
    if ($dbh->inTransaction()) 
        $dbh->rollBack();
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
?>