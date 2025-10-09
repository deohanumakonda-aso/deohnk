<?php
session_start();
require_once '../includes/db_connect.php';

// Security Check
if (!isset($_SESSION['admin_loggedin']) || $_SESSION['admin_loggedin'] !== true) {
    header("Location: ../index.php");
    exit;
}

// --- HANDLE ADDING A NEW TEACHER ---
if (isset($_POST['add_teacher'])) {
    $name = $_POST['name'];
    $subject = $_POST['subject'];
    $school_id = $_POST['school_id'];
    $phone = $_POST['phone'];
    $email = $_POST['email'];

    $stmt = $conn->prepare("INSERT INTO teachers (name, subject, school_id, phone, email) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("ssiss", $name, $subject, $school_id, $phone, $email);

    if ($stmt->execute()) {
    header("Location: ../teachersearch.php?status=add_success");
    } else {
    header("Location: ../teachersearch.php?status=add_error");
    }
    $stmt->close();
}

// --- HANDLE DELETING A TEACHER ---
if (isset($_GET['delete'])) {
    $id_to_delete = $_GET['delete'];

    $stmt = $conn->prepare("DELETE FROM teachers WHERE id = ?");
    $stmt->bind_param("i", $id_to_delete);

     if ($stmt->execute()) {
    header("Location: ../teachersearch.php?status=delete_success");
    } else {
    header("Location: ../teachersearch.php?status=delete_error");
    }
    $stmt->close();
}

$conn->close();
?>