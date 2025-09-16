<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

include "../config/jwt.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$search = trim($input['search'] ?? '');
$page = (int) ($input['page'] ?? 1);
$limit = (int) ($input['limit'] ?? 5);
$offset = ($page - 1) * $limit;
$useLimit = $limit > 0;

try {
    $params = [];
    $searchSql = '';
    if ($search) {
        $searchSql = "WHERE e.name LIKE :search OR e.asset_code LIKE :search OR e.end_date LIKE :search OR e.status LIKE :search";
        $params[':search'] = "%$search%";
    }

    $sql = "
    SELECT
        e.*,
        em.name AS main_equipment_name,
        em.asset_code AS main_equipment_asset_code,
        em.equipment_id AS main_equipment_id,
        it.name AS import_type_name,
        sc.name AS subcategory_name,
        d.department_name,
        mc.name AS manufacturer_name,
        scp.name AS supplier_name,
        cc.name AS maintainer_name,
        gu1.group_name AS group_responsible_name,
        gu2.group_name AS group_user_name,
        u1.full_name AS created_by_name,
        u2.full_name AS updated_by_name,
        COALESCE(
            CONCAT('[', GROUP_CONCAT(DISTINCT JSON_OBJECT(
                'equipment_id', ce.equipment_id,
                'name', ce.name,
                'asset_code', ce.asset_code
            )), ']'), '[]'
        ) AS child_equipments,
        COALESCE(
            CONCAT('[', GROUP_CONCAT(DISTINCT JSON_OBJECT(
                'spare_part_id', sp.spare_part_id,
                'name', sp.name,
                'asset_code', sp.asset_code
            )), ']'), '[]'
        ) AS spare_parts,
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

    LEFT JOIN (
        SELECT rg.subcategory_id, MIN(rg.group_user_id) AS group_user_id
        FROM relation_group rg
        JOIN group_user gu ON gu.group_user_id = rg.group_user_id
        WHERE gu.type = 'ผู้ใช้งาน'
        GROUP BY rg.subcategory_id
    ) rg_user ON rg_user.subcategory_id = e.subcategory_id
    LEFT JOIN group_user gu1 ON gu1.group_user_id = rg_user.group_user_id

    LEFT JOIN (
        SELECT rg.subcategory_id, MIN(rg.group_user_id) AS group_user_id
        FROM relation_group rg
        JOIN group_user gu ON gu.group_user_id = rg.group_user_id
        WHERE gu.type = 'ผู้ดูแลหลัก'
        GROUP BY rg.subcategory_id
    ) rg_responsible ON rg_responsible.subcategory_id = e.subcategory_id
    LEFT JOIN group_user gu2 ON gu2.group_user_id = rg_responsible.group_user_id

    -- ใช้ users.ID แทน user_id
    LEFT JOIN users u1 ON u1.ID = e.user_id
    LEFT JOIN users u2 ON u2.ID = e.updated_by

    " . ($search ? $searchSql : "WHERE e.equipment_id IS NOT NULL") . "
    GROUP BY e.equipment_id
    ORDER BY e.equipment_id Desc
    LIMIT :limit OFFSET :offset
";

    // นับจำนวนทั้งหมดสำหรับ pagination
    $countSql = "SELECT COUNT(DISTINCT e.equipment_id) FROM equipments e " . ($search ? $searchSql : '');
    $countStmt = $dbh->prepare($countSql);
    foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
    $countStmt->execute();
    $totalItems = (int) $countStmt->fetchColumn();
    $totalPages = ceil($totalItems / $limit);

    // ดึงข้อมูลหลัก
    $stmt = $dbh->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // decode JSON fields
    foreach ($results as &$row) {
        $row['filesInfo'] = json_decode($row['filesInfo'], true);
        $row['spare_parts'] = json_decode($row['spare_parts'], true);
        $row['child_equipments'] = json_decode($row['child_equipments'], true);
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
