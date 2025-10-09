<?php
// ajax_print_token.php - removed
http_response_code(410);
header('Content-Type: application/json');
echo json_encode(['ok' => false, 'error' => 'This endpoint has been removed.']);
exit;

?>
<?php
// ajax_print_token.php - disabled
// PDF export/token endpoints have been removed per project request.
http_response_code(410);
header('Content-Type: application/json');
echo json_encode(['ok' => false, 'error' => 'This endpoint has been removed.']);
exit;

?>
