<?php
include "../config/jwt.php";
$input = json_decode(file_get_contents('php://input'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "post method!!!"]);
    exit;
}

$search = trim($input->search ?? '');
$page   = (int)($input->page ?? 1);
$limit  = (int)($input->limit ?? 10);
$offset = ($page - 1) * $limit;

try {
    $params = [];
    $searchSql = '';
    if ($search) {
        $searchSql = "WHERE e.name LIKE :search 
                      OR e.asset_code LIKE :search 
                      OR e.serial_number LIKE :search 
                      OR e.brand LIKE :search";
        $params[':search'] = "%$search%";
    }
    $countSql = "SELECT COUNT(DISTINCT e.equipment_id) 
                 FROM equipments e
                 LEFT JOIN file_equip f ON f.equipment_id = e.equipment_id
                 $searchSql";
    $countStmt = $dbh->prepare($countSql);
    $countStmt->execute($params);
    $totalItems = (int)$countStmt->fetchColumn();
    $totalPages = ceil($totalItems / $limit);

    // ดึงข้อมูลพร้อมรวมไฟล์แนบเป็น JSON
    $sql = "
    SELECT
        e.*,
        e.main_equipment_id,
        it.name AS import_type_name,
        sc.name AS subcategory_name,
        d.department_name,
        em.name AS main_equipment_name,
        mc.name AS manufacturer_company_name,
        mc.phone AS manufacturer_phone,
        mc.email AS manufacturer_email,
        mc.line_id AS manufacturer_line_id,
        scp.name AS supplier_company_name,
        scp.phone AS supplier_phone,
        scp.email AS supplier_email,
        scp.line_id AS supplier_line_id,
        cc.name AS maintainer_company_name,
        cc.phone AS maintainer_phone,
        cc.email AS maintainer_email,
        cc.line_id AS maintainer_line_id,
        gu1.group_name AS group_user_name,
        gu2.group_name AS group_responsible_name,
        u1.full_name AS user_full_name,
        u2.full_name AS updated_by_name,
        COALESCE(
            CONCAT('[', GROUP_CONCAT(
                JSON_OBJECT(
                    'file_equip_id', f.file_equip_id,
                    'file_equip_name', f.file_equip_name,
                    'equip_url', f.equip_url,
                    'equip_type_name', f.equip_type_name
                )
            ), ']'), '[]'
        ) AS filesInfo
    FROM equipments e
    LEFT JOIN equipments em ON em.equipment_id = e.main_equipment_id
    LEFT JOIN import_types it ON it.import_type_id = e.import_type_id
    LEFT JOIN equipment_subcategories sc ON sc.subcategory_id = e.subcategory_id
    LEFT JOIN departments d ON d.department_id = e.location_department_id
    LEFT JOIN companies mc ON mc.company_id = e.manufacturer_company_id
    LEFT JOIN companies scp ON scp.company_id = e.supplier_company_id
    LEFT JOIN companies cc ON cc.company_id = e.maintainer_company_id
    LEFT JOIN group_user gu1 ON gu1.group_user_id = e.group_user_id AND gu1.type = 'ผู้ดูแลหลัก'
    LEFT JOIN group_user gu2 ON gu2.group_user_id = e.group_responsible_id AND gu2.type = 'ผู้ใช้งาน'
    LEFT JOIN users u1 ON u1.user_id = e.user_id
    LEFT JOIN users u2 ON u2.user_id = e.updated_by
    LEFT JOIN file_equip f ON f.equipment_id = e.equipment_id
    $searchSql
    GROUP BY e.equipment_id
    LIMIT :limit OFFSET :offset
    ";

    $stmt = $dbh->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // แปลง JSON ของไฟล์แนบ
    foreach ($results as &$row) {
        $row['filesInfo'] = json_decode($row['filesInfo'], true);
    }

    echo json_encode([
        "status" => "success",
        "data" => $results,
        "pagination" => [
            "totalItems"  => $totalItems,
            "totalPages"  => $totalPages,
            "currentPage" => $page,
            "limit"       => $limit
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
