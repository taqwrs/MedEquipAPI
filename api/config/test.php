<?php
//generate_keys.php
require '../../vendor/autoload.php';
$dbh = new PDO('mysql:host=192.168.2.41;dbname=intern_medequipment', 'intern', 'intern@Tsh');

const VAPID_SUBJECT = 'mailto:thichanontcn33@gmail.com';
const VAPID_PUBLIC = 'BAWUaKUGWbR_OVbKirmke2UC8QC5RsrobaEYlUTv6RnEAFvQXT8ZpvJSqJYZLVQ_3icB-QAmuD29RMgrmV7u6_A';
const VAPID_PRIVATE = '8QqkU-UFjl62MsKzWEzx1kFqtBoimvvEP05d2DK5E2E';


use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;
$recipient_id = 3;
$sql = "SELECT endpoint, p256dh, auth
          FROM push_subscriptions
          WHERE is_active = 1 AND u_id = ?";
$st = $dbh->prepare($sql);
$st->execute([$recipient_id]);
$targets = $st->fetchAll(PDO::FETCH_ASSOC);
var_dump($targets);
$payload = [
    'title' => "คุณได้รับเครื่องมือโอนย้าย",
    'body' => "เครื่องมือ: ",
];

$auth = [
    'VAPID' => [
        'subject' => VAPID_SUBJECT,
        'publicKey' => VAPID_PUBLIC,
        'privateKey' => VAPID_PRIVATE,
    ]
];
if (!defined('OPENSSL_EC_NAMED_CURVE')) {
    define('OPENSSL_EC_NAMED_CURVE', 1);
}
$webPush = new WebPush($auth);
$webPush->setDefaultOptions(['TTL' => 60]); // อยู่ในคิว 60s

// queue ทุกตัว
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

// flush และเก็บผลลัพธ์
$results = [];
foreach ($webPush->flush() as $report) {
    if (!is_null($report->getResponse())) {
        echo "Response: " . $report->getResponse()->getStatusCode() . "\n";
    } else {
        echo "No response for: " . $report->getRequest()->getUri()->__toString() . "\n";
    }
}
