<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

include "../config/jwt.php";
include "../config/pagination_helper.php"; // ใช้ helper

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method only"]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

try {
    // Base SQL
    $baseSql = "
    SELECT
        sp.*,
        it.name AS import_type_name,
        sc.name AS subcategory_name,
        d.department_name,
        mc.name AS manufacturer_company_name,
        scp.name AS supplier_company_name,
        cc.name AS maintainer_company_name,
        cc.phone AS maintainer_phone,
        cc.email AS maintainer_email,
        cc.line_id AS maintainer_line_id,
        gu1.group_name AS group_responsible_name,
        gu2.group_name AS group_user_name,
        u1.full_name AS user_full_name,
        u2.full_name AS updated_by_name,
        e.name AS equipment_name,
        e.asset_code AS equipment_asset_code,
        COALESCE(
            CONCAT('[', GROUP_CONCAT(DISTINCT JSON_OBJECT(
                'file_spare_id', fs.file_spare_id,
                'file_spare_name', fs.file_spare_name,
                'spare_url', fs.spare_url,
                'spare_type_name', fs.spare_type_name
            )), ']'), '[]'
        ) AS filesInfo
    FROM spare_parts sp
    LEFT JOIN import_types it ON it.import_type_id = sp.import_type_id
    LEFT JOIN spare_subcategories sc ON sc.spare_subcategory_id = sp.spare_subcategory_id
    LEFT JOIN departments d ON d.department_id = sp.location_department_id
    LEFT JOIN companies mc ON mc.company_id = sp.manufacturer_company_id
    LEFT JOIN companies scp ON scp.company_id = sp.supplier_company_id
    LEFT JOIN companies cc ON cc.company_id = sp.maintainer_company_id

    -- equipments ก่อน เพื่อให้ JOIN relation_group ใช้ได้
    LEFT JOIN equipments e ON e.equipment_id = sp.equipment_id

    -- ผู้ใช้งาน (จาก subcategory ของ equipment)
    LEFT JOIN (
        SELECT rg.subcategory_id, MIN(rg.group_user_id) AS group_user_id
        FROM relation_group rg
        JOIN group_user gu ON gu.group_user_id = rg.group_user_id
        WHERE gu.type = 'ผู้ดูแลหลัก'
        GROUP BY rg.subcategory_id
    ) rg_user ON e.subcategory_id = rg_user.subcategory_id
    LEFT JOIN group_user gu2 ON gu2.group_user_id = rg_user.group_user_id

    -- ผู้ดูแลหลัก (จาก subcategory ของ equipment)
    LEFT JOIN (
        SELECT rg.subcategory_id, MIN(rg.group_user_id) AS group_user_id
        FROM relation_group rg
        JOIN group_user gu ON gu.group_user_id = rg.group_user_id
        WHERE gu.type = 'ผู้ใช้งาน'
        GROUP BY rg.subcategory_id
    ) rg_responsible ON e.subcategory_id = rg_responsible.subcategory_id
    LEFT JOIN group_user gu1 ON gu1.group_user_id = rg_responsible.group_user_id

    LEFT JOIN users u1 ON u1.ID = sp.user_id
    LEFT JOIN users u2 ON u2.ID = sp.updated_by
    LEFT JOIN file_spare fs ON fs.spare_part_id = sp.spare_part_id
    ";


    // Count SQL
    $countSql = "SELECT COUNT(DISTINCT sp.spare_part_id) FROM spare_parts sp";

    // Where clause เริ่มต้น
    $whereClause = "WHERE sp.active = 1";

    // Additional params
    $additionalParams = [];

    // Handle record_status
    $record_status = trim($input['record_status'] ?? '');
    if ($record_status !== '') {
        $whereClause .= " AND sp.record_status = :record_status";
        $additionalParams[':record_status'] = $record_status;
    }

    // Fields for search
    $searchFields = [
        'sp.name',
        'sp.asset_code',
        'sp.end_date',
        'sp.status',
        'sp.record_status'
    ];

    // Call helper to get paginated search result
    $response = handlePaginatedSearch(
        $dbh,
        $input,
        $baseSql,
        $countSql,
        $searchFields,
        "GROUP BY sp.spare_part_id ORDER BY sp.spare_part_id DESC",
        $whereClause,
        $additionalParams
    );

    // Decode JSON fields
    if (!empty($response['data'])) {
        foreach ($response['data'] as &$row) {
            $row['filesInfo'] = json_decode($row['filesInfo'], true);
        }
    }

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
