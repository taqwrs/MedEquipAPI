<?php
include "../config/jwt.php";
$input = json_decode(file_get_contents("php://input"), true);

$category = $input["category"] ?? "IPD";
$div = $input["div"] ?? ""; 
$date = $input["date"] ?? date("Y-m-d");

try {
  
    $query_user = "SELECT * FROM users WHERE employee_code = :employee_code";
    $stmt1 = $dbh->prepare($query_user);
    $stmt1->bindParam(":employee_code", $employee_code);
    $stmt1->execute();

    while ($row = $stmt1->fetch(PDO::FETCH_ASSOC)) {
        $dep = $row['department'];
        $r_id = $row['role_id'];
    }

   $query = "
    SELECT 
        w.department,
        w.ward_id,
        w.category,
        p.date,
        p.rn_actual,
        p.rn_actual_ADJ,
        p.na_actual,
        p.na_actual_ADJ,
        p.visit_count,
        nh.NHPWU,
        nh.FTE_1,
        u.employee_code
    FROM productivity p
    JOIN ward w ON p.ward_id = w.ward_id
    JOIN users u ON p.employee_code = u.employee_code
    LEFT JOIN nursing_hours nh ON nh.NH_id = (
        SELECT NH_id 
        FROM productivity p2 
        WHERE p2.ward_id = p.ward_id 
        ORDER BY p2.date DESC 
        LIMIT 1
    )
    WHERE 1 = 1
";

    if ($category !== "" && strtoupper($category) !== "ALL") {
        $query .= " AND w.category = :category";
    }

    if ($r_id !== 9 && $r_id !== 6) {
        $query .= " AND w.department = :div";
    }
    
    // เปลี่ยนการเรียงลำดับเป็น ward_id
    $query .= " ORDER BY w.ward_id, p.date DESC";
    
    $stmt = $dbh->prepare($query);

    if ($category !== "" && strtoupper($category) !== "ALL") {
        $stmt->bindParam(":category", $category);
    }

    if ($r_id !== 9 && $r_id !== 6) {
        $stmt->bindParam(":div", $dep);
    }

    $stmt->execute();

    $data = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ward = $row['department'];
        if (strtoupper($ward) === 'SPU') {
            $visit_count = $row['visit_count'];
        } else {
            $response = @file_get_contents("http://192.168.2.21/productivity/getWardNow.php?IOPD=" . urlencode($row['category']) . "&ward=" . urlencode($ward));
            $api_data = json_decode($response, true);
            $visit_count = ($api_data && is_numeric($api_data)) ? $api_data : $row['visit_count'];
        }

        $data[] = array(
            'NHPWU' => $row['NHPWU'],
            'FTE_1' => $row['FTE_1'], 
            'date' => $row['date'],
            'department' => $row['department'],
            'employee_code' => $row['employee_code'],
            'na_actual' => ($row['na_actual_ADJ'] !== null ? (float)$row['na_actual_ADJ'] : (float)$row['na_actual']),
            'rn_actual' => ($row['rn_actual_ADJ'] !== null ? (float)$row['rn_actual_ADJ'] : (float)$row['rn_actual']),
            'visit_count' => $visit_count,
            'ward_id' => $row['ward_id']
        );
    }

    echo json_encode([
        "status" => "success",
        "data" => $data
    ]);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
?>