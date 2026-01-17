# Cloudinary Setup Guide

## What is Cloudinary?

Cloudinary is a cloud-based image and video management service that provides:
- Fast CDN delivery
- Automatic image optimization
- Image transformations
- Secure file storage
- Free tier available

## Setup Instructions

### Step 1: Create Cloudinary Account

1. Go to https://cloudinary.com/users/register/free
2. Sign up for a free account
3. Verify your email address

### Step 2: Get Your Credentials

1. Log in to your Cloudinary dashboard
2. Go to **Settings** → **Product Environment Credentials**
3. Copy the following:
   - **Cloud Name**
   - **API Key**
   - **API Secret**

### Step 3: Configure in Task Flow

#### Option A: Direct Configuration (Development)

Edit `config/cloudinary.php` and replace:

```php
$cloudinary_cloud_name = 'your_cloud_name';
$cloudinary_api_key = 'your_api_key';
$cloudinary_api_secret = 'your_api_secret';
```

With your actual credentials:

```php
$cloudinary_cloud_name = 'dxyz1234';
$cloudinary_api_key = '123456789012345';
$cloudinary_api_secret = 'abcdefghijklmnopqrstuvwxyz';
```

#### Option B: Environment Variables (Production - Recommended)

Set environment variables on your server:

```bash
CLOUDINARY_CLOUD_NAME=your_cloud_name
CLOUDINARY_API_KEY=your_api_key
CLOUDINARY_API_SECRET=your_api_secret
```

### Step 4: Test the Configuration

1. Go to **Edit Profile** page
2. Try uploading a profile picture
3. Check if the image appears correctly

## Features

### Automatic Image Optimization
- Images are automatically optimized for web
- Format conversion (WebP when supported)
- Quality optimization
- Responsive images

### Image Transformations
Profile pictures are automatically:
- Resized to max 800x800px
- Optimized for quality
- Converted to optimal format

### File Organization
- Profile pictures: `taskflow/profiles/`
- Organization logos: `taskflow/organizations/`
- Task attachments: `taskflow/tasks/` (future)

## Fallback to Local Storage

If Cloudinary is not configured:
- Files will be saved locally in `uploads/` directory
- This works for development but not recommended for production

## Security Notes

⚠️ **Important**: Never commit your API secret to version control!

- Use environment variables in production
- Keep credentials secure
- Rotate API keys if compromised

## Free Tier Limits

Cloudinary free tier includes:
- 25 GB storage
- 25 GB bandwidth/month
- 25,000 transformations/month

This is sufficient for most small to medium applications.

## Support

For issues or questions:
- Cloudinary Docs: https://cloudinary.com/documentation
- Cloudinary Support: https://support.cloudinary.com
