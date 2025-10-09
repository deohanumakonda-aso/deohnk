<?php
session_start();
require_once 'includes/db_connect.php';
include 'includes/header.php';
?>

<div class="container main-body">
    <?php include 'includes/left_sidebar.php'; ?>

    <main class="main-content">
        <h2>Announcements & Proceedings</h2>

        <?php // Admin Form: Only show if admin is logged in
        if (isset($_SESSION['admin_loggedin']) && $_SESSION['admin_loggedin'] === true): ?>
            <div class="admin-form-container">
                <h3>Post a New Announcement</h3>
                <form action="admin/manage_announcements.php" method="post">
                    <input type="text" name="title" placeholder="Announcement Title" required>
                    <textarea name="content" placeholder="Full announcement details..." required></textarea>
                    <input type="text" name="officer_name" placeholder="Officer Name" required>
                    <button type="submit" name="add_announcement">Publish Announcement</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="announcements-list">
            <?php
                // Fetch and display all announcements, newest first
                $result = $conn->query("SELECT * FROM announcements ORDER BY date_posted DESC");
                if ($result->num_rows > 0) {
                    while($ann = $result->fetch_assoc()) {
                        echo "<article class='announcement-item' id='ann-{$ann['id']}'>";
                        echo "<h3>" . htmlspecialchars($ann['title']) . "</h3>";
                        echo "<small>Posted on: " . date('F j, Y, g:i a', strtotime($ann['date_posted'])) . " by " . htmlspecialchars($ann['officer_name']) . "</small>";
                        echo "<p>" . nl2br(htmlspecialchars($ann['content'])) . "</p>";

                        // Show delete button only to admins
                        if (isset($_SESSION['admin_loggedin']) && $_SESSION['admin_loggedin'] === true) {
                            echo "<a href='admin/manage_announcements.php?delete={$ann['id']}' class='delete-link' onclick='return confirm(\"Are you sure you want to delete this announcement?\");'>Delete</a>";
                        }
                        echo "</article>";
                    }
                } else {
                    echo "<p>No announcements have been posted yet.</p>";
                }
            ?>
        </div>
    </main>

    <?php include 'includes/right_sidebar.php'; ?>
</div>

<?php include 'includes/footer.php'; ?>