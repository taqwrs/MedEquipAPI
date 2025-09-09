<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
include "../config/jwt.php";
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$input = json_decode(file_get_contents('php://input'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || json_last_error() !== JSON_ERROR_NONE || !isset($input->spare_part_id)) {
    echo json_encode(["status" => "error", "message" => "Invalid POST request or missing spare_part_id"]);
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
    $stmt->bindValue(':spare_part_id', $input->spare_part_id);
    $stmt->bindValue(':name', $input->name);
    $stmt->bindValue(':asset_code', $input->asset_code);
    $stmt->bindValue(':import_type_id', $input->import_type_id);
    $stmt->bindValue(':spare_subcate_id', $input->spare_subcate_id);
    $stmt->bindValue(':location_department_id', $input->location_department_id);
    $stmt->bindValue(':location_details', $input->location_details);
    $stmt->bindValue(':production_year', $input->production_year);
    $stmt->bindValue(':price', $input->price);
    $stmt->bindValue(':contract', $input->contract);
    $stmt->bindValue(':start_date', $input->start_date);
    $stmt->bindValue(':end_date', $input->end_date);
    $stmt->bindValue(':warranty_condition', $input->warranty_condition);
    $stmt->bindValue(':maintainer_company_id', $input->maintainer_company_id);
    $stmt->bindValue(':supplier_company_id', $input->supplier_company_id);
    $stmt->bindValue(':manufacturer_company_id', $input->manufacturer_company_id);
    $stmt->bindValue(':group_user_id', $input->group_user_id);
    $stmt->bindValue(':group_responsible_id', $input->group_responsible_id);
    $stmt->bindValue(':status', $input->status);
    $stmt->bindValue(':updated_by', $input->updated_by);
    $stmt->execute();

    // Clear and re-insert files
    $dbh->exec("DELETE FROM file_spare WHERE spare_part_id = {$input->spare_part_id}");

    if (!empty($input->files)) {
        $fileSql = "INSERT INTO file_spare (spare_part_id, spare_url, spare_type_name) VALUES (:spare_part_id, :spare_url, :spare_type_name)";
        $fileStmt = $dbh->prepare($fileSql);
        foreach ($input->files as $file) {
            $fileStmt->bindValue(':spare_part_id', $input->spare_part_id);
            $fileStmt->bindValue(':spare_url', $file->spare_url);
            $fileStmt->bindValue(':spare_type_name', $file->spare_type_name);
            $fileStmt->execute();
        }
    }

    $dbh->commit();
    echo json_encode(["status" => "success", "message" => "Spare part updated successfully."]);
} catch (PDOException $e) {
    $dbh->rollBack();
    echo json_encode(["status" => "error", "message" => "Database Error: " . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "General Error: " . $e->getMessage()]);
}
