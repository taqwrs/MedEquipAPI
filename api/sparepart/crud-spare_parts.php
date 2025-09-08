<?php
include "../config/jwt.php";
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'));

try {
    $action = $_GET['action'] ?? '';

    switch($action) {

        // ----------------- CREATE -----------------
        case 'create':
            $sql = "INSERT INTO spare_parts
                    (name, asset_code, model, serial_number, brand, spare_subcate_id, equipment_id,
                     manufacturer_company_id, supplier_company_id, maintainer_company_id,
                     import_type_id, location_department_id)
                    VALUES
                    (:name, :asset_code, :model, :serial_number, :brand, :spare_subcate_id, :equipment_id,
                     :manufacturer_company_id, :supplier_company_id, :maintainer_company_id,
                     :import_type_id, :location_department_id)";
            $stmt = $dbh->prepare($sql);
            $stmt->execute([
                ':name' => $input->name,
                ':asset_code' => $input->asset_code,
                ':model' => $input->model,
                ':serial_number' => $input->serial_number,
                ':brand' => $input->brand,
                ':spare_subcate_id' => $input->spare_subcate_id,
                ':equipment_id' => $input->equipment_id,
                ':manufacturer_company_id' => $input->manufacturer_company_id,
                ':supplier_company_id' => $input->supplier_company_id,
                ':maintainer_company_id' => $input->maintainer_company_id,
                ':import_type_id' => $input->import_type_id,
                ':location_department_id' => $input->location_department_id
            ]);
            $spare_part_id = $dbh->lastInsertId();

            // ถ้ามีไฟล์แนบ
            if (!empty($input->files) && is_array($input->files)) {
                $sqlFile = "INSERT INTO file_spare (spare_part_id, file_spare_name, spare_url, spare_type_name) 
                            VALUES (:spare_part_id, :file_spare_name, :spare_url, :spare_type_name)";
                $stmtFile = $dbh->prepare($sqlFile);
                foreach ($input->files as $file) {
                    $stmtFile->execute([
                        ':spare_part_id' => $spare_part_id,
                        ':file_spare_name' => $file->file_spare_name,
                        ':spare_url' => $file->spare_url,
                        ':spare_type_name' => $file->spare_type_name
                    ]);
                }
            }

            echo json_encode(['status'=>'success','spare_part_id'=>$spare_part_id]);
            break;

        // ----------------- READ -----------------
        case 'read':
            $sql = "SELECT sp.*, e.name AS equipment_name, sc.name AS subcategory_name,
                           COALESCE(JSON_ARRAYAGG(
                               JSON_OBJECT(
                                   'file_spare_id', fs.file_spare_id,
                                   'file_spare_name', fs.file_spare_name,
                                   'spare_url', fs.spare_url,
                                   'spare_type_name', fs.spare_type_name
                               )
                           ), JSON_ARRAY()) AS files
                    FROM spare_parts sp
                    LEFT JOIN equipments e ON e.equipment_id = sp.equipment_id
                    LEFT JOIN equipment_subcategories sc ON sc.subcategory_id = sp.spare_subcate_id
                    LEFT JOIN file_spare fs ON fs.spare_part_id = sp.spare_part_id
                    GROUP BY sp.spare_part_id
                    ORDER BY sp.spare_part_id DESC";
            $stmt = $dbh->query($sql);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['status'=>'success','data'=>$results]);
            break;

        // ----------------- UPDATE -----------------
        case 'update':
            if (!isset($input->spare_part_id)) throw new Exception('Missing spare_part_id');
            $sql = "UPDATE spare_parts SET 
                        name=:name, asset_code=:asset_code, model=:model, serial_number=:serial_number, brand=:brand,
                        spare_subcate_id=:spare_subcate_id, equipment_id=:equipment_id,
                        manufacturer_company_id=:manufacturer_company_id,
                        supplier_company_id=:supplier_company_id,
                        maintainer_company_id=:maintainer_company_id,
                        import_type_id=:import_type_id,
                        location_department_id=:location_department_id
                    WHERE spare_part_id=:spare_part_id";
            $stmt = $dbh->prepare($sql);
            $stmt->execute([
                ':name' => $input->name,
                ':asset_code' => $input->asset_code,
                ':model' => $input->model,
                ':serial_number' => $input->serial_number,
                ':brand' => $input->brand,
                ':spare_subcate_id' => $input->spare_subcate_id,
                ':equipment_id' => $input->equipment_id,
                ':manufacturer_company_id' => $input->manufacturer_company_id,
                ':supplier_company_id' => $input->supplier_company_id,
                ':maintainer_company_id' => $input->maintainer_company_id,
                ':import_type_id' => $input->import_type_id,
                ':location_department_id' => $input->location_department_id,
                ':spare_part_id' => $input->spare_part_id
            ]);

            // ลบไฟล์เดิมและ insert ใหม่ (ง่ายต่อ maintenance)
            if (!empty($input->files) && is_array($input->files)) {
                $stmt = $dbh->prepare("DELETE FROM file_spare WHERE spare_part_id=:spare_part_id");
                $stmt->execute([':spare_part_id'=>$input->spare_part_id]);

                $sqlFile = "INSERT INTO file_spare (spare_part_id, file_spare_name, spare_url, spare_type_name) 
                            VALUES (:spare_part_id, :file_spare_name, :spare_url, :spare_type_name)";
                $stmtFile = $dbh->prepare($sqlFile);
                foreach ($input->files as $file) {
                    $stmtFile->execute([
                        ':spare_part_id' => $input->spare_part_id,
                        ':file_spare_name' => $file->file_spare_name,
                        ':spare_url' => $file->spare_url,
                        ':spare_type_name' => $file->spare_type_name
                    ]);
                }
            }

            echo json_encode(['status'=>'success']);
            break;

        // ----------------- DELETE -----------------
        case 'delete':
            if (!isset($input->spare_part_id)) throw new Exception('Missing spare_part_id');
            // ลบไฟล์ก่อน
            $stmt = $dbh->prepare("DELETE FROM file_spare WHERE spare_part_id=:spare_part_id");
            $stmt->execute([':spare_part_id'=>$input->spare_part_id]);
            // ลบ spare part
            $stmt = $dbh->prepare("DELETE FROM spare_parts WHERE spare_part_id=:spare_part_id");
            $stmt->execute([':spare_part_id'=>$input->spare_part_id]);
            echo json_encode(['status'=>'success']);
            break;

        default:
            echo json_encode(['status'=>'error','message'=>'Invalid action']);
    }

} catch(Exception $e){
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
