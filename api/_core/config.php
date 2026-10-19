<?php
// ===============================
// Maan Win Backend Config
// cPanel MySQL details yaha set hain.
// AUTO_INSTALL_TABLES=true hone par pehli API request par tables automatic create/seed ho jayengi.
// ===============================

define('DB_HOST', 'localhost');
define('DB_NAME', 'rajni1122');
define('DB_USER', 'rajni1122');
define('DB_PASS', 'rajni1122');

define('AUTO_INSTALL_TABLES', true);
define('AUTO_INSTALL_VERSION', '2026_07_22_v34_support_team_wheel');

define('APP_TENANT_ID', 6016);
define('APP_CURRENCY', 'INR');
define('APP_CURRENCY_SIGN', '₹');
define('SUPPORT_TELEGRAM_URL', 'https://t.me/www9kclubbot');
define('APP_SECRET', getenv('DW_APP_SECRET') ?: 'b887fd8e108ea8bdd2587747c6cc1eec91806544edb76abe99d9ec703d8a23ad');
define('APP_TIMEZONE', 'Asia/Kolkata');

// true = project/demo virtual wallet only. Real-money payment gateway/live settlement connected nahi hai.
define('DEMO_MODE', true);

// Wingo period offset: 1 = abhi jo chal raha hai usse 1 period piche karke chalna hai
define('WINGO_PERIOD_OFFSET', 1);

@date_default_timezone_set(APP_TIMEZONE);
