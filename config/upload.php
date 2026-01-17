<?php
/**
 * Local File Upload System
 * 
 * Handles file uploads to local storage with GUID filenames
 * Max file size: 200KB
 */

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
    
    return $guid . '.' . strtolower($extension);
}

/**
 * Upload image to local storage
 * 
 * @param array $file $_FILES array element
 * @param string $type 'profile' or 'organization'
 * @param int $max_size Maximum file size in bytes (default: 200KB)
 * @return array Upload result
 */
function uploadImageLocal($file, $type = 'profile', $max_size = 204800) {
    // Validate file upload
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'error' => 'Invalid file upload'];
    }
    
    // Check upload error
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
        ];
        return ['success' => false, 'error' => $error_messages[$file['error']] ?? 'Unknown upload error'];
    }
    
    // Validate file type
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $file_type = mime_content_type($file['tmp_name']);
    
    if (!in_array($file_type, $allowed_types)) {
        return ['success' => false, 'error' => 'Invalid file type. Only JPEG, PNG, GIF, and WebP are allowed.'];
    }
    
    // Validate file size
    if ($file['size'] > $max_size) {
        $max_size_kb = round($max_size / 1024, 2);
        $file_size_kb = round($file['size'] / 1024, 2);
        return ['success' => false, 'error' => "File size exceeds {$max_size_kb}KB limit. Your file is {$file_size_kb}KB."];
    }
    
    // Get file extension
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
    // Normalize extension
    $extension_map = ['jpeg' => 'jpg', 'jpg' => 'jpg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp'];
    $extension = $extension_map[strtolower($extension)] ?? 'jpg';
    
    // Generate unique GUID filename
    $guid_filename = generateUniqueFilename($extension);
    
    // Determine upload directory
    $upload_dir = __DIR__ . '/../uploads/';
    if ($type === 'organization') {
        $upload_dir .= 'organizations/';
    } else {
        $upload_dir .= 'profiles/';
    }
    
    // Create directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            return ['success' => false, 'error' => 'Failed to create upload directory'];
        }
    }
    
    // Full upload path
    $upload_path = $upload_dir . $guid_filename;
    
    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $upload_path)) {
        // Return relative path for database storage (just the filename)
        return [
            'success' => true,
            'filename' => $guid_filename, // Store only GUID filename in database
            'path' => $upload_path, // Full path for reference
            'url' => ($type === 'organization' ? 'uploads/organizations/' : 'uploads/profiles/') . $guid_filename
        ];
    } else {
        return ['success' => false, 'error' => 'Failed to move uploaded file'];
    }
}

/**
 * Delete image from local storage
 * 
 * @param string $filename GUID filename from database
 * @param string $type 'profile' or 'organization'
 * @return bool Success status
 */
function deleteImageLocal($filename, $type = 'profile') {
    if (empty($filename)) {
        return false;
    }
    
    // Determine file path
    $upload_dir = __DIR__ . '/../uploads/';
    if ($type === 'organization') {
        $upload_dir .= 'organizations/';
    } else {
        $upload_dir .= 'profiles/';
    }
    
    $file_path = $upload_dir . $filename;
    
    // Delete file if it exists
    if (file_exists($file_path)) {
        return unlink($file_path);
    }
    
    return false;
}

/**
 * Get image URL from filename (local storage only)
 * 
 * @param string $filename GUID filename from database
 * @param string $type 'profile' or 'organization'
 * @return string|null Image URL or null if empty
 */
function getImageUrlLocal($filename, $type = 'profile') {
    if (empty($filename)) {
        return null;
    }
    
    // If it's already a full URL, return as is (backward compatibility)
    if (filter_var($filename, FILTER_VALIDATE_URL)) {
        return $filename;
    }
    
    // Return local path
    $folder = $type === 'organization' ? 'uploads/organizations' : 'uploads/profiles';
    return $folder . '/' . $filename;
}
