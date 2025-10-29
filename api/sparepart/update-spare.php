<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
include "../config/jwt.php";
include "../config/LogModel.php";

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

// ฟังก์ชันคำนวณ warranty_duration_days
function calculateWarranty($startDate, $endDate) {
    if (!$startDate || !$endDate) return null;
    $start = new DateTime($startDate);
    $end = new DateTime($endDate);
    if ($end < $start) throw new Exception("วันที่สิ้นสุดต้องไม่น้อยกว่าวันที่เริ่มต้น");
    return $start->diff($end)->days;
}

$spare_part_id = $_POST['spare_part_id'] ?? null;
if (empty($spare_part_id)) {
    echo json_encode(["status" => "error", "message" => "spare_part_id required"]);
    exit;
}

try {
    $dbh->beginTransaction();
    $log = new LogModel($dbh);

    // คนแก้ไขจริงจาก JWT
    $user_id = $decoded->data->ID ?? null;
    if (!$user_id) throw new Exception("User ID not found");

    // --- ดึงข้อมูลเก่าสำหรับ log ---
    $stmtOld = $dbh->prepare("SELECT * FROM spare_parts WHERE spare_part_id = :id");
    $stmtOld->execute([':id' => $spare_part_id]);
    $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);
    if (!$oldData) throw new Exception("Spare part not found");

    // Fields ที่อนุญาตให้อัปเดต
    $fields = [
        'name', 'import_type_id', 'spare_subcategory_id', 'location_department_id',
        'location_details', 'production_year', 'price', 'contract', 'start_date', 'end_date',
        'warranty_condition', 'maintainer_company_id', 'supplier_company_id', 'manufacturer_company_id',
        'status', 'record_status', 'spec', 'model', 'brand', 'serial_number', 'details','purchase_date'
    ];

    $setParts = [];
    $params = [':spare_part_id' => $spare_part_id, ':updated_by' => $user_id];

    $old_log = ['spare_part_id' => $spare_part_id];
    $new_log = ['spare_part_id' => $spare_part_id];

    // --- Loop update fields ---
    foreach ($fields as $f) {
        if (isset($_POST[$f])) {
            $newVal = $_POST[$f];

            // draft -> complete
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

    // --- คำนวณ warranty_duration_days ---
    if (isset($_POST['start_date']) || isset($_POST['end_date'])) {
        $startDate = $_POST['start_date'] ?? $oldData['start_date'] ?? null;
        $endDate = $_POST['end_date'] ?? $oldData['end_date'] ?? null;
        $newWarranty = calculateWarranty($startDate, $endDate);
        $oldWarranty = $oldData['warranty_duration_days'] ?? null;

        if ($oldWarranty != $newWarranty) {
            $setParts[] = "warranty_duration_days=:warranty_duration_days";
            $params[':warranty_duration_days'] = $newWarranty;
            $old_log['warranty_duration_days'] = $oldWarranty;
            $new_log['warranty_duration_days'] = $newWarranty;
        }
    }

    // --- Update updated_by / updated_at ---
    $setParts[] = "updated_by=:updated_by";
    $setParts[] = "updated_at=NOW()";

    // --- ตรวจ asset_code unique ---
    if (isset($_POST['asset_code']) && $_POST['asset_code'] !== '') {
        $newAsset = $_POST['asset_code'];
        $currentAsset = $oldData['asset_code'] ?? null;
        if ($newAsset !== $currentAsset) {
            $stmtCheck = $dbh->prepare("
                SELECT COUNT(*) as cnt 
                FROM spare_parts 
                WHERE asset_code=:asset_code AND spare_part_id != :spare_part_id
            ");
            $stmtCheck->execute([':asset_code' => $newAsset, ':spare_part_id' => $spare_part_id]);
            if ($stmtCheck->fetchColumn() > 0) {
                throw new Exception("รหัสทรัพย์สินมีอยู่แล้ว: $newAsset");
            }
        }
    }

    // --- Execute update ---
    if (!empty($setParts)) {
        $sql = "UPDATE spare_parts SET " . implode(',', $setParts) . " WHERE spare_part_id=:spare_part_id";
        $stmt = $dbh->prepare($sql);
        $stmt->execute($params);
    }

    // --- Log เฉพาะ field ที่เปลี่ยน ---
    if (count($old_log) > 1) {
        $log->insertLog($user_id, 'spare_parts', 'UPDATE', $old_log, $new_log);
    }

    $dbh->commit();
    echo json_encode(["status" => "success", "message" => "Spare part updated successfully."]);

} catch (Exception $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
