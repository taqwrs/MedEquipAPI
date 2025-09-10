<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
include "../config/jwt.php";

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

$input = json_decode(file_get_contents('php://input'), true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || json_last_error() !== JSON_ERROR_NONE || empty($input['spare_part_id'])) {
    echo json_encode(["status"=>"error","message"=>"Invalid POST request or missing spare_part_id"]);
    exit;
}

try {
    $dbh->beginTransaction();

    // Update main spare part data
    $sql = "UPDATE spare_parts SET
        name = :name, asset_code = :asset_code, import_type_id = :import_type_id,
        spare_subcate_id = :spare_subcate_id, location_department_id = :location_department_id,
        location_details = :location_details, production_year = :production_year, price = :price,
        contract = :contract, start_date = :start_date, end_date = :end_date,
        warranty_condition = :warranty_condition, maintainer_company_id = :maintainer_company_id,
        supplier_company_id = :supplier_company_id, manufacturer_company_id = :manufacturer_company_id,
        group_user_id = :group_user_id, group_responsible_id = :group_responsible_id,
        status = :status, updated_by = :updated_by
    WHERE spare_part_id = :spare_part_id";

    $stmt = $dbh->prepare($sql);
    foreach ($input as $k => $v) {
        if (strpos($sql, ":$k") !== false) $stmt->bindValue(":$k", $v);
    }
    $stmt->execute();

    // Clear and re-insert files safely using prepared statement
    $delStmt = $dbh->prepare("DELETE FROM file_spare WHERE spare_part_id = :spare_part_id");
    $delStmt->bindValue(':spare_part_id', $input['spare_part_id']);
    $delStmt->execute();

    if (!empty($input['files']) && is_array($input['files'])) {
        $fileSql = "INSERT INTO file_spare (spare_part_id, spare_url, spare_type_name, file_spare_name) VALUES (:spare_part_id, :spare_url, :spare_type_name, :file_spare_name)";
        $fileStmt = $dbh->prepare($fileSql);
        foreach ($input['files'] as $file) {
            $fileStmt->bindValue(':spare_part_id', $input['spare_part_id']);
            $fileStmt->bindValue(':spare_url', $file['spare_url']);
            $fileStmt->bindValue(':spare_type_name', $file['spare_type_name']);
            $fileStmt->bindValue(':file_spare_name', $file['file_spare_name'] ?? '');
            $fileStmt->execute();
        }
    }

    $dbh->commit();
    echo json_encode(["status"=>"success","message"=>"Spare part updated successfully."]);

} catch(Exception $e) {
    if($dbh->inTransaction()) $dbh->rollBack();
    echo json_encode(["status"=>"error","message"=>$e->getMessage()]);
}
