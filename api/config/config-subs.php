<?php
// ใส่อีเมลติดต่อของคุณ และคีย์ที่ได้จาก generate_keys.php
const VAPID_SUBJECT = 'mailto:thichanontcn33@gmail.com';
const VAPID_PUBLIC  = 'BAWUaKUGWbR_OVbKirmke2UC8QC5RsrobaEYlUTv6RnEAFvQXT8ZpvJSqJYZLVQ_3icB-QAmuD29RMgrmV7u6_A';
const VAPID_PRIVATE = '8QqkU-UFjl62MsKzWEzx1kFqtBoimvvEP05d2DK5E2E';

//OjY0Kn18t5luinvr46PcUuGeiHVVDdaVbBT-77o-A0I

// ที่เก็บ subscriptions แบบง่าย ๆ (ไฟล์ JSON)
// const SUBS_FILE = __DIR__ . '/subs.json';
// if (!defined('SUBS_FILE')) {
//     define('SUBS_FILE', __DIR__ . '/../data/subs.json'); // ../data จากโฟลเดอร์ config
// }