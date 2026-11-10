<?php
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
session_start();
session_unset();
session_destroy();
header("Location: customer_login.php");
exit;
