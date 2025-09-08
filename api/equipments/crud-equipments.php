<?php
include "../config/jwt.php";
header('Content-Type: application/json');
$input = json_decode(file_get_contents('php://input'));

try {
    $action = $_GET['action'] ?? '';

    switch ($action) {

        /* ---------------------------- CREATE ---------------------------- */
        case 'create':
            $sql = "INSERT INTO equipments (
                name, asset_code, model, serial_number, brand,
                subcategory_id, import_type_id, production_year, price, contract,
                start_date, end_date, warranty_duration_days, warranty_condition,
                location_department_id, location_details,
                group_user_id, group_responsible_person,
                user_id, updated_by,
                manufacturer_company_id, supplier_company_id, maintainer_company_id,
                spec, status, record_status
            ) VALUES (
                :name, :asset_code, :model, :serial_number, :brand,
                :subcategory_id, :import_type_id, :production_year, :price, :contract,
                :start_date, :end_date, :warranty_duration_days, :warranty_condition,
                :location_department_id, :location_details,
                :group_user_id, :group_responsible_person,
                :user_id, :updated_by,
                :manufacturer_company_id, :supplier_company_id, :maintainer_company_id,
                :spec, :status, :record_status
            )";

            $stmt = $dbh->prepare($sql);
            $stmt->execute([
                ':name' => $input->name,
                ':asset_code' => $input->asset_code,
                ':model' => $input->model,
                ':serial_number' => $input->serial_number,
                ':brand' => $input->brand,
                ':subcategory_id' => $input->subcategory_id,
                ':import_type_id' => $input->import_type_id,
                ':production_year' => $input->production_year,
                ':price' => $input->price,
                ':contract' => $input->contract,
                ':start_date' => $input->start_date,
                ':end_date' => $input->end_date,
                ':warranty_duration_days' => $input->warranty_duration_days,
                ':warranty_condition' => $input->warranty_condition,
                ':location_department_id' => $input->location_department_id,
                ':location_details' => $input->location_details,
                ':group_user_id' => $input->group_user_id,
                ':group_responsible_person' => json_encode($input->group_responsible_person),
                ':user_id' => $input->user_id,
                ':updated_by' => $input->updated_by,
                ':manufacturer_company_id' => $input->manufacturer_company_id,
                ':supplier_company_id' => $input->supplier_company_id,
                ':maintainer_company_id' => $input->maintainer_company_id,
                ':spec' => $input->spec,
                ':status' => $input->status,
                ':record_status' => $input->record_status,
            ]);

            $equipment_id = $dbh->lastInsertId();

            /* --------- Insert files --------- */
            if (!empty($input->files) && is_array($input->files)) {
                $sqlFile = "INSERT INTO file_equip (
                    equipment_id, file_equip_name, equip_url, equip_type_name
                ) VALUES (
                    :equipment_id, :file_equip_name, :equip_url, :equip_type_name
                )";
                $stmtFile = $dbh->prepare($sqlFile);
                foreach ($input->files as $file) {
                    $stmtFile->execute([
                        ':equipment_id' => $equipment_id,
                        ':file_equip_name' => $file->file_equip_name,
                        ':equip_url' => $file->equip_url,
                        ':equip_type_name' => $file->equip_type_name,
                    ]);
                }
            }

            echo json_encode(['status' => 'success', 'equipment_id' => $equipment_id]);
            break;

        /* ---------------------------- READ ---------------------------- */
        case 'read':
            $sql = "SELECT e.*, 
                           COALESCE(JSON_ARRAYAGG(
                               JSON_OBJECT(
                                   'file_equip_id', fe.file_equip_id,
                                   'file_equip_name', fe.file_equip_name,
                                   'equip_url', fe.equip_url,
                                   'equip_type_name', fe.equip_type_name
                               )
                           ), JSON_ARRAY()) AS files
                    FROM equipments e
                    LEFT JOIN file_equip fe ON fe.equipment_id = e.equipment_id
                    GROUP BY e.equipment_id
                    ORDER BY e.equipment_id DESC";
            $stmt = $dbh->query($sql);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status' => 'success', 'data' => $results]);
            break;

        /* ---------------------------- UPDATE ---------------------------- */
        case 'update':
            if (!isset($input->equipment_id)) throw new Exception('Missing equipment_id');

            $sql = "UPDATE equipments SET
                name=:name, asset_code=:asset_code, model=:model, serial_number=:serial_number, brand=:brand,
                subcategory_id=:subcategory_id, import_type_id=:import_type_id,
                production_year=:production_year, price=:price, contract=:contract,
                start_date=:start_date, end_date=:end_date, warranty_duration_days=:warranty_duration_days,
                warranty_condition=:warranty_condition,
                location_department_id=:location_department_id, location_details=:location_details,
                group_user_id=:group_user_id, group_responsible_person=:group_responsible_person,
                user_id=:user_id, updated_by=:updated_by,
                manufacturer_company_id=:manufacturer_company_id, supplier_company_id=:supplier_company_id, maintainer_company_id=:maintainer_company_id,
                spec=:spec, status=:status, record_status=:record_status
                WHERE equipment_id=:equipment_id";

            $stmt = $dbh->prepare($sql);
            $stmt->execute([
                ':name' => $input->name,
                ':asset_code' => $input->asset_code,
                ':model' => $input->model,
                ':serial_number' => $input->serial_number,
                ':brand' => $input->brand,
                ':subcategory_id' => $input->subcategory_id,
                ':import_type_id' => $input->import_type_id,
                ':production_year' => $input->production_year,
                ':price' => $input->price,
                ':contract' => $input->contract,
                ':start_date' => $input->start_date,
                ':end_date' => $input->end_date,
                ':warranty_duration_days' => $input->warranty_duration_days,
                ':warranty_condition' => $input->warranty_condition,
                ':location_department_id' => $input->location_department_id,
                ':location_details' => $input->location_details,
                ':group_user_id' => $input->group_user_id,
                ':group_responsible_person' => json_encode($input->group_responsible_person),
                ':user_id' => $input->user_id,
                ':updated_by' => $input->updated_by,
                ':manufacturer_company_id' => $input->manufacturer_company_id,
                ':supplier_company_id' => $input->supplier_company_id,
                ':maintainer_company_id' => $input->maintainer_company_id,
                ':spec' => $input->spec,
                ':status' => $input->status,
                ':record_status' => $input->record_status,
                ':equipment_id' => $input->equipment_id,
            ]);

            /* --------- Update files --------- */
            if (!empty($input->files) && is_array($input->files)) {
                $stmt = $dbh->prepare("DELETE FROM file_equip WHERE equipment_id=:equipment_id");
                $stmt->execute([':equipment_id' => $input->equipment_id]);

                $sqlFile = "INSERT INTO file_equip (equipment_id, file_equip_name, equip_url, equip_type_name)
                            VALUES (:equipment_id, :file_equip_name, :equip_url, :equip_type_name)";
                $stmtFile = $dbh->prepare($sqlFile);
                foreach ($input->files as $file) {
                    $stmtFile->execute([
                        ':equipment_id' => $input->equipment_id,
                        ':file_equip_name' => $file->file_equip_name,
                        ':equip_url' => $file->equip_url,
                        ':equip_type_name' => $file->equip_type_name,
                    ]);
                }
            }

            echo json_encode(['status' => 'success']);
            break;

        /* ---------------------------- DELETE ---------------------------- */
        case 'delete':
            if (!isset($input->equipment_id)) throw new Exception('Missing equipment_id');
            $stmt = $dbh->prepare("DELETE FROM file_equip WHERE equipment_id=:equipment_id");
            $stmt->execute([':equipment_id' => $input->equipment_id]);
            $stmt = $dbh->prepare("DELETE FROM equipments WHERE equipment_id=:equipment_id");
            $stmt->execute([':equipment_id' => $input->equipment_id]);
            echo json_encode(['status' => 'success']);
            break;

        default:
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
