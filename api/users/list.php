<?php
include "../config/jwt.php";
$input = json_decode(file_get_contents('php://input'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array("status" => "error", "message" => "post method!!!"));
    die();
}
try {
    $query = "SELECT * FROM `role` where 1";

    $stmt = $dbh->prepare($query);
//    $stmt->bindParam(1, $menuPath);
//    $stmt->bindParam(2, $role_id);
    $stmt->execute();
    if ($stmt->rowCount() > 0) {
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($results);
    } else {

        echo json_encode(["status" => "error", "message" => "error"]);
    }


} catch (Exception $e) {
    echo json_encode(array("status" => "error", "message" => $e->getMessage()));
}
