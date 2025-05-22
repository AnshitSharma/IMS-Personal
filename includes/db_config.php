<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

$dbHost = "localhost";
$dbUser = "shubhams_api";
$dbPass = "5C8R.wRErC_(";
$dbName = "shubhams_bdc_ims";

$mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);


if ($mysqli->connect_errno) {
      http_response_code(500); // Set HTTP status code to 500 (Internal Server Error)
      echo json_encode([
            'status' => 'error',
            'message' => 'Database connection failed',
            'error' => $mysqli->connect_error
      ]);
}

?>