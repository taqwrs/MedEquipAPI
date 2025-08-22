<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header("Access-Control-Allow-Headers: Content-Type, Authorization");
require '../vendor/autoload.php';
include "config.php";
date_default_timezone_set("Asia/Bangkok");

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$secretKey = 'secretKey';

// Function to validate token expiration
function checkExp($token) {
    global $secretKey;
    try {
        // Decode the token and check if expired
        $decoded = JWT::decode($token, new Key($secretKey, 'HS256'));
        if (time() > $decoded->exp) {
            return false; // Token expired
        }
        return $decoded;
    } catch (Exception $e) {
        // Token is invalid
        return false;
    }
}

// Handle preflight requests for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Ensure this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST'&& $_SERVER['REQUEST_METHOD'] !== 'GET') {
    echo json_encode(array("status" => "error", "message" => "post method required555"));
    die();
}

// Retrieve the token from the Authorization header
$headers = apache_request_headers();
$token = isset($headers['Authorization']) ? $headers['Authorization'] : (isset($headers['authorization']) ? $headers['authorization'] : null);
// echo $token;
if (!$token) {
    http_response_code(401);
    echo json_encode(['error' => 'Token not provided ได้ไง']);
    exit;
}

// Remove the "Bearer " prefix from the token
$token = str_replace('Bearer ', '', $token);

// Validate the token
$decoded = checkExp($token);

if (!$decoded) {
    http_response_code(401);
    echo json_encode(['error' => 'Token expired or invalid']);
    exit;
}

// Extract user information if token is valid
$user_id = $decoded->data->user_id;
$name = $decoded->data->name;
$role_id = $decoded->data->role_id;
$div = $decoded->data->div;

// Respond with success and user data

