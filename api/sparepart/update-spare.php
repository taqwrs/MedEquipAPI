<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
include "../config/jwt.php";
include "../config/LogModel.php";

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS')
    exit(0);

$spare_part_id = $_POST['spare_part_id'] ?? null;

if (empty($spare_part_id)) {
    echo json_encode(["status" => "error", "message" => "spare_part_id required"]);
    exit;
}

try {
    $dbh->beginTransaction();
    $log = new LogModel($dbh);

    // คนแก้ไขจริง จาก JWT
    $user_id = $decoded->data->ID ?? null;
    if (!$user_id) throw new Exception("User ID not found");

    // --- ดึงข้อมูลเก่าสำหรับ log ---
    $stmtOld = $dbh->prepare("SELECT * FROM spare_parts WHERE spare_part_id = :id");
    $stmtOld->execute([':id' => $spare_part_id]);
    $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);

    // Fields สำหรับ update
    $fields = [
        'name', 'asset_code', 'import_type_id', 'spare_subcategory_id', 'location_department_id',
        'location_details', 'production_year', 'price', 'contract', 'start_date', 'end_date',
        'warranty_condition', 'maintainer_company_id', 'supplier_company_id', 'manufacturer_company_id',
        'status', 'record_status', 'spec', 'model', 'brand', 'serial_number', 'details'
    ];

    $setParts = [];
    $params = [':spare_part_id' => $spare_part_id, ':updated_by' => $user_id];

    // เก็บเฉพาะ field ที่เปลี่ยน
    $old_log = ['spare_part_id' => $spare_part_id];
    $new_log = ['spare_part_id' => $spare_part_id];

    foreach ($fields as $f) {
        if (isset($_POST[$f])) {
            $newVal = $_POST[$f];

            // เปลี่ยน record_status จาก draft -> complete
            if ($f === 'record_status' && $oldData['record_status'] === 'draft') {
                $newVal = 'complete';
            }

            $setParts[] = "$f=:$f";
            $params[":$f"] = $newVal;

            $oldVal = $oldData[$f] ?? null;
            if ($oldVal != $newVal) {
                $old_log[$f] = $oldVal;
                $new_log[$f] = $newVal;
            }
        }
    }

    $setParts[] = "updated_by=:updated_by";
    $setParts[] = "updated_at=NOW()";

    if (!empty($setParts)) {
        $sql = "UPDATE spare_parts SET " . implode(',', $setParts) . " WHERE spare_part_id=:spare_part_id";
        $stmt = $dbh->prepare($sql);
        $stmt->execute($params);
    }

    // ตรวจ asset_code unique
    if (isset($_POST['asset_code']) && $_POST['asset_code'] !== '') {
        $newAsset = $_POST['asset_code'];
        $currentAsset = $oldData['asset_code'] ?? null;
        if ($newAsset !== $currentAsset) {
            $stmtCheckCode = $dbh->prepare("
                SELECT COUNT(*) as cnt 
                FROM spare_parts 
                WHERE asset_code = :asset_code 
                  AND spare_part_id != :spare_part_id
            ");
            $stmtCheckCode->execute([':asset_code' => $newAsset, ':spare_part_id' => $spare_part_id]);
            $row = $stmtCheckCode->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['cnt'] > 0) {
                throw new Exception("รหัสทรัพย์สินมีอยู่แล้ว " . $newAsset . " กรุณาเปลี่ยน");
            }
        }
    }

    // --- Log เฉพาะ field ที่เปลี่ยน, ใช้ user แก้ไขจริง ---
    if (count($old_log) > 1) { // มี field ที่เปลี่ยนมากกว่าแค่ PK
        $log->insertLog($user_id, 'spare_parts', 'UPDATE', $old_log, $new_log);
    }

    $dbh->commit();
    echo json_encode(["status" => "success", "message" => "Spare part updated successfully."]);

} catch (Exception $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
