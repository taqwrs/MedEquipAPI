<?php
include "../config/jwt.php";
$input = json_decode(file_get_contents('php://input'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array("status" => "error", "message" => "post method!!!"));
    die();
}
try {

    $username = $user_id;
    $password = $input->password;
    if (isset($username,$password)) {
        $password = password_hash($password, PASSWORD_BCRYPT); // เข้ารหัสรหัสผ่าน
        $stmt = $dbh->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $stmt->bindParam(1, $password);
        $stmt->bindParam(2, $username);
        if ($stmt->execute()) {
            echo json_encode(array("status" => "success","message"=>"Password reset successfully"));
        }
    }else {
        echo json_encode(["status" => "error", "message" => "Invalid input"]);
    }


} catch (Exception $e) {
    echo json_encode(array("status" => "error", "message" => $e->getMessage()));
}
