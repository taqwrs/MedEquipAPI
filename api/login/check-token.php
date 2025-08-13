<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header("Access-Control-Allow-Headers: Content-Type, Authorization");
require '../vendor/autoload.php';
include "../config/config.php";
date_default_timezone_set("Asia/Bangkok");

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
$secret_key = "secretKey";
$secret_keyRefreshToken = "RefreshToken";


$input = json_decode(file_get_contents('php://input'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array("status" => "error", "message" => "post method!!!"));
    die();
}
try {

    $token = $input->refreshToken;
    $decoded = JWT::decode($token, new Key($secret_keyRefreshToken, 'HS256'));
    $issued_at = time();

    $expiration_time =  $issued_at + (60 * 60);
//    $expiration_time =  $issued_at + (60 *1);
    $accessToken  = array(
        "iat" => $decoded->iat,
        "exp" => $expiration_time,
        "data" => $decoded->data
    );
    $jwt = JWT::encode($accessToken, $secret_key, 'HS256'); // เซ็นต์ Token
    $data = array(
        "status" => "ok",
        "message" => "RefreshToken",
        "accessToken" => $jwt,
    );
    echo json_encode($data);

} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Token expired or invalid']);
    exit;}

