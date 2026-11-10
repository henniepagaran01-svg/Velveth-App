<?php
session_start();
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: admin_dashboard.php");
    } else {
        header("Location: customer_dashboard.php");
    }
} else {
    header("Location: home.php");
}
exit;
