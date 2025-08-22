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

    $username = $input->username;
    $password = $input->password;
    if (isset($username, $password)) {
        $query = "SELECT * FROM users WHERE user_id = ?";

        $stmt = $dbh->prepare($query);
        $stmt->bindParam(1, $username);
        $stmt->execute();
        if ($stmt->rowCount() === 1) {
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if(password_verify($password, $results[0]['password'])) {
                $users = array(
                    "user_id" => $results[0]['user_id'],
                    "name" => $results[0]['full_name'],
                    "div" => $results[0]['department_id'],
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
                $stmt = $dbh->prepare("UPDATE users SET last_login = current_timestamp WHERE user_id = ?");
                $stmt->bindParam(1, $username);

                if ($stmt->execute()) {
                    echo json_encode($data);
                } else{
                    echo json_encode(array("status" => "error"));
                }
            }else{
                echo json_encode(array("status" => "error", "message" => "Wrong password"));
            }

        } else {
            echo json_encode(array("status" => "error", "message" => "Wrong username or password!!!"));
        }
    } else {
        echo json_encode(array("status" => "error1", "mess" => "fail"));

    }


} catch (PDOException $e) {
    print "Error!: " . $e->getMessage() . "<br/>";
    die();
}