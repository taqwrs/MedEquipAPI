<?php
include "../config/jwt.php";
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method required"]);
    exit;
}

try {
    $query_user = "SELECT * FROM users WHERE employee_code = :employee_code";
    $stmt_user = $dbh->prepare($query_user);
    $stmt_user->bindParam(":employee_code", $employee_code);
    $stmt_user->execute();

    $dep = null;
    $r_id = null;
    
    while ($row = $stmt_user->fetch(PDO::FETCH_ASSOC)) {
        $dep = $row['department'];
        $r_id = $row['role_id'];
    }
    $query = "
        SELECT 
            w.department,
            w.category,
            nh.NHPWU,
            nh.FTE_1,
            nh.date
        FROM nursing_hours nh
        INNER JOIN ward w ON nh.ward_id = w.ward_id
        WHERE nh.active = 1
    ";

    if ($r_id !== 9 && $r_id !== 6) {
        $query .= " AND w.department = :department";
    }

    $query .= " GROUP BY nh.ward_id ORDER BY w.department";

    $stmt = $dbh->prepare($query);

    if ($r_id !== 9 && $r_id !== 6) {
        $stmt->bindParam(":department", $dep);
    }

    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(["status" => "success", "data" => $results]);
    } else {
        echo json_encode(["status" => "error", "message" => "No data found"]);
    }

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>

// ก