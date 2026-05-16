<?php
// Migration script - adds missing columns to teacherdata table
// Run once from browser, then DELETE this file for security
require_once 'config/init.php';
require_once 'includes/db_connect.php';

// Simple auth check
if (!isset($_GET['run']) || $_GET['run'] !== 'yes') {
    echo '<h2>DB Migration</h2>';
    echo '<p>This adds missing columns to the teacherdata table.</p>';
    echo '<a href="?run=yes" onclick="return confirm(\'Run migration now?\')">Click to Run Migration</a>';
    exit;
}

$columns = [
    'ded_trainings_acquired'  => "INT DEFAULT 0 COMMENT 'Number of DEd/TTC trainings'",
    'degrees_acquired'        => "INT DEFAULT 0 COMMENT 'Number of UG degrees'",
    'pg_degrees_acquired'     => "INT DEFAULT 0 COMMENT 'Number of PG degrees'",
    'pt_trainings_acquired'   => "INT DEFAULT 0 COMMENT 'Number of BEd/PT trainings'",
    'pt_pg_trainings_acquired'=> "INT DEFAULT 0 COMMENT 'Number of MEd/PG trainings'",
];

// Get existing columns
$existing = [];
$res = $conn->query('SHOW COLUMNS FROM teacherdata');
while ($row = $res->fetch_assoc()) $existing[] = strtolower($row['Field']);

echo '<h2>DB Migration Results</h2><ul>';
foreach ($columns as $col => $def) {
    if (in_array(strtolower($col), $existing)) {
        echo "<li>✅ <b>$col</b> — already exists, skipped.</li>";
    } else {
        $sql = "ALTER TABLE teacherdata ADD COLUMN `$col` $def";
        if ($conn->query($sql)) {
            echo "<li>✅ <b>$col</b> — <b>ADDED successfully.</b></li>";
        } else {
            echo "<li>❌ <b>$col</b> — Error: " . htmlspecialchars($conn->error) . "</li>";
        }
    }
}
echo '</ul>';
echo '<p><strong>Done. Please DELETE this file from your server now.</strong></p>';
?>
