<?php
include "../config/jwt.php";
$input = json_decode(file_get_contents('php://input'));
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(array("status" => "error", "message" => "post method!!!"));
    die();
}
try {
    $query = "SELECT department_id, department_name FROM `departments`";

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
