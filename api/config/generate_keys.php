<?php
//generate_keys.php
require '../../vendor/autoload.php';

use Minishlink\WebPush\VAPID;

$keys = VAPID::createVapidKeys();
echo "PUBLIC_KEY={$keys['publicKey']}\n";
echo "PRIVATE_KEY={$keys['privateKey']}\n";
