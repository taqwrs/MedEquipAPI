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
    $dep = $input->dep;
    $query = "
       SELECT *,w.ward_id as real_ward_id,p.employee_code as real_employee_code 
       FROM intern_productivity.nursing_hours nh 
       INNER JOIN intern_productivity.productivity p ON nh.NH_id = p.NH_id 
       INNER JOIN ward w ON nh.ward_id = w.ward_id
        WHERE w.category = ?
    ";

    $stmt = $dbh->prepare($query);
    $stmt->bindParam(1, $dep);

    $stmt->execute();

    if ($stmt->rowCount() > 0) {

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ward = $row['department'];
            $IOPD = $row['category'];

            // echo $IOPD;
            if ($IOPD !== 'SPU') {
            $response = @file_get_contents("http://192.168.2.21/productivity/getWardNow.php?IOPD=$IOPD&ward=" . urlencode($ward));
            $total = json_decode($response, true);
            }
            else  {
                $total =$row['visit_count'];
            }

            $rn_actual = $row['rn_actual_ADJ'] !== null ? (float)$row['rn_actual_ADJ'] : (float)$row['rn_actual'];
            $na_actual = $row['na_actual_ADJ'] !== null ? (float)$row['na_actual_ADJ'] : (float)$row['na_actual'];
            $FTE_1 = $row['FTE_1'];

            $TotalHN_dy = $row['NHPWU'] * $total;
            $totalActual = ($rn_actual+ $na_actual)* $FTE_1;
            if ( $TotalHN_dy !== null && $totalActual !== null && $totalActual != 0) {
                $productivity = ($TotalHN_dy * 100) / $totalActual;
            } else {
                $productivity = 0; // 
            }

            $data[] = array(
                'NHPWU' => (float)$row['NHPWU'],
                'date' => $row['date'],
                'FTE_1' => $row['FTE_1'],
                'active' => $row['active'],
                'P_id' => $row['P_id'],
                'NH_id' => $row['NH_id'],
                'visit_count' => $total,
                'ward_id' => $row['real_ward_id'],
                'employee_code' => $row['real_employee_code'],
                'TotalHN_dy' => $TotalHN_dy,
                'rn_actual'=> $rn_actual,
                'na_actual'=> $na_actual,
                'productivity_score' => $productivity,
                'Total_Actual' => $totalActual,
                'department' => $row['department'],
                'category' => $row['category'],
            );
        }

        echo json_encode(["status" => "success", "data" => $data]);
    } else {
        echo json_encode(["status" => "error", "message" => "No data found"]);
    }
} catch (Exception $e) {
    echo json_encode(array("status" => "error", "message" => $e->getMessage()));
}

