<?php
include "../config/jwt.php";
$input = json_decode(file_get_contents('php://input'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array("status" => "error", "message" => "post method!!!"));
    die();
}

try {
    $sql = "
    SELECT
        e.equipment_id,
        e.name,
        e.brand,
        e.asset_code,
        e.model,
        e.serial_number,
        e.import_type_id,
        it.name AS import_type_name,
        e.subcategory_id,
        sc.name AS subcategory_name,
        e.location_department_id,
        d.department_name,
        e.location_details,
        e.main_equipment_id,
        CASE
            WHEN e.main_equipment_id IS NULL THEN NULL
            ELSE em.name
        END AS main_equipment_name,
        e.manufacturer_company_id,
        mc.name AS manufacturer_company_name,
        e.supplier_company_id,
        scp.name AS supplier_company_name,
        e.maintainer_company_id,
        cc.name AS maintainer_company_name,
        e.spec,
        e.production_year,
        e.price,
        e.contract,
        e.start_date,
        e.end_date,
        e.warranty_duration_days,
        e.warranty_condition,
        e.group_user_id,
        gu1.group_name AS group_user_name,
        e.group_responsible_id,
        gu2.group_name AS group_responsible_name,
        e.user_id,
        u1.full_name AS user_full_name,
        e.updated_by,
        u2.full_name AS updated_by_name,
        e.record_status,
        e.status,
        f.file_equip_id,
        f.file_equip_name,
        f.equip_url,
        f.equip_type_name
    FROM
        equipments e
    LEFT JOIN equipments em ON em.equipment_id = e.main_equipment_id
    LEFT JOIN import_types it ON it.import_type_id = e.import_type_id
    LEFT JOIN equipment_subcategories sc ON sc.subcategory_id = e.subcategory_id
    LEFT JOIN departments d ON d.department_id = e.location_department_id
    LEFT JOIN companies mc ON mc.company_id = e.manufacturer_company_id
    LEFT JOIN companies scp ON scp.company_id = e.supplier_company_id
    LEFT JOIN companies cc ON cc.company_id = e.maintainer_company_id
    LEFT JOIN group_user gu1 ON gu1.group_user_id = e.group_user_id
        AND gu1.type = 'ผู้ดูแลหลัก'
    LEFT JOIN group_user gu2 ON gu2.group_user_id = e.group_responsible_id
        AND gu2.type = 'ผู้ใช้งาน'
    LEFT JOIN users u1 ON u1.user_id = e.user_id
    LEFT JOIN users u2 ON u2.user_id = e.updated_by
    LEFT JOIN file_equip f ON f.equipment_id = e.equipment_id
    ORDER BY
        e.equipment_id;
    ";

    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $equipments = [];

    foreach ($results as $row) {
        $id = $row['equipment_id'];

        if (!isset($equipments[$id])) {
            $equipments[$id] = [
                'equipment_id' => $id,
                'name' => $row['name'],
                'brand' => $row['brand'],
                'asset_code' => $row['asset_code'],
                'model' => $row['model'],
                'serial_number' => $row['serial_number'],
                'import_type_id' => $row['import_type_id'],
                'import_type_name' => $row['import_type_name'],
                'subcategory_id' => $row['subcategory_id'],
                'subcategory_name' => $row['subcategory_name'],
                'location_department_id' => $row['location_department_id'],
                'department_name' => $row['department_name'],
                'location_details' => $row['location_details'],
                'main_equipment_id' => $row['main_equipment_id'],
                'main_equipment_name' => $row['main_equipment_name'],
                'manufacturer_company_id' => $row['manufacturer_company_id'],
                'manufacturer_company_name' => $row['manufacturer_company_name'],
                'supplier_company_id' => $row['supplier_company_id'],
                'supplier_company_name' => $row['supplier_company_name'],
                'maintainer_company_id' => $row['maintainer_company_id'],
                'maintainer_company_name' => $row['maintainer_company_name'],
                'spec' => $row['spec'],
                'production_year' => $row['production_year'],
                'price' => $row['price'],
                'contract' => $row['contract'],
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
                'warranty_duration_days' => $row['warranty_duration_days'],
                'warranty_condition' => $row['warranty_condition'],
                'group_user_id' => $row['group_user_id'],
                'group_user_name' => $row['group_user_name'],
                'group_responsible_id' => $row['group_responsible_id'],
                'group_responsible_name' => $row['group_responsible_name'],
                'user_id' => $row['user_id'],
                'user_full_name' => $row['user_full_name'],
                'updated_by' => $row['updated_by'],
                'updated_by_name' => $row['updated_by_name'],
                'record_status' => $row['record_status'],
                'status' => $row['status'],
                'filesInfo' => []
            ];
        }

        if ($row['file_equip_id']) {
            $equipments[$id]['filesInfo'][] = [
                'file_equip_id' => $row['file_equip_id'],
                'file_equip_name' => $row['file_equip_name'],
                'equip_url' => $row['equip_url'],
                'equip_type_name' => $row['equip_type_name']
            ];
        }
    }

    echo json_encode([
        "status" => "success",
        "data" => array_values($equipments)
    ]);

} catch (Exception $e) {
    echo json_encode(array("status" => "error", "message" => $e->getMessage()));
}
