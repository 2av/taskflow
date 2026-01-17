# Local File Upload System Documentation

## Overview

The system uses **local file storage** with **unique GUID filenames** for all uploaded images. Only the GUID filename is stored in the database, not the full path or URL.

## How It Works

### 1. Upload Process

When an image is uploaded:
1. System validates file type (JPEG, PNG, GIF, WebP only)
2. System validates file size (max 200KB)
3. System generates a unique GUID filename (e.g., `a1b2c3d4-e5f6-7890-abcd-ef1234567890.jpg`)
4. Image is saved to local `uploads/` directory
5. **Only the GUID filename is saved in the database** (e.g., `a1b2c3d4-e5f6-7890-abcd-ef1234567890.jpg`)
6. Full URL is constructed when needed for display

### 2. Storage Locations

#### Local Storage
- **Profile Pictures**: `uploads/profiles/{GUID}.{ext}`
- **Organization Logos**: `uploads/organizations/{GUID}.{ext}`
- **Example Path**: `uploads/profiles/a1b2c3d4-e5f6-7890-abcd-ef1234567890.jpg`

### 3. Database Storage

**Database stores ONLY the GUID filename:**
- `users.profile_picture` = `a1b2c3d4-e5f6-7890-abcd-ef1234567890.jpg`
- `organizations.logo` = `b2c3d4e5-f6g7-8901-bcde-f12345678901.png`

**NOT stored:**
- ❌ Full file path
- ❌ Full URL
- ❌ Original filename

### 4. File Size Limits

- **Maximum file size**: 200KB (204,800 bytes)
- **Allowed formats**: JPEG, PNG, GIF, WebP
- Validation happens both client-side and server-side

### 5. Display Images

Use the helper function to get the full URL:

```php
// Get profile picture URL
$profile_url = getImageUrl($user['profile_picture'], 'profile');

// Get organization logo URL
$logo_url = getImageUrl($organization['logo'], 'organization');
```

The function automatically:
- Checks if it's already a URL (backward compatibility)
- Constructs local path from GUID filename
- Returns relative path for use in HTML

## File Structure

```
uploads/
├── profiles/
│   └── {GUID}.jpg
└── organizations/
    └── {GUID}.png
```

## Functions

### `uploadImageLocal($file, $type, $max_size)`
Uploads an image to local storage.

**Parameters:**
- `$file`: `$_FILES` array element
- `$type`: `'profile'` or `'organization'`
- `$max_size`: Maximum file size in bytes (default: 200KB)

**Returns:**
```php
[
    'success' => true,
    'filename' => 'a1b2c3d4-e5f6-7890-abcd-ef1234567890.jpg',
    'path' => '/full/path/to/uploads/profiles/a1b2c3d4-e5f6-7890-abcd-ef1234567890.jpg',
    'url' => 'uploads/profiles/a1b2c3d4-e5f6-7890-abcd-ef1234567890.jpg'
]
```

### `deleteImageLocal($filename, $type)`
Deletes an image from local storage.

**Parameters:**
- `$filename`: GUID filename from database
- `$type`: `'profile'` or `'organization'`

**Returns:** `true` on success, `false` on failure

### `getImageUrlLocal($filename, $type)`
Gets the URL for an image.

**Parameters:**
- `$filename`: GUID filename from database
- `$type`: `'profile'` or `'organization'`

**Returns:** Relative URL path (e.g., `uploads/profiles/filename.jpg`)

### `generateUniqueFilename($extension)`
Generates a unique GUID v4 filename.

**Parameters:**
- `$extension`: File extension without dot (e.g., `'jpg'`)

**Returns:** GUID filename with extension (e.g., `a1b2c3d4-e5f6-7890-abcd-ef1234567890.jpg`)

## Benefits

1. **Database Efficiency**: Only stores short GUID filenames (~40 chars) instead of long paths
2. **Uniqueness**: GUID ensures no filename conflicts
3. **Security**: Original filenames not exposed
4. **Simplicity**: No external service dependencies
5. **Portability**: Easy to migrate between servers

## Example Usage

```php
// Upload profile picture
if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
    $upload_result = uploadImageLocal($_FILES['profile_picture'], 'profile', 204800);
    
    if ($upload_result['success']) {
        // Delete old picture if exists
        if ($old_filename && function_exists('deleteImageLocal')) {
            deleteImageLocal($old_filename, 'profile');
        }
        
        // Save only GUID filename to database
        $filename = $upload_result['filename'];
        $stmt->bind_param("s", $filename);
    } else {
        $error = $upload_result['error'];
    }
}

// Display image
$user = get_user_data();
$profile_url = getImageUrl($user['profile_picture'], 'profile');
echo '<img src="' . htmlspecialchars($profile_url) . '">';
```

## Error Handling

The system provides detailed error messages:
- File size exceeds limit
- Invalid file type
- Upload directory creation failed
- File move failed
- PHP upload errors

All errors are returned in the upload result array with descriptive messages.
