<?php
// ใส่อีเมลติดต่อของคุณ และคีย์ที่ได้จาก generate_keys.php
const VAPID_SUBJECT = 'mailto:thichanontcn33@gmail.com';
const VAPID_PUBLIC  = 'BF11L_MnxDFAed5HPF8kvXRg3F2RLDLYqh7VdLlDngT1wbJILbNY55bTte2v2nLYOwcUORuEYM-j4Fv_dxB9vYU';
const VAPID_PRIVATE = 'Amf7DK2D5TXvP8NPnHmejWHKNR4jU0A2frMjxDGkMm0';

//OjY0Kn18t5luinvr46PcUuGeiHVVDdaVbBT-77o-A0I

// ที่เก็บ subscriptions แบบง่าย ๆ (ไฟล์ JSON)
const SUBS_FILE = __DIR__ . '/subs.json';
if (!defined('SUBS_FILE')) {
    define('SUBS_FILE', __DIR__ . '/../data/subs.json'); // ../data จากโฟลเดอร์ config
}