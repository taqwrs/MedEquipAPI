<?php
include "../config/jwt.php";
include "../config/pagination_helper.php";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// รับค่าจาก query string
$equipmentId = isset($_GET['equipment_id']) ? intval($_GET['equipment_id']) : null;
$roundId = isset($_GET['round_id']) ? intval($_GET['round_id']) : null;
$planId = isset($_GET['plan_id']) ? intval($_GET['plan_id']) : null;
$viewType = isset($_GET['viewType']) ? $_GET['viewType'] : null;

try {
    $result = [];
    // --- กรณีดู "ทุกรอบของอุปกรณ์" ---
    if ($viewType === "allRoundsOfEquipment" && $planId) {

        // SQL หลัก
        $baseSql = "
            SELECT e.equipment_id, e.asset_code, e.name, dmp.details_ma_id, mr.ma_result_id, 
                   mp.plan_name, dmp.start_date, mr.performed_date, mr.result, mr.details, mr.reason
            FROM maintenance_result mr
            INNER JOIN details_maintenance_plans dmp ON mr.details_ma_id = dmp.details_ma_id
            INNER JOIN equipments e ON e.equipment_id = mr.equipment_id
            INNER JOIN maintenance_plans mp ON mp.plan_id = dmp.plan_id
            WHERE dmp.plan_id = :plan_id
            " . ($equipmentId ? " AND e.equipment_id = :equipment_id" : "") . "
            ORDER BY dmp.details_ma_id ASC
        ";

        // SQL นับจำนวนทั้งหมด สำหรับ pagination
        $countSql = "
            SELECT COUNT(*) 
            FROM maintenance_result mr
            INNER JOIN details_maintenance_plans dmp ON mr.details_ma_id = dmp.details_ma_id
            INNER JOIN equipments e ON e.equipment_id = mr.equipment_id
            INNER JOIN maintenance_plans mp ON mp.plan_id = dmp.plan_id
            WHERE dmp.plan_id = :plan_id
            " . ($equipmentId ? " AND e.equipment_id = :equipment_id" : "") . "
        ";

        // fields ที่ใช้ค้นหา
        $searchFields = ['e.name', 'e.asset_code', 'mp.plan_name'];

        // bind parameter
        $additionalParams = [':plan_id' => $planId];
        if ($equipmentId) {
            $additionalParams[':equipment_id'] = $equipmentId;
        }

        // ใช้ฟังก์ชัน handlePaginatedSearch สำหรับ pagination + search
        $response = handlePaginatedSearch(
            $dbh,
            $_GET,
            $baseSql,
            $countSql,
            $searchFields,
            "",
            "",
            $additionalParams
        );

        if ($response['status'] === 'success') {
            $rows = $response['data'];
            $grouped = [];

            // จัดกลุ่มตาม equipment_id
            foreach ($rows as $r) {
                $eqId = $r['equipment_id'];
                if (!isset($grouped[$eqId])) {
                    $grouped[$eqId] = [
                        'name' => $r['asset_code'] . " - " . $r['name'],
                        'rounds' => []
                    ];
                }

                $grouped[$eqId]['rounds'][] = [
                    'round' => count($grouped[$eqId]['rounds']) + 1,
                    'details_ma_id' => $r['details_ma_id'],
                    'ma_result_id' => $r['ma_result_id'],
                    'plan_name' => $r['plan_name'],
                    'status' => $r['result'],
                    'details' => $r['details'] ?: '-',
                    'reason' => $r['reason'] ?: '-',
                    'start_date' => $r['start_date'],
                    'performed_date' => $r['performed_date'],
                ];
            }

            echo json_encode([
                'status' => 'success',
                'data' => array_values($grouped),
                'pagination' => $response['pagination']
            ]);
            exit;
        } else {
            echo json_encode($response);
            exit;
        }
    }

    // --- กรณีดู "ทุกอุปกรณ์ในรอบ" ---
    elseif ($viewType === "allEquipments" && $roundId) {
        $stmtCheck = $dbh->prepare("SELECT dmp.plan_id FROM details_maintenance_plans dmp WHERE dmp.details_ma_id = :round_id");
        $stmtCheck->execute([':round_id' => $roundId]);
        $roundData = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if ($roundData) {
            $planId = $roundData['plan_id'];

            $baseSql = "
                SELECT mr.ma_result_id, mr.equipment_id, mr.result, mr.details, mr.reason, 
                       dmp.start_date, mr.performed_date, e.asset_code, e.name AS equipment_name, mp.plan_name
                FROM maintenance_result mr
                INNER JOIN details_maintenance_plans dmp ON mr.details_ma_id = dmp.details_ma_id
                INNER JOIN equipments e ON e.equipment_id = mr.equipment_id
                INNER JOIN maintenance_plans mp ON mp.plan_id = dmp.plan_id
                WHERE mr.details_ma_id = :round_id
            ";

            $countSql = "
                SELECT COUNT(*) 
                FROM maintenance_result mr
                INNER JOIN details_maintenance_plans dmp ON mr.details_ma_id = dmp.details_ma_id
                INNER JOIN equipments e ON e.equipment_id = mr.equipment_id
                INNER JOIN maintenance_plans mp ON mp.plan_id = dmp.plan_id
                WHERE mr.details_ma_id = :round_id
            ";

            $searchFields = ['e.name', 'e.asset_code', 'mp.plan_name'];
            $additionalParams = [':round_id' => $roundId];

            $response = handlePaginatedSearch(
                $dbh,
                $_GET,
                $baseSql,
                $countSql,
                $searchFields,
                "ORDER BY e.name ASC",
                "",
                $additionalParams
            );

            if ($response['status'] === 'success') {
                $result = [];
                foreach ($response['data'] as $row) {
                    $equipmentName = ($row['asset_code'] ?? "-") . " - " . ($row['equipment_name'] ?? $row['equipment_id']);
                    $result[] = [
                        'name' => $equipmentName,
                        'rounds' => [[
                            'round' => 1,
                            'details_ma_id' => $roundId,
                            'ma_result_id' => $row['ma_result_id'],
                            'plan_name' => $row['plan_name'] ?? "Plan $planId",
                            'status' => $row['result'],
                            'details' => $row['details'] ?: '-',
                            'reason' => $row['reason'] ?: '-',
                            'start_date' => $row['start_date'] ?: '-',
                            'performed_date' => $row['performed_date'] ?: '-'
                        ]]
                    ];
                }

                echo json_encode([
                    'status' => 'success',
                    'data' => $result,
                    'pagination' => $response['pagination']
                ]);
                exit;
            } else {
                echo json_encode($response);
                exit;
            }
        }
    }

    echo json_encode(['status' => 'success', 'data' => []]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
