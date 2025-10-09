<?php
// include DB and then include the ajax endpoint with a set GET param
$_GET['division'] = 'HANUMAKONDA';
// capture output
ob_start();
include 'ajax_get_mandals_schools.php';
$out = ob_get_clean();
header('Content-Type: application/json');
echo $out;
