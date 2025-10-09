<?php
session_start();
// If already logged in, redirect to home
if (isset($_SESSION['admin_loggedin']) && $_SESSION['admin_loggedin'] === true) {
    header("location: ../index.php");
    exit;
}

require_once '../includes/db_connect.php';
// ... PHP logic to handle form submission ...
// Check username, fetch hash from db, use password_verify()
?>
<form action="" method="post">
    <label for="username">Username</label>
    <input type="text" name="username" required>
    <label for="password">Password</label>
    <input type="password" name="password" required>
    <button type="submit">Login</button>
</form>