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
$cloudinary_cloud_name = getenv('CLOUDINARY_CLOUD_NAME') ?: 'ducnefn6c';
$cloudinary_api_key = getenv('CLOUDINARY_API_KEY') ?: '353172497417511';
$cloudinary_api_secret = getenv('CLOUDINARY_API_SECRET') ?: 'LjwWHjph1EOdW_Ql6OfMAQ1_ODs';

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
 * Generate unique GUID filename
 * 
 * @param string $extension File extension (without dot)
 * @return string Unique GUID filename
 */
function generateUniqueFilename($extension = 'jpg') {
    // Generate GUID v4
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Set bits 6-7 to 10
    $guid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    
    return $guid . '.' . $extension;
}

/**
 * Upload file to Cloudinary with GUID filename
 * 
 * @param string $file_path Local file path
 * @param string $folder Cloudinary folder (optional)
 * @param string $public_id Custom public_id (GUID filename)
 * @param array $options Additional upload options
 * @return array|false Upload result or false on failure
 */
function uploadToCloudinary($file_path, $folder = 'taskflow', $public_id = null, $options = []) {
    global $cloudinary;
    
    try {
        // Generate GUID if not provided
        if (!$public_id) {
            $extension = pathinfo($file_path, PATHINFO_EXTENSION) ?: 'jpg';
            $public_id = generateUniqueFilename($extension);
        }
        
        // Construct full public_id with folder
        $full_public_id = $folder ? $folder . '/' . $public_id : $public_id;
        
        $default_options = [
            'public_id' => $full_public_id,
            'resource_type' => 'auto', // auto-detect image, video, raw
            'overwrite' => true,
            'invalidate' => true
        ];
        
        // Merge all options (transformations included)
        $upload_options = array_merge($default_options, $options);
        
        // Debug: Log upload attempt
        error_log('Cloudinary upload attempt - Public ID: ' . $full_public_id);
        error_log('File path: ' . $file_path);
        error_log('File exists: ' . (file_exists($file_path) ? 'Yes' : 'No'));
        error_log('File size: ' . (file_exists($file_path) ? filesize($file_path) : 'N/A') . ' bytes');
        
        $result = $cloudinary->uploadApi()->upload($file_path, $upload_options);
        
        // Debug: Log upload result
        if (isset($result['secure_url'])) {
            error_log('Cloudinary upload SUCCESS - URL: ' . $result['secure_url']);
            error_log('Public ID: ' . ($result['public_id'] ?? 'N/A'));
        } else {
            error_log('Cloudinary upload FAILED - No secure_url in result');
            error_log('Result: ' . print_r($result, true));
        }
        
        return [
            'success' => true,
            'filename' => $public_id, // Return only GUID filename (without folder path) for database
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
 * Get full URL from GUID filename
 * 
 * @param string $filename GUID filename stored in database
 * @param string $folder Cloudinary folder (e.g., 'taskflow/profiles' or 'taskflow/organizations')
 * @return string Full Cloudinary URL or local path
 */
function getImageUrlFromFilename($filename, $folder = 'taskflow') {
    global $cloudinary_cloud_name;
    
    if (empty($filename)) {
        return null;
    }
    
    // If it's already a full URL, return as is (backward compatibility)
    if (filter_var($filename, FILTER_VALIDATE_URL)) {
        return $filename;
    }
    
    // Check if Cloudinary is configured
    if ($cloudinary_cloud_name && $cloudinary_cloud_name !== 'your_cloud_name') {
        // Construct Cloudinary URL from GUID filename
        // Format: https://res.cloudinary.com/{cloud_name}/image/upload/{folder}/{filename}
        // Note: filename already includes extension, so use it directly
        $public_id = $folder . '/' . pathinfo($filename, PATHINFO_FILENAME);
        $format = pathinfo($filename, PATHINFO_EXTENSION) ?: 'jpg';
        
        $url = "https://res.cloudinary.com/{$cloudinary_cloud_name}/image/upload/{$public_id}.{$format}";
        
        // Debug logging
        error_log("Constructed Cloudinary URL: {$url} from filename: {$filename}, folder: {$folder}");
        
        return $url;
    }
    
    // Fallback to local storage path
    // Extract folder name from full path (e.g., 'taskflow/profiles' -> 'profiles')
    $local_folder = basename($folder);
    if ($local_folder === 'taskflow') {
        $local_folder = 'profiles'; // default
    }
    
    return "uploads/{$local_folder}/{$filename}";
}

/**
 * Delete file from Cloudinary using GUID filename
 * 
 * @param string $filename GUID filename from database
 * @param string $folder Cloudinary folder
 * @return array|false Delete result or false on failure
 */
function deleteFromCloudinary($filename, $folder = 'taskflow') {
    global $cloudinary;
    
    try {
        // Construct public_id from GUID filename
        $public_id = $folder . '/' . pathinfo($filename, PATHINFO_FILENAME);
        
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
 * Upload image from file input with GUID filename
 * 
 * @param array $file $_FILES array element
 * @param string $folder Cloudinary folder (e.g., 'taskflow/profiles' or 'taskflow/organizations')
 * @return array Upload result with 'filename' (GUID) for database storage
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
    
    // Get file extension
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
    // Normalize extension
    $extension_map = ['jpeg' => 'jpg', 'jpg' => 'jpg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp'];
    $extension = $extension_map[strtolower($extension)] ?? 'jpg';
    
    // Generate unique GUID filename
    $guid_filename = generateUniqueFilename($extension);
    
    // Upload to Cloudinary with GUID as public_id
    try {
        // Upload with transformations (Cloudinary v3.x format - simplified)
        // Note: transformations can be passed as array or string
        $result = uploadToCloudinary($file['tmp_name'], $folder, $guid_filename, [
            'transformation' => [
                'width' => 800,
                'height' => 800,
                'crop' => 'limit',
                'quality' => 'auto',
                'fetch_format' => 'auto'
            ]
        ]);
        
        // Return only GUID filename for database (not full URL)
        if ($result['success']) {
            // Debug logging
            error_log('Cloudinary upload successful - GUID: ' . $result['filename']);
            error_log('Cloudinary URL: ' . $result['url']);
            error_log('Public ID: ' . $result['public_id']);
            
            return [
                'success' => true,
                'filename' => $result['filename'], // GUID filename to store in database
                'url' => $result['url'], // Full URL for immediate display
                'public_id' => $result['public_id'] // For debugging
            ];
        }
        
        return $result;
    } catch (Exception $e) {
        error_log('Cloudinary upload exception: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        return [
            'success' => false,
            'error' => 'Upload failed: ' . $e->getMessage()
        ];
    }
}
