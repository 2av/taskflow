<?php
/**
 * CKEditor Image Upload Endpoint
 * Handles image uploads from CKEditor
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/upload.php';

// Set JSON response header
header('Content-Type: application/json');

// Check if file was uploaded
if (!isset($_FILES['upload']) || $_FILES['upload']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode([
        'error' => [
            'message' => 'File upload failed'
        ]
    ]);
    exit;
}

// Upload image using existing upload function
$result = uploadImageLocal($_FILES['upload'], 'profile', 2048000); // 2MB max for CKEditor images

if ($result['success']) {
    // Return CKEditor-compatible response
    echo json_encode([
        'url' => $result['url']
    ]);
} else {
    http_response_code(400);
    echo json_encode([
        'error' => [
            'message' => $result['error'] ?? 'Upload failed'
        ]
    ]);
}
?>
