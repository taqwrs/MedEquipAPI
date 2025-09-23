<?php
include "../config/jwt.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit;
}

try {
    $u_id = $decoded->data->ID ?? null;

    $input = json_decode(file_get_contents("php://input"), true);
    $debug_info = ["user_id_from_jwt" => $u_id];

    // Query หลัก
    $sql = "
        SELECT 
            e.equipment_id,
            e.name AS equipment_name,
            e.asset_code,
            e.location_department_id,
            e.location_details,
            u.ID AS admin_id,
            u.user_id AS admin_user_id,
            u.full_name AS admin_name,
            d.department_name AS admin_department,
            gu.type AS group_type
        FROM equipments e
        LEFT JOIN relation_group rg 
            ON e.subcategory_id = rg.subcategory_id
        LEFT JOIN group_user gu 
            ON rg.group_user_id = gu.group_user_id
            AND gu.type = 'ผู้ดูแลหลัก'
        LEFT JOIN relation_user ru 
            ON gu.group_user_id = ru.group_user_id
        LEFT JOIN users u 
            ON ru.u_id = u.ID
        LEFT JOIN departments d 
            ON u.department_id = d.department_id
        WHERE e.record_status = 1
    ";

    $params = [];
    if (!empty($input['equipment_id'])) {
        $sql .= " AND e.equipment_id = :equipment_id";
        $params[':equipment_id'] = $input['equipment_id'];
    }

    $sql .= " ORDER BY e.equipment_id, u.full_name";

    $stmt = $dbh->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // จัดกลุ่ม main_admin
    $equipments = [];
    foreach ($results as $row) {
        $equipment_id = $row['equipment_id'];

        if (!isset($equipments[$equipment_id])) {
            $equipments[$equipment_id] = [
                'equipment_name' => $row['equipment_name'],
                'asset_code' => $row['asset_code'],
                'location_department_id' => $row['location_department_id'],
                'location_details' => $row['location_details'],
                'main_admin' => []
            ];
        }

        if (!empty($row['admin_id']) && $row['group_type'] === 'ผู้ดูแลหลัก') {
            $admin = [
                'ID' => $row['admin_id'],
                'user_id' => $row['admin_user_id'],
                'full_name' => $row['admin_name'],
                'department_name' => $row['admin_department']
            ];

            // ป้องกันซ้ำ
            $exists = false;
            foreach ($equipments[$equipment_id]['main_admin'] as $existing) {
                if ($existing['ID'] == $admin['ID']) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $equipments[$equipment_id]['main_admin'][] = $admin;
            }
        }
    }

    echo json_encode([
        "status" => "success",
        "message" => "Equipment details retrieved successfully",
        "data" => array_values($equipments),
        "total_records" => count($equipments),
        "debug_info" => $debug_info
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} finally {
    if (isset($dbh)) {
        $dbh = null;
    }
}
?>
