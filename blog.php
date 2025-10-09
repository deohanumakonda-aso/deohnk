<?php
session_start();
require_once 'includes/db_connect.php';
include 'includes/header.php';
?>

<div class="container main-body">
    <?php include 'includes/left_sidebar.php'; ?>

    <main class="main-content">
        <h2>Official Blog</h2>

        <?php // Admin Form: Only show if admin is logged in
        if (isset($_SESSION['admin_loggedin']) && $_SESSION['admin_loggedin'] === true): ?>
            <div class="admin-form">
                <h3>Create New Post</h3>
                <form action="admin/manage_blog.php" method="post" enctype="multipart/form-data">
                    <input type="text" name="title" placeholder="Post Title" required>
                    <input type="text" name="genre" placeholder="Category">
                    <textarea name="content" placeholder="Post content..." required></textarea>
                    <input type="file" name="image">
                    <input type="file" name="attachment">
                    <button type="submit" name="add_post">Publish Post</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="blog-posts">
            <?php
                // Fetch and display blog posts
                $result = $conn->query("SELECT * FROM blog_posts ORDER BY date_created DESC");
                while($post = $result->fetch_assoc()) {
                    echo "<article>";
                    echo "<h3>" . htmlspecialchars($post['title']) . "</h3>";
                    echo "<p><em>" . htmlspecialchars($post['genre']) . " - " . date('F j, Y', strtotime($post['date_created'])) . "</em></p>";
                    if (!empty($post['image_path'])) {
                        echo "<img src='" . htmlspecialchars($post['image_path']) . "' alt='Post Image' style='max-width: 100%;'>";
                    }
                    echo "<div>" . nl2br(htmlspecialchars($post['content'])) . "</div>";
                    echo "</article><hr>";
                }
            ?>
        </div>
    </main>

    <?php include 'includes/right_sidebar.php'; ?>
</div>

<?php include 'includes/footer.php'; ?>