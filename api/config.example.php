<?php
// Database credentials — copy this file to config.php and fill in real values
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

// JWT secret — use a long random string (e.g. bin2hex(random_bytes(32)))
define('JWT_SECRET', 'CHANGE_ME_TO_A_RANDOM_SECRET');

// JWT token lifetime in seconds (24 hours)
define('JWT_LIFETIME', 86400);

// Anthropic API key — used by the CERT review tool (api/ai/review.php)
define('ANTHROPIC_API_KEY', '');
