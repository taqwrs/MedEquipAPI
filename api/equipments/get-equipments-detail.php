<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS, GET");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Ensure DB connection is available for all requests
include_once __DIR__ . "/../config/config.php";

$authHeader = null;
if (function_exists('apache_request_headers')) {
    $reqHeaders = apache_request_headers();
    $authHeader = isset($reqHeaders['Authorization']) ? $reqHeaders['Authorization'] : (isset($reqHeaders['authorization']) ? $reqHeaders['authorization'] : null);
}
if (!$authHeader) {
    // Fallback to common $_SERVER keys
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}

if ($authHeader) {
    include "../config/jwt.php"; 
}
// Handle CORS preflight OPTIONS request early so browser can proceed with POST/GET
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Accept GET or POST. For GET, read equipment_id from query string (equipment_id or id).
$method = $_SERVER['REQUEST_METHOD'];
$equipmentId = 0;
if ($method === 'GET') {
    $equipmentId = (int)($_GET['equipment_id'] ?? $_GET['id'] ?? 0);
} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $equipmentId = (int)($input['equipment_id'] ?? 0);
} else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit;
}

if (!$equipmentId) {
    http_response_code(400); // Bad Request
    echo json_encode(["status" => "error", "message" => "Missing equipment ID."]);
    exit;
}

try {
    // นำ SQL เดิมของ Equipment มาใช้ แต่เปลี่ยนส่วนเงื่อนไข
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

    -- relation_group สำหรับผู้ใช้งาน
    LEFT JOIN (
        SELECT rg.subcategory_id, MIN(rg.group_user_id) AS group_user_id
        FROM relation_group rg
        JOIN group_user gu ON gu.group_user_id = rg.group_user_id
        WHERE gu.type = 'ผู้ใช้งาน'
        GROUP BY rg.subcategory_id
    ) rg_user ON rg_user.subcategory_id = e.subcategory_id
    LEFT JOIN group_user gu1 ON gu1.group_user_id = rg_user.group_user_id

    -- relation_group สำหรับผู้ดูแลหลัก
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
    
    -- *** เงื่อนไขหลัก: ดึงข้อมูลเฉพาะ ID ที่ระบุเท่านั้น ***
    WHERE e.equipment_id = :id 
    GROUP BY e.equipment_id
    LIMIT 1
    ";

    $stmt = $dbh->prepare($sql);
    $stmt->bindValue(':id', $equipmentId, PDO::PARAM_INT);
    $stmt->execute();
    $data = $stmt->fetch(PDO::FETCH_ASSOC); // ดึงข้อมูลเดียวเท่านั้น

    if (!$data) {
        http_response_code(404); // Not Found
        echo json_encode(["status" => "error", "message" => "ไม่พบข้อมูลอุปกรณ์ ID $equipmentId"]);
        exit;
    }

    // decode JSON fields
    $data['filesInfo'] = json_decode($data['filesInfo'], true);
    $data['spare_parts'] = json_decode($data['spare_parts'], true);
    $data['child_equipments'] = json_decode($data['child_equipments'], true);
    
    // จัดรูปแบบ main_equipment
    if ($data['main_equipment_id'] !== null) {
        $data['main_equipment'] = [
            'equipment_id' => $data['main_equipment_id'],
            'name' => $data['main_equipment_name'],
            'asset_code' => $data['main_equipment_asset_code'],
        ];
    }
    
    // ลบ Key ที่ไม่จำเป็นออกไป (ทางเลือก)
    // unset($data['main_equipment_name'], $data['main_equipment_asset_code']);


    echo json_encode([
        "status" => "success",
        "data" => $data, // คืนค่าเป็น Object เดียว
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}