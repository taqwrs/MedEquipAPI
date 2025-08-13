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

    $query = "
       SELECT *,w.ward_id as real_ward_id,p.employee_code as real_employee_code FROM intern_productivity.nursing_hours nh INNER JOIN intern_productivity.productivity p ON nh.NH_id = p.NH_id INNER JOIN ward w ON nh.ward_id = w.ward_id;
    ";
    $stmt = $dbh->prepare($query);
    $stmt->execute();
    $data = null;
    while ($result = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ward = $result['department'];
        $response = @file_get_contents("http://192.168.2.21/productivity/getWardNow.php?ward=" . urlencode($ward));
        $total = json_decode($response, true);

        $rn_actual = $result['rn_actual_ADJ'] !== null ? (float)$result['rn_actual_ADJ'] : (float)$result['rn_actual'];
        $na_actual = $result['na_actual_ADJ'] !== null ? (float)$result['na_actual_ADJ'] : (float)$result['na_actual'];
     

        $TotalHN_dy = $result['NHPWU'] * $total;
        // $totalActual = $result['Total_Actual'];
        $totalActual = ($rn_actual+$na_actual)*$result['FTE_1'];
        $productivity = ($TotalHN_dy * 100) / $totalActual;
        $currenttime = date("Y-m-d");

        $sqlAdd = "INSERT INTO history(date,employee_code,ward_id,visit_count,NHPWU,TotalHN_dy,FTE_1,rn_actual,na_actual,total_actual,productivity_score,rn_actual_ADJ,na_actual_ADJ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)";
        $stmt1 = $dbh->prepare($sqlAdd);
        $stmt1->bindParam(1, $currenttime);
        $stmt1->bindParam(2, $result['employee_code']);
        $stmt1->bindParam(3, $result['real_ward_id']);
        $stmt1->bindParam(4, $total);
        $stmt1->bindParam(5, $result['NHPWU']);
        $stmt1->bindParam(6, $TotalHN_dy);
        $stmt1->bindParam(7, $result['FTE_1']);
        $stmt1->bindParam(8, $result['rn_actual']);
        $stmt1->bindParam(9, $result['na_actual']);
        $stmt1->bindParam(10, $totalActual);
        // $stmt1->bindParam(10, $result['Total_Actual']);
        $stmt1->bindParam(11, $productivity);
        // $stmt1->bindParam(12, $result['rn_actual_ADJ']);
        // $stmt1->bindParam(13, $result['na_actual_ADJ']);
        if ($result['rn_actual_ADJ'] !== null) {
            $rn_actual_ADJ = (float)$result['rn_actual_ADJ'];
        } else {
            $rn_actual_ADJ = (float)$result['rn_actual'];
        }
        $stmt1->bindParam(12, $rn_actual_ADJ);
        if ($result['na_actual_ADJ'] !== null) {
            $na_actual_ADJ = (float)$result['na_actual_ADJ'];
        } else {
            $na_actual_ADJ = (float)$result['na_actual'];
        }
        $stmt1->bindParam(13, $na_actual_ADJ);
        $stmt1->execute();

    }

} catch (Exception $e) {
    echo json_encode(array("status" => "error", "message" => $e->getMessage()));
}

