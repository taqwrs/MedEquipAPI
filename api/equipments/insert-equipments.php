<?php
include "../config/jwt.php";
include "../config/LogModel.php";

$isJsonRequest = (strpos(strtolower(getenv("CONTENT_TYPE")), 'application/json') !== false);
$input = $isJsonRequest ? json_decode(file_get_contents('php://input'), true) : $_POST;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}
try {
    $dbh->beginTransaction();
    $log = new LogModel($dbh);
    $user_id = $decoded->data->ID ?? null;
    if (!$user_id)
        throw new Exception("User ID not found");

    $requiredFields = ['name', 'asset_code', 'location_details',];
    $allFields = [
        'name',
        'brand',
        'asset_code',
        'model',
        'serial_number',
        'spec',
        'import_type_id',
        'subcategory_id',
        'location_department_id',
        'location_details',
        'manufacturer_company_id',
        'supplier_company_id',
        'maintainer_company_id',
        'production_year',
        'price',
        'contract',
        'start_date',
        'end_date',
        'warranty_duration_days',
        'warranty_condition',
        'user_id',
        'updated_by',
        'record_status',
        'details',
        'status',
        'active',
        'purchase_date',
        'first_register'
    ];

    $relations = [
        'child_equipments' => ['table' => 'equipments', 'fk' => 'main_equipment_id', 'idField' => 'equipment_id'],
        'spare_parts' => ['table' => 'spare_parts', 'fk' => 'equipment_id', 'idField' => 'spare_part_id']
    ];

    // --- ตรวจ required ---
    foreach ($requiredFields as $f) {
        if (empty($_POST[$f]))
            throw new Exception("Missing field: $f");
    }

    // --- ตรวจ asset_code unique ---
    if (!empty($_POST['asset_code'])) {
        $stmtCheckCode = $dbh->prepare("SELECT COUNT(*) as cnt FROM equipments WHERE asset_code = :asset_code");
        $stmtCheckCode->execute([':asset_code' => $_POST['asset_code']]);
        $row = $stmtCheckCode->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['cnt'] > 0) {
            throw new Exception("รหัสทรัพย์สินมีอยู่แล้ว: " . $_POST['asset_code']);
        }
    }

    // --- คำนวณ warranty ---
    if (!empty($_POST['start_date']) && !empty($_POST['end_date'])) {
        $start = new DateTime($_POST['start_date']);
        $end = new DateTime($_POST['end_date']);
        if ($end < $start) {
            throw new Exception("วันที่สิ้นสุดต้องไม่น้อยกว่าวันที่เริ่มต้น");
        }
        $_POST['warranty_duration_days'] = $start->diff($end)->days;
    } else
        $_POST['warranty_duration_days'] = null;

    // --- เตรียม insert ---
    $cols = [];
    $placeholders = [];
    $values = [];
    foreach ($allFields as $f) {
        if ($f === 'first_register') {
            $cols[] = $f;
            $placeholders[] = 'NOW()';
            continue;
        }
        $cols[] = $f;
        $placeholders[] = ":$f";
        if ($f === 'active' && !isset($_POST['active']))
            $values[":$f"] = 1;
        elseif ($f === 'status' && !isset($_POST['status']))
            $values[":$f"] = 'ใช้งาน';
        elseif ($f === 'record_status' && !isset($_POST['record_status']))
            $values[":$f"] = 'complete';
        elseif ($f === 'user_id' || $f === 'updated_by')
            $values[":$f"] = $user_id; // force from JWT
        else
            $values[":$f"] = $_POST[$f] ?? null;
    }
    $cols[] = 'updated_at';
    $placeholders[] = 'NOW()';

    // --- Execute insert ---
    $sql = "INSERT INTO equipments(" . implode(',', $cols) . ") VALUES(" . implode(',', $placeholders) . ")";
    $stmt = $dbh->prepare($sql);
    foreach ($values as $k => $v)
        $stmt->bindValue($k, $v);
    $stmt->execute();
    $equipId = $dbh->lastInsertId();

    // --- Log insert main equipment ---
    $log->insertLog($user_id, 'equipments', 'INSERT', null, $values + ['equipment_id' => $equipId]);

    // --- Relations ---
    foreach ($relations as $relKey => $relConfig) {
        if (!empty($_POST[$relKey])) {
            $arr = is_string($_POST[$relKey]) ? json_decode($_POST[$relKey], true) : $_POST[$relKey];
            if (is_array($arr) && isset($arr[0]) && !is_array($arr[0])) {
                $arr = array_map(fn($id) => [$relConfig['idField'] => $id], $arr);
            }

            foreach ($arr as $item) {
                if (!empty($item[$relConfig['idField']])) {
                    $id = $item[$relConfig['idField']];

                    // ดึงค่าเดิมของความสัมพันธ์เฉพาะ field ที่เกี่ยวข้อง
                    $stmtCheck = $dbh->prepare("SELECT {$relConfig['fk']} FROM {$relConfig['table']} WHERE {$relConfig['idField']}=:id");
                    $stmtCheck->execute([':id' => $id]);
                    $oldMain = $stmtCheck->fetchColumn();

                    // อัปเดตค่าใหม่
                    $stmt = $dbh->prepare("UPDATE {$relConfig['table']} 
                                       SET {$relConfig['fk']}=:main, 
                                       status = CASE WHEN status='คลัง' THEN 'ใช้งาน' ELSE status END,
                                       updated_by=:updated_by, updated_at=NOW() 
                                       WHERE {$relConfig['idField']}=:id");
                    $stmt->execute([':main' => $equipId, ':id' => $id, ':updated_by' => $user_id]);

                    // ตรวจว่ามีการย้ายจากอุปกรณ์อื่นหรือไม่
                    $actionType = ($oldMain && $oldMain != $equipId) ? 'TRANSFER' : 'UPDATE';

                    // Log เฉพาะ field ที่เปลี่ยน
                    $log->insertLog(
                        $user_id, $relConfig['table'], $actionType,
                        [$relConfig['idField'] => $id, $relConfig['fk'] => $oldMain],
                        [$relConfig['idField'] => $id, $relConfig['fk'] => $equipId]
                    );
                }
            }
        }
    }

    $dbh->commit();
    echo json_encode(["status" => "success", "message" => "Equipment inserted", "id" => $equipId]);
} catch (Exception $e) {
    if ($dbh->inTransaction())
        $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
