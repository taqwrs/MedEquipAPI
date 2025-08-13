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
    $startDate = $input->startDate ?? '';
    $endDate = $input->endDate ?? '';
    $filterType = $input->filterType ?? 'day';
    $role_id = $input->role_id ?? ''; // รับ role_id จาก token
    $div = $input->div ?? ''; // รับ div (department) จาก token

    if ($filterType === 'day') {
        $query = "
        SELECT 
            history.*,
            ward.department,
            ward.category,
            ward.ward_id AS real_ward_id
        FROM 
            intern_productivity.history
        INNER JOIN 
            intern_productivity.ward ON history.ward_id = ward.ward_id
        ";

        $whereConditions = [];
        $params = [];

        if (!empty($startDate) && !empty($endDate)) {
            $whereConditions[] = "history.date BETWEEN :startDate AND :endDate";
            $params[':startDate'] = $startDate;
            $params[':endDate'] = $endDate;
        }

        // เพิ่มเงื่อนไขถ้า role_id = 39 ให้แสดงเฉพาะ department ที่ตรงกับ div ของผู้ใช้
        if ($role_id == 39 && !empty($div)) {
            $whereConditions[] = "ward.department = :department";
            $params[':department'] = $div;
        }

        if (!empty($whereConditions)) {
            $query .= " WHERE " . implode(" AND ", $whereConditions);
        }

        $query .= " ORDER BY history.date DESC, ward.ward_id ASC";

        $stmt = $dbh->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $data = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rn_actual = $row['rn_actual_ADJ'] ?? $row['rn_actual'];
                $na_actual = $row['na_actual_ADJ'] ?? $row['na_actual'];
                $TotalHN_dy = $row['visit_count'] * $row['NHPWU'];
                $total_actual = ($na_actual + $rn_actual) * $row['FTE_1'];
                $productivity_score = ($total_actual > 0)
                    ? ($TotalHN_dy * 100) / $total_actual
                    : 0;

                $data[] = [
                    'date' => $row['date'],
                    'department' => $row['department'],
                    'category' => $row['category'],
                    'visit_count' => $row['visit_count'],
                    'NHPWU' => $row['NHPWU'],
                    'TotalHN_dy' => $TotalHN_dy,
                    'FTE_1' => $row['FTE_1'],
                    'rn_actual' => $rn_actual,
                    'na_actual' => $na_actual,
                    'total_actual' => $total_actual,
                    'productivity_score' => round($productivity_score, 2),
                    'message' => ''
                ];
            }

            echo json_encode([
                "status" => "success",
                "filterType" => $filterType,
                "startDate" => $startDate,
                "endDate" => $endDate,
                "data" => $data,
                "dep" => $div // ส่งค่า div กลับไปด้วยเพื่อ debug
            ]);
        } else {
            echo json_encode(["status" => "error", "message" => "No data found"]);
        }
    } elseif ($filterType === 'month') {
        $requiredDaysInMonth = 24;
        $query = "
            SELECT *,
            AVG(productivity_score) AS productivity_score_mth,
            AVG(na_actual_ADJ) AS na_AVG,
            AVG(rn_actual_ADJ) AS rn_AVG,
            SUM(visit_count) AS visit_count_mth,
            SUM(TotalHN_dy) AS TotalHN_mth,
            ward.ward_id AS real_ward_id,
            ward.department,
            ward.category
            FROM 
            `history` inner join ward on history.ward_id = ward.ward_id 
        ";

        $whereConditions = [];
        $params = [];

        $whereConditions[] = "history.date BETWEEN :startDate AND :endDate";
        $params[':startDate'] = $startDate;
        $params[':endDate'] = $endDate;

        // เพิ่มเงื่อนไขถ้า role_id = 39 ให้แสดงเฉพาะ department ที่ตรงกับ div ของผู้ใช้
        if ($role_id == 39 && !empty($div)) {
            $whereConditions[] = "ward.department = :department";
            $params[':department'] = $div;
        }

        $query .= " WHERE " . implode(" AND ", $whereConditions);
        $query .= " GROUP by ward.ward_id";

        $stmt = $dbh->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $data = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rn_actual = (float)$row['rn_AVG'];
                $na_actual = (float)$row['na_AVG'];
                $TotalHN_mth = $row['visit_count_mth'] * $row['NHPWU'];
                $FTE_1 = (float)$row['FTE_1'] * 24;
                $total_actual = ((float)$na_actual + (float)$rn_actual) * $FTE_1;
                $productivity_score = ($total_actual > 0)
                    ? ($TotalHN_mth * 100) / $total_actual
                    : 0;
                $data[] = [
                    'date' => $row['date'],
                    'department' => $row['department'],
                    'category' => $row['category'],
                    'visit_count' => $row['visit_count_mth'],
                    'NHPWU' => $row['NHPWU'],
                    'TotalHN_dy' => $TotalHN_mth,
                    'FTE_1' => $FTE_1,
                    'rn_actual' => $rn_actual,
                    'na_actual' => $na_actual,
                    'total_actual' => $total_actual,
                    'productivity_score' => round($productivity_score, 2),
                    'message' => ''
                ];
            }

            echo json_encode([
                "status" => "success",
                "filterType" => $filterType,
                "startDate" => $startDate,
                "endDate" => $endDate,
                "data" => $data,
                "dep" => $div
            ]);
        } else {
            echo json_encode(["status" => "error", "message" => "No data found"]);
        }
    } elseif ($filterType === 'year') {
        $requiredDaysInYear = 289;
        $query = "
            SELECT *,
            AVG(productivity_score) AS productivity_score_yr,
            AVG(na_actual_ADJ) AS na_AVG,
            AVG(rn_actual_ADJ) AS rn_AVG,
            SUM(visit_count) AS visit_count_yr,
            SUM(TotalHN_dy) AS TotalHN_yr,
            ward.ward_id AS real_ward_id,
            ward.department,
            ward.category
            FROM 
            `history` inner join ward on history.ward_id = ward.ward_id 
        ";

        $whereConditions = [];
        $params = [];

        $whereConditions[] = "history.date BETWEEN :startDate AND :endDate";
        $params[':startDate'] = $startDate;
        $params[':endDate'] = $endDate;

        // เพิ่มเงื่อนไขถ้า role_id = 39 ให้แสดงเฉพาะ department ที่ตรงกับ div ของผู้ใช้
        if ($role_id == 39 && !empty($div)) {
            $whereConditions[] = "ward.department = :department";
            $params[':department'] = $div;
        }

        $query .= " WHERE " . implode(" AND ", $whereConditions);
        $query .= " GROUP by ward.ward_id";

        $stmt = $dbh->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $data = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rn_actual = (float)$row['rn_AVG'];
                $na_actual = (float)$row['na_AVG'];
                $TotalHN_yr = $row['visit_count_yr'] * $row['NHPWU'];
                $FTE_1 = $row['FTE_1'] * 289;
                $total_actual = ((float)$na_actual + (float)$rn_actual) * $FTE_1;
                $productivity_score = ($total_actual > 0)
                    ? ($TotalHN_yr * 100) / $total_actual
                    : 0;
                $data[] = [
                    'date' => $row['date'],
                    'department' => $row['department'],
                    'category' => $row['category'],
                    'visit_count' => $row['visit_count_yr'],
                    'NHPWU' => $row['NHPWU'],
                    'TotalHN_dy' => $TotalHN_yr,
                    'FTE_1' => $FTE_1,
                    'rn_actual' => $rn_actual,
                    'na_actual' => $na_actual,
                    'total_actual' => $total_actual,
                    'productivity_score' => round($productivity_score, 2),
                    'message' => ''
                ];
            }

            echo json_encode([
                "status" => "success",
                "filterType" => $filterType,
                "startDate" => $startDate,
                "endDate" => $endDate,
                "data" => $data,
                "dep" => $div
            ]);
        } else {
            echo json_encode(["status" => "error", "message" => "No data found"]);
        }
    }
} catch (Exception $e) {
    echo json_encode(array("status" => "error", "message" => $e->getMessage()));
}
