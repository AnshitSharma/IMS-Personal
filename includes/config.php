<?php

    // if (file_exists(__DIR__ . '/.config_env')) {
    // $lines = file(__DIR__ . '/.config_env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    // foreach ($lines as $line) {
    //     // Ignore comments
    //     if (strpos($line, '#') === 0) continue;

    //     // Parse key-value pairs
    //     list($key, $value) = explode('=', $line, 2);
    //     putenv(trim("$key=$value"));
    // }
    // } else {
    
    // exit('ENVIRONMENT NOT CONFIGURED');
    // }

// $timezone = getenv('TIMEZONE');
// date_default_timezone_set($timezone);

define('MAIN_SITE_URL', getenv('MAIN_SITE_URL'));


?>