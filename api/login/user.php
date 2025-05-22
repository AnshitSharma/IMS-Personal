<?php
require_once(__DIR__ . '/../../includes/db_config.php');
require_once(__DIR__ . '/../../includes/QueryModel.php');
require_once(__DIR__ . '/../../includes/BaseFunctions.php');

session_start();


if (!isUserLoggedIn($mysqli)) {
  session_unset();
  session_destroy();
  header("Location: /bdc_ims/api/login/login.php");
  exit();
}

?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>
<body>
    <h1>welcome user you are logged in</h1>
    <form action="/bdc_ims/api/login/logout.php" method="GET">
        <button type="submit">logout</button>
    </form>
    <?php var_dump( $_SESSION, session_id());
        
    ?>

</body>
</html>