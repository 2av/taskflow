# File Organization Guide

This document describes the organized file structure of the Task Flow system.

## Directory Structure

```
taskflow/
├── assets/              # Static assets
│   ├── css/            # Stylesheets
│   └── js/             # JavaScript files
├── config/             # Configuration files
│   ├── config.php      # Main configuration
│   ├── database.php    # Database configuration
│   ├── email.php       # Email configuration
│   └── cloudinary.php  # Cloudinary configuration
├── includes/           # Reusable components
│   ├── header.php      # Site header
│   └── footer.php      # Site footer
├── setup/              # Setup and migration scripts
│   ├── database_setup.php
│   └── migrate_*.php
├── uploads/            # Local file uploads (fallback)
│   ├── profiles/       # User profile pictures
│   ├── organizations/  # Organization logos
│   └── temp/           # Temporary uploads
├── vendor/             # Composer dependencies
└── [PHP files]         # Main application files
```

## File Categories

### Core Application Files
- `index.php` - Login page
- `dashboard.php` - Main dashboard
- `tasks.php` - Task list
- `task_view.php` - Task details
- `task_form.php` - Create/edit task
- `projects.php` - Project management
- `users.php` - User management
- `calendar.php` - Calendar view
- `reports.php` - Reports

### Profile & Settings
- `edit_profile.php` - Edit user profile (all users)
- `edit_organization.php` - Edit organization (admins only)
- `change_password.php` - Change password
- `subscription.php` - Subscription management

### Configuration
- `config/config.php` - Main config with helper functions
- `config/database.php` - Database connection
- `config/email.php` - Email settings
- `config/cloudinary.php` - Cloudinary file upload service

### Setup & Migration
- `setup/database_setup.php` - Initial database setup
- `setup/migrate_*.php` - Database migration scripts

## Cloudinary Integration

The system uses Cloudinary for file uploads (images, documents, etc.):

1. **Configuration**: Set credentials in `config/cloudinary.php`
2. **Profile Pictures**: Uploaded to `taskflow/profiles/` folder
3. **Organization Logos**: Uploaded to `taskflow/organizations/` folder
4. **Fallback**: If Cloudinary not configured, files save locally to `uploads/` directory

### Setting Up Cloudinary

1. Sign up at https://cloudinary.com
2. Get your credentials from the dashboard
3. Update `config/cloudinary.php` with:
   - Cloud Name
   - API Key
   - API Secret

Or set environment variables:
- `CLOUDINARY_CLOUD_NAME`
- `CLOUDINARY_API_KEY`
- `CLOUDINARY_API_SECRET`

## Profile Management

### User Profile (`edit_profile.php`)
- Available to all users
- Can edit: Full name, email, phone, bio, profile picture
- Profile pictures stored in Cloudinary or local uploads

### Organization Profile (`edit_organization.php`)
- Available only to Organization Admins
- Can edit: Organization name, email, phone, address, logo
- Organization logos stored in Cloudinary or local uploads

## Best Practices

1. **Never commit** actual uploaded files to git
2. Use Cloudinary for production (better performance, CDN)
3. Keep local uploads as fallback for development
4. Always validate file types and sizes before upload
5. Use proper error handling for upload operations
