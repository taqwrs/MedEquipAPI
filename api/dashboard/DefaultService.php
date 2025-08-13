<?php

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

include "../config/jwt.php";

$input = json_decode(file_get_contents('php://input'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array("status" => "error", "message" => "POST method required"));
    die();
}

try {

    echo $currentDay = date("d");
    echo $currentMonth = date("n");
    echo $currentYear = date("Y");

    $query2 = "SELECT * FROM `ward`";
    $stmt2 = $dbh->prepare($query2);
    $stmt2->execute();
    while ($result2 = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        $query3 = "SELECT * FROM TB_default WHERE ward = ? AND day = ? AND month = ? AND year = ?";
        $stmt3 = $dbh->prepare($query3);
        $stmt3->bindParam(1, $result2['department']);
        $stmt3->bindParam(2, $currentDay);
        $stmt3->bindParam(3, $currentMonth);
        $stmt3->bindParam(4, $currentYear);
        $stmt3->execute();
        echo $result2['ward_id'];
        if ($stmt3->rowCount() > 0) {
            while ($result3 = $stmt3->fetch(PDO::FETCH_ASSOC)) {
                $query4 = "UPDATE productivity SET rn_actual = ?, na_actual = ?,rn_actual_ADJ = null , na_actual_ADJ = null WHERE ward_id = ?";
                $stmt4 = $dbh->prepare($query4);
                $stmt4->bindParam(1, $result3['rn']);
                $stmt4->bindParam(2, $result3['na']);
                $stmt4->bindParam(3, $result2['ward_id']);
                $stmt4->execute();
            }
        }

    }



} catch (Exception $e) {
    echo json_encode(array("status" => "error", "message" => $e->getMessage()));
}

