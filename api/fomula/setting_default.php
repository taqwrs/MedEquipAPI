<?php
include "../config/jwt.php";
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json');

error_reporting(E_ALL);
ini_set('display_errors', 1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method required"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

if (isset($input['data']) && is_array($input['data'])) {
    $data = $input['data'];
    
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
        
        $dbh->beginTransaction();
        
        $insertQuery = "
            INSERT INTO `TB_default` (`ward`, `day`, `month`, `year`, `rn`, `na`, `employee_code`)
            VALUES (:ward, :day, :month, :year, :rn, :na, :employee_code)
        ";

        $insertStmt = $dbh->prepare($insertQuery);
        $affected = 0;
        $processed = 0;
        $errors = [];

        foreach ($data as $index => $row) {
            $processed++;
            
            if (!isset($row['ward']) || !isset($row['day']) || !isset($row['month']) || !isset($row['year'])) {
                $errors[] = "Row $index: Missing required fields (ward, day, month, year)";
                continue;
            }

            $ward = $row['ward'];
            $day = (int)$row['day'];
            $month = (int)$row['month'];
            $year = (int)$row['year'];
            $rn = null;
            $na = null;
            
            if (isset($row['rn']) && $row['rn'] !== null && $row['rn'] !== '') {
                $rn = is_numeric($row['rn']) ? (float)$row['rn'] : null;
                if ($rn === null) {
                    $errors[] = "Row $index: Invalid numeric value for 'rn': " . $row['rn'];
                    continue;
                }
            }
            
            if (isset($row['na']) && $row['na'] !== null && $row['na'] !== '') {
                $na = is_numeric($row['na']) ? (float)$row['na'] : null;
                if ($na === null) {
                    $errors[] = "Row $index: Invalid numeric value for 'na': " . $row['na'];
                    continue;
                }
            }

            if ($r_id !== null && $r_id !== 9 && $r_id !== 6) {
                if ($ward !== $dep) {
                    $errors[] = "Row $index: No permission to insert ward '$ward'. You can only insert data for ward '$dep'";
                    continue;
                }
            }
            
            try {
                $success = $insertStmt->execute([
                    ':ward' => $ward,
                    ':day' => $day,
                    ':month' => $month,
                    ':year' => $year,
                    ':rn' => $rn,
                    ':na' => $na,
                    ':employee_code' => $employee_code,
                ]);
                
                if ($success && $insertStmt->rowCount() > 0) {
                    $affected++;
                }
                
            } catch (PDOException $e) {
                $errors[] = "Row $index error: " . $e->getMessage();
            }
        }

        $dbh->commit();

        $response = [
            "status" => "success", 
            "message" => "Inserted $processed rows, affected $affected rows.",
            "details" => [
                "processed" => $processed,
                "affected" => $affected,
                "errors" => $errors,
                "user_permissions" => [
                    "role_id" => $r_id,
                    "allowed_ward" => ($r_id !== null && $r_id !== 9 && $r_id !== 6) ? $dep : "all"
                ]
            ]
        ];
        
        echo json_encode($response);

    } catch (Exception $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollback();
        }
        echo json_encode([
            "status" => "error", 
            "message" => "Database error: " . $e->getMessage(),
            "trace" => $e->getTraceAsString()
        ]);
    }
} else {
    try {
        $query_user = "SELECT * FROM users WHERE employee_code = :employee_code";
        $stmt1 = $dbh->prepare($query_user);
        $stmt1->bindParam(":employee_code", $employee_code);
        $stmt1->execute();

        $dep = null;
        $r_id = null;

        while ($row = $stmt1->fetch(PDO::FETCH_ASSOC)) {
            $dep = $row['department'];
            $r_id = $row['role_id'];
        }

        $query = "SELECT * FROM `TB_default`";
        
        if ($r_id !== null && $r_id !== 9 && $r_id !== 6) {
            $query .= " WHERE ward = :dep"; 
        }
        
        $query .= " ORDER BY year DESC, month ASC, day ASC";
        
        $stmt = $dbh->prepare($query);
        
        if ($r_id !== null && $r_id !== 9 && $r_id !== 6 && $dep !== null) {
            $stmt->bindParam(":dep", $dep);
        }
        
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($results as &$result) {
                if (isset($result['rn']) && is_numeric($result['rn'])) {
                    $result['rn'] = (float)$result['rn'];
                }
                if (isset($result['na']) && is_numeric($result['na'])) {
                    $result['na'] = (float)$result['na'];
                }
            }
            
            echo json_encode([
                "status" => "ok", 
                "data" => $results,
                "user_info" => [
                    "department" => $dep,
                    "role_id" => $r_id
                ]
            ]);
        } else {
            echo json_encode([
                "status" => "ok", 
                "data" => [],
                "user_info" => [
                    "department" => $dep,
                    "role_id" => $r_id
                ]
            ]);
        }

    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}
?>