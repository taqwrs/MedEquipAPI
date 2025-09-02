<?php
include "../config/jwt.php";

$input = json_decode(file_get_contents('php://input'));

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(array("status" => "error", "message" => "GET method required"));
    die();
}

try {
    $query = "SELECT 
                et.transfer_id,
                et.transfer_type,
                et.transfer_date,
                et.reason,
                et.detail_trans,
                
                -- ข้อมูลเครื่องมือแพทย์
                eq.equipment_id,
                eq.name AS equipment_name,
                
                -- แผนกต้นทาง / ปลายทาง / ติดตั้ง
                d_from.department_name AS from_department_name,
                d_to.department_name AS to_department_name,
                d_install.department_name AS install_location_name,
                
                -- ผู้โอน
                u_from.ID AS transfer_user_id,
                u_from.user_id AS transfer_user_code,
                u_from.full_name AS transfer_user_name,
                d_user_from.department_name AS transfer_user_department,
                
                -- ผู้รับโอน
                u_to.ID AS recipient_user_id,
                u_to.user_id AS recipient_user_code,
                u_to.full_name AS recipient_user_name,
                d_user_to.department_name AS recipient_user_department,
                
                -- กลุ่ม relation
                et.relation_group_id

            FROM equipment_transfers et
            LEFT JOIN equipments eq ON et.equipment_id = eq.equipment_id
            LEFT JOIN departments d_from ON et.from_department_id = d_from.department_id
            LEFT JOIN departments d_to ON et.to_department_id = d_to.department_id
            LEFT JOIN departments d_install ON et.install_location_dep_id = d_install.department_id
            LEFT JOIN users u_from ON et.transfer_user_id = u_from.ID
            LEFT JOIN departments d_user_from ON u_from.department_id = d_user_from.department_id
            LEFT JOIN users u_to ON et.recipient_user_id = u_to.ID
            LEFT JOIN departments d_user_to ON u_to.department_id = d_user_to.department_id
            ORDER BY et.transfer_date DESC";

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
