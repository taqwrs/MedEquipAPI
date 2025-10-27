<?php
// ใส่อีเมลติดต่อของคุณ และคีย์ที่ได้จาก generate_keys.php
const VAPID_SUBJECT = 'mailto:surapits@thaksinhospital.com';
const VAPID_PUBLIC  = 'BLU8U0B0dbsPUjYzjn3wIvyhhxWvWKh2hVCSWhisJacbsMGXv80mMKsBf9XGzSkpENVohwSX06vvd5J1JYLx1cc';
const VAPID_PRIVATE = 'ITjZ7YXOJYG93JJho-l3z5aTUawifIx9JKeCIsHJB0U';

//OjY0Kn18t5luinvr46PcUuGeiHVVDdaVbBT-77o-A0I
// PUBLIC_KEY=
// PRIVATE_KEY=HlThZNBEDPk-ooC_JMoWl_0qeaC0L2_l7f16DcHmHDY
// ที่เก็บ subscriptions แบบง่าย ๆ (ไฟล์ JSON)
// const SUBS_FILE = __DIR__ . '/subs.json';
// if (!defined('SUBS_FILE')) {
//     define('SUBS_FILE', __DIR__ . '/../data/subs.json'); // ../data จากโฟลเดอร์ config
// }