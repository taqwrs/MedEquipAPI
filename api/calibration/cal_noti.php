<?php
require '../../vendor/autoload.php';

$dbh = new PDO('mysql:host=192.168.2.41;dbname=intern_medequipment', 'intern', 'intern@Tsh');
const VAPID_SUBJECT = 'mailto:surapits@thaksinhospital.com';
const VAPID_PUBLIC  = 'BLU8U0B0dbsPUjYzjn3wIvyhhxWvWKh2hVCSWhisJacbsMGXv80mMKsBf9XGzSkpENVohwSX06vvd5J1JYLx1cc';
const VAPID_PRIVATE = 'ITjZ7YXOJYG93JJho-l3z5aTUawifIx9JKeCIsHJB0U';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "POST method required"]);
    exit;
}

function getGroupSubscriptions(PDO $dbh, int $group_user_id): array
{
    $sql = "SELECT DISTINCT ps.endpoint, ps.p256dh, ps.auth, u.ID as user_id, u.full_name
            FROM push_subscriptions ps
            INNER JOIN relation_user ru ON ps.u_id = ru.u_id
            INNER JOIN users u ON ru.u_id = u.ID
            WHERE ru.group_user_id = :group_user_id 
            AND ps.is_active = 1";
    
    $stmt = $dbh->prepare($sql);
    $stmt->execute([':group_user_id' => $group_user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function sendPushToTargets(array $targets, array $payload): array
{
    $auth = [
        'VAPID' => [
            'subject' => VAPID_SUBJECT,
            'publicKey' => VAPID_PUBLIC,
            'privateKey' => VAPID_PRIVATE,
        ]
    ];
    
    $webPush = new WebPush($auth);
    $webPush->setDefaultOptions(['TTL' => 300]); 

    foreach ($targets as $t) {
        $sub = [
            'endpoint' => $t['endpoint'],
            'keys' => [
                'p256dh' => $t['p256dh'],
                'auth' => $t['auth'],
            ],
        ];
        $webPush->queueNotification(
            Subscription::create($sub),
            json_encode($payload, JSON_UNESCAPED_UNICODE)
        );
    }

    $results = [];
    foreach ($webPush->flush() as $report) {
        $endpoint = $report->getRequest()->getUri()->__toString();
        if ($report->isSuccess()) {
            $results[] = ['endpoint' => $endpoint, 'ok' => true];
        } else {
            $status = $report->getResponse() ? $report->getResponse()->getStatusCode() : null;
            $reason = $report->getReason();
            $results[] = [
                'endpoint' => $endpoint, 
                'ok' => false, 
                'status' => $status, 
                'reason' => $reason
            ];
        }
    }
    return $results;
}

function deactivateEndpoints(PDO $dbh, array $endpoints): void
{
    if (empty($endpoints)) return;

    $dbh->beginTransaction();
    try {
        $sql = "UPDATE push_subscriptions SET is_active = 0 WHERE endpoint = :endpoint";
        $stmt = $dbh->prepare($sql);
        
        foreach ($endpoints as $ep) {
            $stmt->execute([':endpoint' => $ep]);
        }
        
        $dbh->commit();
    } catch (Throwable $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        throw $e;
    }
}

try {
    $today = date('Y-m-d');
    
    $sql = "SELECT 
                dcp.details_cal_id,
                dcp.plan_id,
                dcp.start_date,
                cp.plan_name,
                cp.group_user_id,
                gu.group_name
            FROM details_calibration_plans dcp
            INNER JOIN calibration_plans cp ON dcp.plan_id = cp.plan_id
            INNER JOIN group_user gu ON cp.group_user_id = gu.group_user_id
            WHERE DATE(dcp.start_date) = :today
            AND cp.is_active = 1";
    
    $stmt = $dbh->prepare($sql);
    $stmt->execute([':today' => $today]);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($schedules)) {
        echo json_encode([
            "success" => true,
            "message" => "ไม่มีแผนสอบเทียบที่ถึงกำหนดวันนี้",
            "date" => date('d/m/Y'),
            "notifyResults" => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $allResults = [];

    foreach ($schedules as $schedule) {
        $group_user_id = $schedule['group_user_id'];
        $plan_name = $schedule['plan_name'];
        $start_date = date('d/m/Y', strtotime($schedule['start_date']));
        $plan_id = $schedule['plan_id'];
        $group_name = $schedule['group_name'];

        $targets = getGroupSubscriptions($dbh, $group_user_id);

        if (empty($targets)) {
            continue;
        }

        $payload = [
            'title' => "แจ้งเตือน: แผนการสอบเทียบวันนี้!",
            'body' => "แผน: {$plan_name}\nวันที่: {$start_date}",
            'url' => "http://localhost:5173/calibration",
        ];

        $results = sendPushToTargets($targets, $payload);

        $toDeactivate = [];
        foreach ($results as $r) {
            if (!$r['ok'] && in_array($r['status'] ?? null, [404, 410], true)) {
                $toDeactivate[] = $r['endpoint'];
            }
        }

        if (!empty($toDeactivate)) {
            deactivateEndpoints($dbh, $toDeactivate);
        }

        $allResults[] = [
            "plan_id" => $plan_id,
            "plan_name" => $plan_name,
            "group_name" => $group_name,
            "start_date" => $start_date
        ];
    }

    echo json_encode([
        "success" => true,
        "message" => "ส่งการแจ้งเตือนเรียบร้อย",
        "date" => date('d/m/Y'),
        "notifyResults" => $allResults
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode([
        "success" => false,
        "message" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}