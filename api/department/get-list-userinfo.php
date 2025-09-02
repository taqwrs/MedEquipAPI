<?php
include "../config/jwt.php";
$input = json_decode(file_get_contents('php://input'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array("status" => "error", "message" => "post method!!!"));
    die();
}
try {
    // $query = "SELECT user_id, department, full_name FROM `users`";
    $query = "
    SELECT 
    u.user_id,
    u.department_id,
    d.department_name,
    u.full_name,
    u.role_id,
    r.role_name
FROM users u
LEFT JOIN departments d ON u.department_id = d.department_id
LEFT JOIN roles r ON u.role_id = r.role_id

    ";

    $stmt = $dbh->prepare($query);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["status" => "ok", "data" => $results]);
    } else {
        echo json_encode(["status" => "error", "message" => "ไม่พบข้อมูล"]);
    }
} catch (Exception $e) {
    echo json_encode(array("status" => "error", "message" => $e->getMessage()));
}
