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
        $url_path = 'uploads/organizations/';
    } elseif ($type === 'task_attachment') {
        $upload_dir .= 'task_attachments/';
        $url_path = 'uploads/task_attachments/';
    } else {
        $upload_dir .= 'profiles/';
        $url_path = 'uploads/profiles/';
    }
    
    // Create directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            return ['success' => false, 'error' => 'Failed to create upload directory'];
        }
    }
    
    // Full upload path
    $upload_path = $upload_dir . $guid_filename;
    
    // Optimize and compress image before saving
    $optimized = optimizeImage($file['tmp_name'], $upload_path, $extension, $file_type);
    
    if ($optimized['success']) {
        // Check final file size
        $final_size = filesize($upload_path);
        if ($final_size > $max_size) {
            // If still too large after optimization, try more aggressive compression
            $optimized = optimizeImage($file['tmp_name'], $upload_path, $extension, $file_type, true);
            $final_size = filesize($upload_path);
        }
        
        // Return relative path for database storage (just the filename)
        return [
            'success' => true,
            'filename' => $guid_filename, // Store only GUID filename in database
            'path' => $upload_path, // Full path for reference
            'url' => $url_path . $guid_filename,
            'original_size' => $file['size'],
            'optimized_size' => $final_size
        ];
    } else {
        // If optimization failed, try to save original file
        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
            return [
                'success' => true,
                'filename' => $guid_filename,
                'path' => $upload_path,
                'url' => $url_path . $guid_filename
            ];
        } else {
            return ['success' => false, 'error' => 'Failed to save image'];
        }
    }
}

/**
 * Optimize and compress image
 * 
 * @param string $source_path Source image path
 * @param string $destination_path Destination path
 * @param string $extension File extension
 * @param string $mime_type MIME type
 * @param bool $aggressive Use aggressive compression (lower quality)
 * @return array Result with success status
 */
function optimizeImage($source_path, $destination_path, $extension, $mime_type, $aggressive = false) {
    // Check if GD library is available
    if (!function_exists('imagecreatefromjpeg') && !function_exists('imagecreatefrompng')) {
        // GD not available, just copy the file
        return ['success' => copy($source_path, $destination_path)];
    }
    
    // Maximum dimensions for task attachments (larger for descriptions)
    $max_width = 1200;
    $max_height = 1200;
    $quality = $aggressive ? 75 : 85; // JPEG quality (75 for aggressive, 85 for normal)
    
    // Get image dimensions
    $image_info = getimagesize($source_path);
    if (!$image_info) {
        return ['success' => false, 'error' => 'Invalid image file'];
    }
    
    list($width, $height, $type) = $image_info;
    
    // Calculate new dimensions (maintain aspect ratio)
    $ratio = min($max_width / $width, $max_height / $height);
    $new_width = $width;
    $new_height = $height;
    
    // Only resize if image is larger than max dimensions
    if ($ratio < 1) {
        $new_width = (int)($width * $ratio);
        $new_height = (int)($height * $ratio);
    }
    
    // Create image resource based on type
    switch ($type) {
        case IMAGETYPE_JPEG:
            $source_image = imagecreatefromjpeg($source_path);
            break;
        case IMAGETYPE_PNG:
            $source_image = imagecreatefrompng($source_path);
            break;
        case IMAGETYPE_GIF:
            $source_image = imagecreatefromgif($source_path);
            break;
        case IMAGETYPE_WEBP:
            if (function_exists('imagecreatefromwebp')) {
                $source_image = imagecreatefromwebp($source_path);
            } else {
                return ['success' => copy($source_path, $destination_path)];
            }
            break;
        default:
            return ['success' => copy($source_path, $destination_path)];
    }
    
    if (!$source_image) {
        return ['success' => false, 'error' => 'Failed to create image resource'];
    }
    
    // Create new image with calculated dimensions
    $new_image = imagecreatetruecolor($new_width, $new_height);
    
    // Preserve transparency for PNG and GIF
    if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
        imagealphablending($new_image, false);
        imagesavealpha($new_image, true);
        $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
        imagefilledrectangle($new_image, 0, 0, $new_width, $new_height, $transparent);
    }
    
    // Resize image
    imagecopyresampled($new_image, $source_image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
    
    // Save optimized image
    $saved = false;
    switch ($type) {
        case IMAGETYPE_JPEG:
            $saved = imagejpeg($new_image, $destination_path, $quality);
            break;
        case IMAGETYPE_PNG:
            // PNG compression level (0-9, 9 is highest compression)
            $png_quality = $aggressive ? 9 : 7;
            $saved = imagepng($new_image, $destination_path, $png_quality);
            break;
        case IMAGETYPE_GIF:
            $saved = imagegif($new_image, $destination_path);
            break;
        case IMAGETYPE_WEBP:
            if (function_exists('imagewebp')) {
                $saved = imagewebp($new_image, $destination_path, $quality);
            } else {
                // Fallback to JPEG if WebP not supported
                $saved = imagejpeg($new_image, $destination_path, $quality);
                // Update extension in filename if needed
                $destination_path = preg_replace('/\.webp$/i', '.jpg', $destination_path);
            }
            break;
    }
    
    // Free memory
    imagedestroy($source_image);
    imagedestroy($new_image);
    
    return ['success' => $saved];
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
