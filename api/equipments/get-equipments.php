<?php
include "../config/jwt.php";
$input = json_decode(file_get_contents('php://input'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "post method!!!"]);
    exit;
}
$search = trim($input->search ?? '');
$page   = (int)($input->page ?? 1);
$limit  = (int)($input->limit ?? 5);
$offset = ($page - 1) * $limit;

try {
    $params = [];
    $searchSql = '';
    if ($search) {
        $searchSql = "WHERE e.name LIKE :search OR e.asset_code LIKE :search OR e.serial_number LIKE :search OR e.brand LIKE :search";
        $params[':search'] = "%$search%";
    }

    // SQL เพื่อดึงข้อมูลอุปกรณ์หลักและอุปกรณ์ย่อย (ที่ไม่ใช่อะไหล่) ที่ตรงกับเงื่อนไขการค้นหา
    $sql = "
        SELECT 
            e.*,
            em.name AS main_equipment_name,
            em.asset_code AS main_equipment_asset_code,
            em.equipment_id AS main_equipment_id,
            it.name AS import_type_name, 
            sc.name AS subcategory_name, 
            d.department_name, 
            mc.name AS manufacturer_company_name, 
            scp.name AS supplier_company_name, 
            cc.name AS maintainer_company_name, 
            gu1.group_name AS group_user_name, 
            gu2.group_name AS group_responsible_name, 
            u1.full_name AS user_full_name, 
            u2.full_name AS updated_by_name,
            -- ดึงข้อมูลอุปกรณ์ย่อย
            COALESCE(
                CONCAT('[', GROUP_CONCAT(DISTINCT JSON_OBJECT(
                    'equipment_id', ce.equipment_id,
                    'name', ce.name,
                    'asset_code', ce.asset_code
                )), ']'), '[]'
            ) AS child_equipments,
            -- ดึงข้อมูลอะไหล่
            COALESCE(
                CONCAT('[', GROUP_CONCAT(DISTINCT JSON_OBJECT(
                    'spare_part_id', sp.spare_part_id,
                    'name', sp.name,
                    'asset_code', sp.asset_code
                )), ']'), '[]'
            ) AS spareParts,
            -- ดึงข้อมูลไฟล์แนบ
            COALESCE(
                CONCAT('[', GROUP_CONCAT(DISTINCT JSON_OBJECT(
                    'file_equip_id', f.file_equip_id,
                    'file_equip_name', f.file_equip_name,
                    'equip_url', f.equip_url,
                    'equip_type_name', f.equip_type_name
                )), ']'), '[]'
            ) AS filesInfo
        FROM equipments e
        LEFT JOIN equipments em ON em.equipment_id = e.main_equipment_id
        LEFT JOIN equipments ce ON ce.main_equipment_id = e.equipment_id
        LEFT JOIN spare_parts sp ON sp.equipment_id = e.equipment_id
        LEFT JOIN file_equip f ON f.equipment_id = e.equipment_id
        LEFT JOIN import_types it ON it.import_type_id = e.import_type_id
        LEFT JOIN equipment_subcategories sc ON sc.subcategory_id = e.subcategory_id
        LEFT JOIN departments d ON d.department_id = e.location_department_id
        LEFT JOIN companies mc ON mc.company_id = e.manufacturer_company_id
        LEFT JOIN companies scp ON scp.company_id = e.supplier_company_id
        LEFT JOIN companies cc ON cc.company_id = e.maintainer_company_id
        LEFT JOIN group_user gu1 ON gu1.group_user_id = e.group_user_id
        LEFT JOIN group_user gu2 ON gu2.group_user_id = e.group_responsible_id
        LEFT JOIN users u1 ON u1.user_id = e.user_id
        LEFT JOIN users u2 ON u2.user_id = e.updated_by
        " . ($search ? $searchSql : "WHERE e.equipment_id IS NOT NULL") . "
        GROUP BY e.equipment_id
        ORDER BY e.equipment_id
        LIMIT :limit OFFSET :offset
    ";

    // นับจำนวนรายการทั้งหมด
    $countSql = "SELECT COUNT(DISTINCT e.equipment_id) FROM equipments e " . $searchSql;
    $countStmt = $dbh->prepare($countSql);
    foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
    $countStmt->execute();
    $totalItems = (int)$countStmt->fetchColumn();
    $totalPages = ceil($totalItems / $limit);
    
    $stmt = $dbh->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Decode JSON และจัดระเบียบข้อมูล
    foreach ($results as &$row) {
        $row['filesInfo'] = json_decode($row['filesInfo'], true);
        $row['spareParts'] = json_decode($row['spareParts'], true);
        $row['child_equipments'] = json_decode($row['child_equipments'], true);
        // สร้าง object อุปกรณ์หลักสำหรับอุปกรณ์ย่อย
        if ($row['main_equipment_id'] !== null) {
            $row['main_equipment'] = [
                'equipment_id' => $row['main_equipment_id'],
                'name' => $row['main_equipment_name'],
                'asset_code' => $row['main_equipment_asset_code'],
            ];
        }
    }

    echo json_encode([
        "status" => "success",
        "data" => $results,
        "pagination" => [
            "totalItems" => $totalItems,
            "totalPages" => $totalPages,
            "currentPage" => $page,
            "limit" => $limit
        ]
    ]);
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
