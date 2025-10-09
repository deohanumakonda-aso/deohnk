<?php
session_start();
require_once '../includes/db_connect.php';

// Check if admin is logged in
if (!isset($_SESSION['admin_loggedin']) || $_SESSION['admin_loggedin'] !== true) {
    header("Location: /admin/index.php"); // Redirect to login
    exit;
}

if (isset($_POST['add_post'])) {
    $title = $_POST['title'];
    $genre = $_POST['genre'];
    $content = $_POST['content'];

    // Handle file uploads securely (this is a simplified example)
    $image_path = ''; // Logic to upload file and get path
    $attachment_path = ''; // Logic to upload file and get path

    // Use Prepared Statements
    $stmt = $conn->prepare("INSERT INTO blog_posts (title, genre, content, image_path, attachment_path) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("sssss", $title, $genre, $content, $image_path, $attachment_path);

    if ($stmt->execute()) {
        header("Location: ../blog.php?status=success");
    } else {
        header("Location: ../blog.php?status=error");
    }
    $stmt->close();
}
$conn->close();
?>