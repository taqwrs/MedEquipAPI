<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS, GET");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Ensure DB connection is available
include_once __DIR__ . "/../config/config.php";

// โหลด JWT เฉพาะกรณีมี Authorization header
$authHeader = null;
if (function_exists('apache_request_headers')) {
    $reqHeaders = apache_request_headers();
    $authHeader = $reqHeaders['Authorization'] ?? $reqHeaders['authorization'] ?? null;
}
if (!$authHeader) {
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}
if ($authHeader) {
    include "../config/jwt.php"; // optional auth
}

// CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
// รองรับทั้ง GET และ POST
$method = $_SERVER['REQUEST_METHOD'];
$sparePartId = 0;

if ($method === 'GET') {
    $sparePartId = (int)($_GET['spare_id'] ?? $_GET['spare_part_id'] ?? 0);
} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $sparePartId = (int)($input['spare_id'] ?? $input['spare_part_id'] ?? 0);
} else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit;
}

if (!$sparePartId) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing spare part ID."]);
    exit;
}

try {
    $sql = "
    SELECT
        sp.*,
        it.name AS import_type_name,
        sc.name AS subcategory_name,
        d.department_name,
        mc.name AS manufacturer_company_name,
        scp.name AS supplier_company_name,
        cc.name AS maintainer_company_name,
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

    -- relation_group สำหรับผู้ใช้งาน
    LEFT JOIN (
        SELECT rg.subcategory_id, MIN(rg.group_user_id) AS group_user_id
        FROM relation_group rg
        JOIN group_user gu ON gu.group_user_id = rg.group_user_id
        WHERE gu.type = 'ผู้ใช้งาน'
        GROUP BY rg.subcategory_id
    ) rg_user ON rg_user.subcategory_id = sp.spare_subcategory_id
    LEFT JOIN group_user gu1 ON gu1.group_user_id = rg_user.group_user_id

    -- relation_group สำหรับผู้ดูแลหลัก
    LEFT JOIN (
        SELECT rg.subcategory_id, MIN(rg.group_user_id) AS group_user_id
        FROM relation_group rg
        JOIN group_user gu ON gu.group_user_id = rg.group_user_id
        WHERE gu.type = 'ผู้ดูแลหลัก'
        GROUP BY rg.subcategory_id
    ) rg_responsible ON rg_responsible.subcategory_id = sp.spare_subcategory_id
    LEFT JOIN group_user gu2 ON gu2.group_user_id = rg_responsible.group_user_id

    LEFT JOIN users u1 ON u1.ID = sp.user_id
    LEFT JOIN users u2 ON u2.ID = sp.updated_by
    LEFT JOIN file_spare fs ON fs.spare_part_id = sp.spare_part_id
    LEFT JOIN equipments e ON e.equipment_id = sp.equipment_id

    WHERE sp.spare_part_id = :id
    GROUP BY sp.spare_part_id
    LIMIT 1
    ";

    $stmt = $dbh->prepare($sql);
    $stmt->bindValue(':id', $sparePartId, PDO::PARAM_INT);
    $stmt->execute();
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "ไม่พบข้อมูลอะไหล่ ID $sparePartId"]);
        exit;
    }

    $data['filesInfo'] = json_decode($data['filesInfo'], true);

    echo json_encode([
        "status" => "success",
        "data" => $data
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
