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
   $conn = odbc_connect('AS400', 'Z65118', 'l6irb');

    $username = $input->username;
//    $password = $input->password;
    if (isset($username)) {
        $query = "SELECT * FROM users WHERE employee_code = ?";

        $stmt = $dbh->prepare($query);
        $stmt->bindParam(1, $username);
        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
//            echo json_encode(["status" => "success", "data" => $results]);
            $users = array(
                "employee_code" => $results[0]['employee_code'],
                "name" => $results[0]['full_name'],
                "div" => $results[0]['department'],
                "role_id" => $results[0]['role_id'],
            );
            $issued_at = time();
            $expiration_time = $issued_at + (60 * 24 * 60);
//            $expiration_time =  $issued_at + (60 *1);
            $expiration_time2 = $issued_at + (60 * 7 * 24 * 60);
//            $expiration_time2 =  $issued_at + (60*2 );
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
            $jwt = JWT::encode($token, $secret_key, 'HS256'); // เซ็นต์ Token
            $RefreshToken = JWT::encode($tokenRefreshToken, $secret_keyRefreshToken, 'HS256'); // เซ็นต์ Token

            $data = array(
                "status" => "ok",
                "message" => "Logged in",
                "accessToken" => $jwt,
                "refreshToken" => $RefreshToken,
                "user" => $users,
                "expiration_time" => $expiration_time,
            );
            $stmt = $dbh->prepare("UPDATE users SET last_login = current_timestamp WHERE employee_code = ?");
            $stmt->bindParam(1, $username);

            if ($stmt->execute()) {
                echo json_encode($data);

            }
        } else {
//            $clientId = $input->client_id;

            if (isset($input->clientId)&&$input->clientId === "324139003039252483@pro") {//อาจจะเปลี่ยนเป็นอะไรที่ปลอดภัยกว่ากันยิงมาจากที่อื่นโดยไม่ผ่าน zitadel
                $lastLogin = date('Y-m-d H:i:s');
                $sql = "select * from stfmasv5pf where SMSSTFNO = '" . $username . "' and smsoutyy = '0'";
                $res = odbc_exec($conn, $sql);
                while ($row = odbc_fetch_array($res)) {
                    $id = trim($row['SMSSTFNO']);
                    $name = trim($row['SMSNAME']) . ' ' . trim($row['SMSSURNAM']);
                    $div = trim($row['SMSDIVCOD']);

                    switch ($div) {
                        case 'W1B':
                        case 'W1C':
                            $mappedDepartment = 'W1BC';
                            break;
                        case 'ICU':
                        case 'CCU':
                            $mappedDepartment = 'ICU+CCU';
                            break;
                        case 'OPD':
                        case 'MED':
                            $mappedDepartment = 'OPD MED';
                            break;
                        case 'ER':
                            $mappedDepartment = 'AER';
                            break;
                        case 'W2C':
                        case 'W3B':
                            $mappedDepartment = 'W2C+W3B';
                            break;
                        case 'SUR':
                            $mappedDepartment = 'SOD';
                            break;
                        case 'OPR': 
                            $mappedDepartment = 'OR';
                            break;
                        case 'GYN':
                            $mappedDepartment = 'OOD';
                            break;
                        default:
                            $mappedDepartment = $div; // ถ้าไม่ตรงกับเคสใด ให้ใช้ค่า div เดิม
                            break;
                    }
                }
                $stmt = $dbh->prepare("INSERT INTO users (employee_code ,full_name,department,last_login) values (?,?,?,?)");
                $stmt->bindParam(1, $id);
                $stmt->bindParam(2, $name);
                $stmt->bindParam(3, $mappedDepartment);
                // $stmt->bindParam(3, $div);
                $stmt->bindParam(4, $lastLogin);


                if ($stmt->execute()) {
                    $users = array(
                        "employee_code" => $id,
                        "name" => $name,
                        // "div" => $div,
                        "div" => $mappedDepartment,
                        "role_id" => 3,
                    );
                    $issued_at = time();
                    $expiration_time = $issued_at + (60 * 24 * 60);
//            $expiration_time =  $issued_at + (60 *1);
                    $expiration_time2 = $issued_at + (60 * 7 * 24 * 60);
//            $expiration_time2 =  $issued_at + (60*2 );
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
                    $jwt = JWT::encode($token, $secret_key, 'HS256'); // เซ็นต์ Token
                    $RefreshToken = JWT::encode($tokenRefreshToken, $secret_keyRefreshToken, 'HS256'); // เซ็นต์ Token

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
            }else{
                echo json_encode(array("status" => "error", "mess" => "don't have TSH ID"));
            }

        }


//        echo json_encode($data);


    } else {
        echo json_encode(array("status" => "error1", "mess" => "fail"));

    }


} catch (PDOException $e) {
    print "Error!: " . $e->getMessage() . "<br/>";
    die();
}

