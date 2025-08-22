<?php
include "../config/jwt.php";
$input = json_decode(file_get_contents('php://input'));

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array("status" => "error", "message" => "post method!!!"));
    die();
}
try {

    // รับค่าจาก input (JSON) ที่ส่งมา เช่น department และ user_id
    $div = $input->department ?? '';              // ค่า department ที่จะอัปเดต
    $user_id = $input->user_id ?? ''; // รหัสพนักงานที่ต้องการอัปเดต

    // ตรวจสอบว่าได้รับค่าทั้ง department และ user_id หรือไม่
    if (!$div || !$user_id) {
        throw new Exception("Missing department or user_id"); // ถ้าไม่ครบให้หยุดการทำงาน
    }

    // เตรียมคำสั่ง SQL เพื่ออัปเดต department ของผู้ใช้ตาม user_id
    $stmt = $dbh->prepare("UPDATE `users` SET `department` = ? WHERE `user_id` = ?");
    $stmt->bindParam(1, $div);              // ผูกค่าตัวแปร $div กับตำแหน่ง ?
    $stmt->bindParam(2, $user_id);    // ผูกค่าตัวแปร $user_id

    // ทำการ execute และตรวจสอบว่าการอัปเดตสำเร็จหรือไม่
    if (!$stmt->execute()) {
        throw new Exception("UPDATE department failed"); // ถ้าอัปเดตไม่สำเร็จให้แจ้งข้อผิดพลาด
    }

    // ดึงข้อมูลผู้ใช้ที่อัปเดตแล้ว พร้อมข้อมูลชื่อแผนกจากตาราง ward
    $query = "SELECT 
    users.*, 
    departments.department_name AS department_name
    FROM users
    LEFT JOIN departments 
    ON users.department = departments.department_id
    WHERE users.user_id = ?;
";
    $stmt = $dbh->prepare($query);
    $stmt->bindParam(1, $user_id);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $result = $stmt->fetch(PDO::FETCH_ASSOC); // ดึงข้อมูลที่เจอมาใช้งาน

        // เตรียมข้อมูลผู้ใช้เพื่อนำไปใส่ใน JWT
        $user = [
            "user_id" => $result['user_id'],
            "name" => $result['full_name'],           // ชื่อเต็มของผู้ใช้
            "department" => $result['department_id'],
            "role_id" => $result['role_id'],
            "first_login" => $result['first_login'],
            "last_login" => $result['last_login']
        ];

        // กำหนดเวลาสร้าง token และวันหมดอายุ
        $issued_at = time();                                   // เวลาปัจจุบัน
        $expiration_time = $issued_at + (60 * 24 * 60);        // accessToken มีอายุ 1 วัน
        $expiration_time2 = $issued_at + (60 * 7 * 24 * 60);   // refreshToken มีอายุ 1 สัปดาห์

        // สร้าง JWT token สำหรับ access และ refresh
        $token = [
            "iat" => $issued_at,
            "exp" => $expiration_time,
            "data" => $user
        ];
        $tokenRefreshToken = [
            "iat" => $issued_at,
            "exp" => $expiration_time2,
            "data" => $user
        ];

        // กำหนด secret key สำหรับเข้ารหัส JWT
        $secret_key1 = "AccessKey1";
        $secret_keyRefreshToken1 = "RefreshToken"; // คีย์สำหรับ refreshToken

        // เข้ารหัสข้อมูลเป็น JWT ด้วย HS256
        $jwt = JWT::encode($token, $secret_key1, 'HS256');
        $RefreshToken = JWT::encode($tokenRefreshToken, $secret_keyRefreshToken1, 'HS256');

        // บันทึกเวลาล็อกอินล่าสุด
        $stmt = $dbh->prepare("UPDATE users SET last_login = current_timestamp WHERE user_id = ?");
        $stmt->bindParam(1, $user_id);
        $stmt->execute();

        // ส่งข้อมูลกลับในรูปแบบ JSON รวมทั้ง token และข้อมูลผู้ใช้
        echo json_encode([
            "status" => "ok",
            "message" => "Logged in",
            "accessToken" => $jwt,
            "refreshToken" => $RefreshToken,
            "user" => $user,
            "expiration_time" => $expiration_time
        ]);
    } else {
        // ถ้าไม่พบผู้ใช้ ให้ส่ง error กลับ
        echo json_encode(["status" => "error", "message" => "User not found"]);
    }
} catch (Exception $e) {
    //    $dbh->rollBack();

    echo json_encode(array("status" => "error", "message" => $e->getMessage()));
}

// เปลี่ยนแผนก (department) ของผู้ใช้งานในระบบ ตามรหัสพนักงาน (user_id)

// ดึงข้อมูลผู้ใช้ที่อัปเดตแล้วกลับมา พร้อมกับข้อมูลแผนก

// สร้าง JWT access token และ refresh token เพื่อให้สามารถล็อกอินต่อได้โดยไม่ต้องเข้าสู่ระบบใหม่

// บันทึก last_login เพื่อเก็บ timestamp การเข้าสู่ระบบล่าสุด