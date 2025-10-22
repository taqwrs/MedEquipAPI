<?php
// ใส่อีเมลติดต่อของคุณ และคีย์ที่ได้จาก generate_keys.php
const VAPID_SUBJECT = 'mailto:thichanon2413@gmail.com';
const VAPID_PUBLIC  = 'BFbpUKSWv1hiLck_vWC7ONpxw1NIhBDbMOSsoP-KQyVhyEASitPmBeNbywffugsYJEyCnibFLieb_CTRaL1Ygx4';
const VAPID_PRIVATE = 'rXOAyai7b9UnpkvZAGHGl5mjO9jPAMI2HTyW-EFSxBc';

//OjY0Kn18t5luinvr46PcUuGeiHVVDdaVbBT-77o-A0I

// ที่เก็บ subscriptions แบบง่าย ๆ (ไฟล์ JSON)
// const SUBS_FILE = __DIR__ . '/subs.json';
// if (!defined('SUBS_FILE')) {
//     define('SUBS_FILE', __DIR__ . '/../data/subs.json'); // ../data จากโฟลเดอร์ config
// }