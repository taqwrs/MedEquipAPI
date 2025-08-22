<?php
include "../config/jwt.php";
$input = json_decode(file_get_contents("php://input"), true);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode([
        "status" => "error",
        "message" => "POST method required"
    ]);
    exit;
}

$department = $input["department"] ?? null;
$rn_actual  = $input["rn_actual"] ?? null;
$na_actual  = $input["na_actual"] ?? null;
$visit_count = $input["visit_count"] ?? null;

if (!$department || $rn_actual === null || $na_actual === null) {
    echo json_encode([
        "status" => "error",
        "message" => "ข้อมูลไม่ครบ: ต้องมี department, rn_actual, na_actual"
    ]);
    exit;
}

try {
    
    $wardStmt = $dbh->prepare("SELECT ward_id, category FROM ward WHERE department = :department");
    $wardStmt->bindParam(":department", $department);
    $wardStmt->execute();
    $ward = $wardStmt->fetch(PDO::FETCH_ASSOC);

    if (!$ward) {
        echo json_encode([
            "status" => "error",
            "message" => "ไม่พบ ward ที่ชื่อ $department"
        ]);
        exit;
    }

    $ward_id = $ward["ward_id"];
    $ward_category = $ward["category"];
    
    $query = "
        SELECT 
            p.p_id, 
            p.visit_count, 
            nh.NHPWU, 
            nh.FTE_1,
            p.rn_actual,
            p.na_actual
        FROM productivity p
        JOIN nursing_hours nh ON p.NH_id = nh.NH_id
        WHERE p.ward_id = :ward_id
        ORDER BY p.date DESC
        LIMIT 1
    ";
    $stmt = $dbh->prepare($query);
    $stmt->bindParam(':ward_id', $ward_id, PDO::PARAM_INT);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        echo json_encode([
            "status" => "error",
            "message" => "ไม่พบข้อมูล productivity ที่จะอัปเดต"
        ]);
        exit;
    }

    $row             = $stmt->fetch(PDO::FETCH_ASSOC);
    $current_visit_count = floatval($row['visit_count']);
    $NHPWU           = floatval($row['NHPWU']);
    $FTE_1           = floatval($row['FTE_1']);
    $productivity_id = intval($row['p_id']);
    $OldRn = floatval($row['rn_actual']);
    $OldNa = floatval($row['na_actual']);


    $final_visit_count = ($ward_category === "SPU" && $visit_count !== null) ? $visit_count : $current_visit_count;

    if ($FTE_1 <= 0) {
        echo json_encode([
            "status" => "error",
            "message" => "ค่า FTE_1 ไม่ถูกต้อง"
        ]);
        exit;
    }
    $insertQuery = "
            INSERT INTO `log` (`user_id`, `rn_old`, `na_old`, `rn_new`, `na_new`, `ward_id`)
            VALUES (:user_id, :rn_old, :na_old, :rn_new, :na_new, :ward_id)
        ";
      $insertStmt = $dbh->prepare($insertQuery);
       $insertStmt->execute([
                    ':user_id' => $user_id,
                    ':rn_old' => $OldRn,
                    ':na_old' => $OldNa,
                    ':rn_new' => $rn_actual,
                    ':na_new' => $na_actual,
                    ':ward_id' => $ward_id
                ]);
    $totalActual = ($rn_actual + $na_actual) * $FTE_1;
    $requiredHours = $final_visit_count * $NHPWU;
    $productivity = ($totalActual > 0) ? ($requiredHours * 100) / $totalActual : 0;
    $productivity_rounded = round($productivity, 2);

    if ($ward_category === "SPU" && $visit_count !== null) {
        $update = "
            UPDATE productivity
            SET 
                rn_actual_ADJ = :rn_actual,
                na_actual_ADJ = :na_actual,
                visit_count = :visit_count,
                Total_Actual = :Total_Actual,
                `Date` = NOW()
            WHERE p_id = :p_id
        ";
        $updateStmt = $dbh->prepare($update);
        $updateStmt->bindParam(':rn_actual', $rn_actual);
        $updateStmt->bindParam(':na_actual', $na_actual);
        $updateStmt->bindParam(':visit_count', $visit_count);
        $updateStmt->bindParam(':Total_Actual', $totalActual);
        $updateStmt->bindParam(':p_id', $productivity_id, PDO::PARAM_INT);
    } else {
        $update = "
            UPDATE productivity
            SET 
                rn_actual_ADJ = :rn_actual,
                na_actual_ADJ = :na_actual,
                Total_Actual = :Total_Actual,
                `Date` = NOW()
            WHERE p_id = :p_id
        ";
        $updateStmt = $dbh->prepare($update);
        $updateStmt->bindParam(':rn_actual', $rn_actual);
        $updateStmt->bindParam(':na_actual', $na_actual);
        $updateStmt->bindParam(':Total_Actual', $totalActual);
        $updateStmt->bindParam(':p_id', $productivity_id, PDO::PARAM_INT);
    }

    if ($updateStmt->execute()) {
        $dateStmt = $dbh->prepare("SELECT `Date` FROM productivity WHERE p_id = :p_id");
        $dateStmt->bindParam(':p_id', $productivity_id, PDO::PARAM_INT);
        $dateStmt->execute();
        $dateRow = $dateStmt->fetch(PDO::FETCH_ASSOC);
        $updatedDate = $dateRow ? $dateRow['Date'] : null;

        echo json_encode([
            "status" => "success",
            "message" => "อัปเดตข้อมูลสำเร็จ",
            "productivity_score" => $productivity_rounded,
            "Date" => $updatedDate,
            "updated_visit_count" => $final_visit_count
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "message" => "ไม่สามารถอัปเดตข้อมูลได้"
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Exception: " . $e->getMessage()
    ]);
}
?>