<?php
session_start();
require_once '../includes/db_connect.php';

// Security Check: Only admins can access this page
if (!isset($_SESSION['admin_loggedin']) || $_SESSION['admin_loggedin'] !== true) {
    header("Location: ../index.php"); // Redirect non-admins
    exit;
}

// --- HANDLE ADDING A NEW ANNOUNCEMENT ---
if (isset($_POST['add_announcement'])) {
    $title = $_POST['title'];
    $content = $_POST['content'];
    $officer_name = $_POST['officer_name'];

    // Use Prepared Statements to prevent SQL injection
    $stmt = $conn->prepare("INSERT INTO announcements (title, content, officer_name) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $title, $content, $officer_name);

    if ($stmt->execute()) {
        // Success
        header("Location: ../announcements.php?status=add_success");
    } else {
        // Failure
        header("Location: ../announcements.php?status=add_error");
    }
    $stmt->close();
}

// --- HANDLE DELETING AN ANNOUNCEMENT ---
if (isset($_GET['delete'])) {
    $id_to_delete = $_GET['delete'];

    // Use Prepared Statements
    $stmt = $conn->prepare("DELETE FROM announcements WHERE id = ?");
    $stmt->bind_param("i", $id_to_delete);

     if ($stmt->execute()) {
        // Success
        header("Location: ../announcements.php?status=delete_success");
    } else {
        // Failure
        header("Location: ../announcements.php?status=delete_error");
    }
    $stmt->close();
}

$conn->close();
?>