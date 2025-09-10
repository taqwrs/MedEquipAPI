<?php
include "../config/jwt.php";

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(array("status" => "error", "message" => "GET method required"));
    die();
}

try {
    $query = "
        SELECT 
            et.transfer_id,
            et.transfer_type,
            et.transfer_date,
            et.reason,

            eq.equipment_id,
            eq.name AS equipment_name,
            eq.asset_code,
            eq.subcategory_id,
            sc.name AS subcategory_name,
            sc.category_id,
            sc.type AS subcategory_type,

            eq.location_department_id,
            d_eq.department_name AS equipment_location_department,
            eq.location_details,

            et.from_department_id,
            d_from.department_name AS from_department_name,
            et.to_department_id,
            d_to.department_name AS to_department_name,
            et.location_department_id AS transfer_location_department_id,
            d_loc.department_name AS transfer_location_department,

            et.transfer_user_id,
            u_transfer.full_name AS transfer_user_name,
            et.recipient_user_id,
            u_recipient.full_name AS recipient_user_name,

            ru.relation_user_id,
            ru.group_user_id,
            ru.u_id,
            gu.group_name,
            gu.type AS group_type,

            rg.relation_group_id,
            rg.subcategory_id AS rg_subcategory_id

        FROM equipment_transfers et
        JOIN equipments eq ON et.equipment_id = eq.equipment_id
        LEFT JOIN equipment_subcategories sc ON eq.subcategory_id = sc.subcategory_id
        LEFT JOIN departments d_eq ON eq.location_department_id = d_eq.department_id
        LEFT JOIN departments d_from ON et.from_department_id = d_from.department_id
        LEFT JOIN departments d_to ON et.to_department_id = d_to.department_id
        LEFT JOIN departments d_loc ON et.location_department_id = d_loc.department_id
        LEFT JOIN users u_transfer ON et.transfer_user_id = u_transfer.user_id
        LEFT JOIN users u_recipient ON et.recipient_user_id = u_recipient.user_id
        LEFT JOIN relation_user ru ON u_transfer.user_id = ru.u_id OR u_recipient.user_id = ru.u_id
        LEFT JOIN group_user gu ON ru.group_user_id = gu.group_user_id
        LEFT JOIN relation_group rg ON gu.group_user_id = rg.group_user_id
    ";

    $stmt = $dbh->prepare($query);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["status" => "ok", "data" => $results], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(["status" => "error", "message" => "ไม่พบข้อมูล"]);
    }

} catch (Exception $e) {
    echo json_encode(array("status" => "error", "message" => $e->getMessage()));
}
