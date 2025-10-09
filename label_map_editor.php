<?php
session_start();
require_once 'includes/db_connect.php';
include 'includes/header.php';

$configPath = __DIR__ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'label_map.json';
$message = '';
$error = '';

// restrict to admin users
if (!isset($_SESSION['admin_loggedin']) || $_SESSION['admin_loggedin'] !== true) {
    include 'includes/header.php';
    echo '<div class="container main-body">';
    echo '<main class="main-content"><h2>Access denied</h2><p>You must be logged in as admin to edit label map.</p></main>';
    include 'includes/right_sidebar.php';
    echo '</div>';
    include 'includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Save posted JSON mapping
    $json = isset($_POST['label_map_json']) ? trim($_POST['label_map_json']) : '';
    if ($json === '') {
        $error = 'No content provided.';
    } else {
        // validate JSON
        $decoded = json_decode($json, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            $error = 'Invalid JSON: ' . json_last_error_msg();
        } elseif (!is_array($decoded)) {
            $error = 'JSON must be an object mapping keys to labels.';
        } else {
            // attempt to write file
            if (!is_writable(dirname($configPath))) {
                $error = 'Config directory not writable: ' . dirname($configPath);
            } else {
                $ok = file_put_contents($configPath, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                if ($ok === false) {
                    $error = 'Failed to write file.';
                } else {
                    $message = 'Label map saved successfully.';
                }
            }
        }
    }
}

$current = '{}';
if (file_exists($configPath) && is_readable($configPath)) {
    $current = file_get_contents($configPath);
}

?>
<div class="container main-body">
    <?php include 'includes/left_sidebar.php'; ?>

    <main class="main-content">
        <h2>Label Map Editor</h2>
        <p>Edit the JSON mapping of DB column keys to friendly labels. Example: {"TreasuryCode":"Teacher Employee ID"}</p>

        <?php if ($message): ?>
            <div class="notice success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="notice error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="post">
            <label for="label_map_json" style="font-weight:600;">Label Map JSON</label><br>
            <textarea id="label_map_json" name="label_map_json" rows="20" style="width:100%; font-family:monospace;"><?php echo htmlspecialchars($current); ?></textarea>
            <div style="margin-top:8px; display:flex; gap:8px;">
                <button type="submit">Save Mapping</button>
                <a href="teacher_particulars.php" style="align-self:center;">Back to Particulars</a>
            </div>
        </form>
    </main>

    <?php include 'includes/right_sidebar.php'; ?>
</div>

<?php include 'includes/footer.php'; ?>
