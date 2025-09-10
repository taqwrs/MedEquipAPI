<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header("Access-Control-Allow-Headers: * ");
//error_reporting(E_ERROR | E_PARSE);
date_default_timezone_set("Asia/Bangkok");
include "../config/config.php";
require '../vendor/autoload.php'; // ต้องระบุ path ที่ติดตั้งไลบรารี

use \Firebase\JWT\JWT;

$secret_key = "secretKey";
$secret_keyRefreshToken = "RefreshToken";

$input = json_decode(file_get_contents('php://input'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array("status" => "error", "message" => "post method!!!"));
    die();
}
try {
//    $conn = odbc_connect('AS400', 'Z65118', 'l6irb');
    $username = $input->username;

    if (isset($username)) {
        // Query ดึงข้อมูล user พร้อม join ชื่อแผนก
        $query = "
            SELECT u.ID, u.user_id, u.full_name, u.department_id, u.role_id, u.first_login, u.last_login, d.department_name
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.department_id
            WHERE u.user_id = ?
        ";

        $stmt = $dbh->prepare($query);
        $stmt->bindParam(1, $username);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $users = array(
                "ID" => $results[0]['ID'],  
                "user_id" => $results[0]['user_id'], // ใช้ user_id แทน 
                "name" => $results[0]['full_name'],
                "div" => $results[0]['department_name'],  // ใช้ชื่อแผนกจาก join
                "role_id" => $results[0]['role_id'],
            );

            $issued_at = time();
            $expiration_time = $issued_at + (60 * 24 * 60);
            $expiration_time2 = $issued_at + (60 * 7 * 24 * 60);

            $token = array(
                "iat" => $issued_at,
                "exp" => $expiration_time,
                "data" => $users
            );

            $tokenRefreshToken = array(
                "iat" => $issued_at,
                "exp" => $expiration_time2,
                "data" => $users
            );

            $jwt = JWT::encode($token, $secret_key, 'HS256'); 
            $RefreshToken = JWT::encode($tokenRefreshToken, $secret_keyRefreshToken, 'HS256');

            $data = array(
                "status" => "ok",
                "message" => "Logged in",
                "accessToken" => $jwt,
                "refreshToken" => $RefreshToken,
                "user" => $users,
                "expiration_time" => $expiration_time,
            );

            // อัปเดต last_login ใช้ user_id แทน user_id
            $stmt = $dbh->prepare("UPDATE users SET last_login = current_timestamp WHERE user_id = ?");
            $stmt->bindParam(1, $username);
            if ($stmt->execute()) {
                echo json_encode($data);
            }

        } else {
            // ถ้าไม่เจอ user ให้เช็ค clientId
            if (isset($input->clientId) && $input->clientId === "334316074227007490@medicalequipment") {
                $lastLogin = date('Y-m-d H:i:s');
                $sql = "SELECT * FROM stfmasv5pf WHERE SMSSTFNO = '" . $username . "' AND smsoutyy = '0'";
                $res = odbc_exec($conn, $sql);
                while ($row = odbc_fetch_array($res)) {
                    $id = trim($row['SMSSTFNO']);
                    $name = trim($row['SMSNAME']) . ' ' . trim($row['SMSSURNAM']);
                    $div = trim($row['SMSDIVCOD']);

                    // mapping แผนก
                    switch ($div) {
                        case 'W1B': case 'W1C': $mappedDepartment = 'W1BC'; break;
                        case 'ICU': case 'CCU': $mappedDepartment = 'ICU+CCU'; break;
                        case 'OPD': case 'MED': $mappedDepartment = 'OPD MED'; break;
                        case 'ER': $mappedDepartment = 'AER'; break;
                        case 'W2C': case 'W3B': $mappedDepartment = 'W2C+W3B'; break;
                        case 'SUR': $mappedDepartment = 'SOD'; break;
                        case 'OPR': $mappedDepartment = 'OR'; break;
                        case 'GYN': $mappedDepartment = 'OOD'; break;
                        default: $mappedDepartment = $div; break;
                    }
                }

                // insert user ใหม่
                $stmt = $dbh->prepare("INSERT INTO users (user_id, full_name, department_id, last_login, role_id) VALUES (?, ?, (SELECT department_id FROM departments WHERE department_name = ?), ?, 3)");
                $stmt->bindParam(1, $id);
                $stmt->bindParam(2, $name);
                $stmt->bindParam(3, $mappedDepartment);
                $stmt->bindParam(4, $lastLogin);

                if ($stmt->execute()) {
                    $users = array(
                        "user_id" => $id,
                        "name" => $name,
                        "div" => $mappedDepartment,
                        "role_id" => 3,
                    );

                    $issued_at = time();
                    $expiration_time = $issued_at + (60 * 24 * 60);
                    $expiration_time2 = $issued_at + (60 * 7 * 24 * 60);

                    $token = array("iat" => $issued_at, "exp" => $expiration_time, "data" => $users);
                    $tokenRefreshToken = array("iat" => $issued_at, "exp" => $expiration_time2, "data" => $users);

                    $jwt = JWT::encode($token, $secret_key, 'HS256');
                    $RefreshToken = JWT::encode($tokenRefreshToken, $secret_keyRefreshToken, 'HS256');

                    $data = array(
                        "status" => "ok",
                        "message" => "Logged in",
                        "accessToken" => $jwt,
                        "refreshToken" => $RefreshToken,
                        "user" => $users,
                        "expiration_time" => $expiration_time,
                    );

                    echo json_encode($data);

                } else {
                    echo json_encode(array("status" => "error", "mess" => "fail"));
                }
            } else {
                echo json_encode(array("status" => "error", "mess" => "don't have TSH ID"));
            }
        }
    } else {
        echo json_encode(array("status" => "error1", "mess" => "fail"));
    }

} catch (PDOException $e) {
    print "Error!: " . $e->getMessage() . "<br/>";
    die();
}

