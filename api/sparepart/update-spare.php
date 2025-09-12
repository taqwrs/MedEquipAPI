<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
include "../config/jwt.php";

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

$spare_part_id = $_POST['spare_part_id'] ?? null;
$updated_by = $_POST['updated_by'] ?? null;

if (empty($spare_part_id)) {
    echo json_encode(["status"=>"error","message"=>"spare_part_id required"]);
    exit;
}
if (empty($updated_by)) {
    echo json_encode(["status"=>"error","message"=>"updated_by required"]);
    exit;
}

try {
    $dbh->beginTransaction();

    // Fields for partial update
    $fields = [
        'name','asset_code','import_type_id','spare_subcate_id','location_department_id',
        'location_details','production_year','price','contract','start_date','end_date',
        'warranty_condition','maintainer_company_id','supplier_company_id','manufacturer_company_id',
        'group_user_id','group_responsible_id','status'
    ];

    $setParts = [];
    $params = [':spare_part_id' => $spare_part_id, ':updated_by' => $updated_by];

    foreach ($fields as $f) {
        if (isset($_POST[$f])) {
            $setParts[] = "$f=:$f";
            $params[":$f"] = $_POST[$f];
        }
    }
    $setParts[] = "updated_by=:updated_by";
    $setParts[] = "updated_at=NOW()";

    if (!empty($setParts)) {
        $sql = "UPDATE spare_parts SET " . implode(',', $setParts) . " WHERE spare_part_id=:spare_part_id";
        $stmt = $dbh->prepare($sql);
        $stmt->execute($params);
    }

    // Handle files from FormData ($_FILES)
    if (!empty($_FILES['file_spare'])) {
        $uploadDir = 'uploads/';
        foreach ($_FILES['file_spare']['name'] as $index => $name) {
            $tmp_name = $_FILES['file_spare']['tmp_name'][$index];
            $typeName = $_POST['spare_type_name'][$index] ?? null;
            $fileName = $_FILES['file_spare']['name'][$index];

            // move uploaded file
            $targetPath = $uploadDir . uniqid('file_') . '_' . basename($fileName);
            move_uploaded_file($tmp_name, $targetPath);

            $sql = "INSERT INTO file_spare (spare_part_id, spare_url, spare_type_name, file_spare_name, updated_at)
                    VALUES (:spare_part_id, :spare_url, :spare_type_name, :file_spare_name, NOW())";
            $stmt = $dbh->prepare($sql);
            $stmt->execute([
                ':spare_part_id' => $spare_part_id,
                ':spare_url' => $targetPath,
                ':spare_type_name' => $typeName,
                ':file_spare_name' => $fileName
            ]);
        }
    }

    $dbh->commit();
    echo json_encode(["status"=>"success","message"=>"Spare part updated successfully."]);

} catch(Exception $e) {
    if ($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
