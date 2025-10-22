<?php
declare(strict_types=1);

include "../config/jwt.php"; // มี $dbh และ $user_id
header('Content-Type: application/json; charset=utf-8');

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

// 1) ตรวจ method
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit;
}

// 2) อ่าน body (รองรับ JSON)
$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '{}');
if (json_last_error() !== JSON_ERROR_NONE) {
    $body = (object)[];
}

// 3) ดึงข้อมูล user จาก JWT
$stmtUser = $dbh->prepare("SELECT ID FROM users WHERE user_id = :user_id LIMIT 1");
$stmtUser->bindParam(":user_id", $user_id);
$stmtUser->execute();
$userData = $stmtUser->fetch(PDO::FETCH_ASSOC);
$u_id = $userData['ID'] ?? null;

if (!$u_id) {
    echo json_encode(["status" => "error", "message" => "ไม่พบข้อมูลผู้ใช้"]);
    exit;
}

// 4) POST = สมัคร / อัปเดต Subscription
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subscription = $body->subscription ?? null;
    $endpoint = trim($subscription->endpoint ?? '');
    $p256dh   = trim($subscription->keys->p256dh ?? '');
    $auth     = trim($subscription->keys->auth ?? '');
    // $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        echo json_encode([
            "status"  => "error",
            "message" => "Missing fields: endpoint, keys.p256dh, keys.auth are required"
        ]);
        exit;
    }

    try {
        $dbh->beginTransaction();

        // ใช้ endpoint_hash เพื่อกัน duplicate
        $sql = "
            INSERT INTO push_subscriptions (u_id, endpoint, endpoint_hash, p256dh, auth, is_active, created_at)
            VALUES (:u_id, :endpoint, SHA2(:endpoint, 256), :p256dh, :auth, 1, NOW())
            ON DUPLICATE KEY UPDATE
                p256dh = VALUES(p256dh),
                auth = VALUES(auth),
             
                is_active = 1,
                updated_at = CURRENT_TIMESTAMP
        ";
        $stmt = $dbh->prepare($sql);
        $stmt->execute([
            ':u_id'     => $u_id,
            ':endpoint' => $endpoint,
            ':p256dh'   => $p256dh,
            ':auth'     => $auth,
            // ':ua'       => $userAgent
        ]);

        $dbh->commit();
        echo json_encode(["ok" => true]);
    } catch (Exception $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}

// 5) DELETE = ยกเลิก Subscription
elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $endpoint = trim($body->endpoint ?? '');
    if ($endpoint === '') {
        echo json_encode(["status" => "error", "message" => "Endpoint ไม่ถูกส่งมา"]);
        exit;
    }

    try {
        $stmt = $dbh->prepare("
            UPDATE push_subscriptions
            SET is_active = 0, updated_at = CURRENT_TIMESTAMP
            WHERE u_id = :u_id AND endpoint_hash = SHA2(:endpoint, 256)
        ");
        $stmt->execute([
            ':u_id' => $u_id,
            ':endpoint' => $endpoint
        ]);

        echo json_encode(["status" => "success", "message" => "ยกเลิก subscription สำเร็จ"]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
}
