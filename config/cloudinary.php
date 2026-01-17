<?php
/**
 * Cloudinary Configuration
 * 
 * Get your credentials from: https://cloudinary.com/console
 * 
 * For production, set these as environment variables or update directly:
 * - CLOUDINARY_CLOUD_NAME
 * - CLOUDINARY_API_KEY
 * - CLOUDINARY_API_SECRET
 */

// Load Cloudinary SDK
require_once __DIR__ . '/../vendor/autoload.php';

use Cloudinary\Cloudinary;
use Cloudinary\Configuration\Configuration;

// Cloudinary Configuration
// TODO: Replace with your actual Cloudinary credentials
$cloudinary_cloud_name = getenv('CLOUDINARY_CLOUD_NAME') ?: 'your_cloud_name';
$cloudinary_api_key = getenv('CLOUDINARY_API_KEY') ?: 'your_api_key';
$cloudinary_api_secret = getenv('CLOUDINARY_API_SECRET') ?: 'your_api_secret';

// Configure Cloudinary (v3.x)
Configuration::instance([
    'cloud' => [
        'cloud_name' => $cloudinary_cloud_name,
        'api_key' => $cloudinary_api_key,
        'api_secret' => $cloudinary_api_secret,
    ],
    'url' => [
        'secure' => true
    ]
]);

// Initialize Cloudinary instance
$cloudinary = new Cloudinary([
    'cloud' => [
        'cloud_name' => $cloudinary_cloud_name,
        'api_key' => $cloudinary_api_key,
        'api_secret' => $cloudinary_api_secret,
    ],
    'url' => [
        'secure' => true
    ]
]);

/**
 * Upload file to Cloudinary
 * 
 * @param string $file_path Local file path
 * @param string $folder Cloudinary folder (optional)
 * @param array $options Additional upload options
 * @return array|false Upload result or false on failure
 */
function uploadToCloudinary($file_path, $folder = 'taskflow', $options = []) {
    global $cloudinary;
    
    try {
        $default_options = [
            'folder' => $folder,
            'resource_type' => 'auto', // auto-detect image, video, raw
            'overwrite' => true,
            'invalidate' => true
        ];
        
        $upload_options = array_merge($default_options, $options);
        
        $result = $cloudinary->uploadApi()->upload($file_path, $upload_options);
        
        return [
            'success' => true,
            'url' => $result['secure_url'],
            'public_id' => $result['public_id'],
            'format' => $result['format'] ?? null,
            'width' => $result['width'] ?? null,
            'height' => $result['height'] ?? null,
            'bytes' => $result['bytes'] ?? null
        ];
    } catch (Exception $e) {
        error_log('Cloudinary Upload Error: ' . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Delete file from Cloudinary
 * 
 * @param string $public_id Cloudinary public ID
 * @return array|false Delete result or false on failure
 */
function deleteFromCloudinary($public_id) {
    global $cloudinary;
    
    try {
        $result = $cloudinary->uploadApi()->destroy($public_id);
        
        return [
            'success' => $result['result'] === 'ok',
            'result' => $result['result']
        ];
    } catch (Exception $e) {
        error_log('Cloudinary Delete Error: ' . $e->getMessage());
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Upload image from base64 or file input
 * 
 * @param array $file $_FILES array element
 * @param string $folder Cloudinary folder
 * @return array Upload result
 */
function uploadImageFromFile($file, $folder = 'taskflow/profiles') {
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'error' => 'Invalid file upload'];
    }
    
    // Validate file type
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $file_type = mime_content_type($file['tmp_name']);
    
    if (!in_array($file_type, $allowed_types)) {
        return ['success' => false, 'error' => 'Invalid file type. Only JPEG, PNG, GIF, and WebP are allowed.'];
    }
    
    // Validate file size (max 5MB)
    $max_size = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $max_size) {
        return ['success' => false, 'error' => 'File size exceeds 5MB limit.'];
    }
    
    // Upload to Cloudinary
    $result = uploadToCloudinary($file['tmp_name'], $folder, [
        'transformation' => [
            ['width' => 800, 'height' => 800, 'crop' => 'limit'],
            ['quality' => 'auto'],
            ['fetch_format' => 'auto']
        ]
    ]);
    
    return $result;
}
