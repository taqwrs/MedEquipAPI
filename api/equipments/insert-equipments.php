<?php
include "../config/jwt.php";
include "../config/LogModel.php";

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

    $requiredFields = ['name', 'asset_code'];
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
            throw new Exception("Duplicate asset_code: " . $_POST['asset_code']);
        }
    }

    // --- คำนวณ warranty ---
    if (!empty($_POST['start_date']) && !empty($_POST['end_date'])) {
        $start = new DateTime($_POST['start_date']);
        $end = new DateTime($_POST['end_date']);
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
            $values[":$f"] = $_POST[$f] ?? $user_id;
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
    $log->insertLog($user_id, 'equipments', 'INSERT', null, $values + ['equipment_id' => $equipId], 'register_logs');

    // --- Relations ---
    foreach ($relations as $relKey => $relConfig) {
        if (!empty($_POST[$relKey])) {
            $arr = $_POST[$relKey];
            if (is_string($arr))
                $arr = json_decode($arr, true);
            if (is_array($arr) && isset($arr[0]) && !is_array($arr[0])) {
                $arr = array_map(fn($id) => [$relConfig['idField'] => $id], $arr);
            }
            foreach ($arr as $item) {
                if (!empty($item[$relConfig['idField']])) {
                    // --- ก่อน update ดึงค่าเก่าเพื่อ log ---
                    $stmtCheck = $dbh->prepare("SELECT * FROM {$relConfig['table']} WHERE {$relConfig['idField']}=:id");
                    $stmtCheck->execute([':id' => $item[$relConfig['idField']]]);
                    $oldData = $stmtCheck->fetch(PDO::FETCH_ASSOC);

                    $stmt = $dbh->prepare("UPDATE {$relConfig['table']} 
                                         SET {$relConfig['fk']}=:main, updated_by=:updated_by, updated_at=NOW() 
                                         WHERE {$relConfig['idField']}=:id");
                    $stmt->execute([':main' => $equipId, ':id' => $item[$relConfig['idField']], ':updated_by' => $user_id]);
                    $log->insertLog($user_id, $relConfig['table'], 'UPDATE', $oldData, ['equipment_id' => $equipId, 'updated_id' => $item[$relConfig['idField']]], 'register_logs');
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
