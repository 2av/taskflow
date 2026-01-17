# Production Deployment Guide

## Configuration Changes Required

### 1. Update `config/config.php`

Change the following settings:

```php
// Line 5: Set environment to production
define('ENVIRONMENT', 'production');

// Lines 9-10: Update with your production values
define('SITE_NAME', 'Your Company Task System'); // Your actual site name
define('SITE_URL', 'https://yourdomain.com'); // Your actual domain (with https)
```

**Important:**
- Use `https://` (not `http://`) for SITE_URL in production
- Include the full domain (e.g., `https://tasks.yourcompany.com` or `https://yourcompany.com/jira`)

### 2. Update `config/database.php`

Change the following settings:

```php
// Line 4: Set environment to production
$environment = 'production';

// Lines 8-11: Update with your production database credentials
define('DB_HOST', 'localhost'); // Usually 'localhost' on shared hosting
define('DB_USER', 'your_db_username'); // Your hosting database username
define('DB_PASS', 'your_db_password'); // Your hosting database password
define('DB_NAME', 'your_db_name'); // Your hosting database name
```

**Important:**
- Never use default credentials (root/empty password) in production
- Use strong, unique passwords
- Database name might be prefixed by your hosting provider (e.g., `username_jira_system`)

### 3. Security Checklist

- [ ] Change `ENVIRONMENT` to `'production'` in both config files
- [ ] Update database credentials with production values
- [ ] Update SITE_URL to your actual domain with https
- [ ] Verify error reporting is disabled (automatically handled when ENVIRONMENT = 'production')
- [ ] Change default admin password after first login
- [ ] Ensure `.htaccess` file is in place (already created)
- [ ] Remove or protect `setup/database_setup.php` file (or restrict access)

### 4. File Permissions

On Linux servers, ensure proper file permissions:
```bash
chmod 644 config/*.php
chmod 755 setup/
```

### 5. Database Setup

1. Create database on your hosting provider
2. Run the setup: `http://yourdomain.com/setup/database_setup.php`
3. **IMPORTANT:** Delete or password-protect the setup folder after installation

### 6. Additional Production Recommendations

1. **SSL Certificate**: Ensure your site uses HTTPS (SSL certificate)
2. **Backup**: Set up regular database backups
3. **Updates**: Keep PHP and MySQL versions updated
4. **Monitoring**: Set up error logging and monitoring
5. **Performance**: Consider enabling PHP opcache
6. **Security**: Regularly update passwords and review user access

### 7. Testing After Deployment

- [ ] Test login functionality
- [ ] Verify database connections work
- [ ] Check all pages load correctly
- [ ] Test user creation and permissions
- [ ] Verify file uploads (if implemented later)
- [ ] Check mobile responsiveness
- [ ] Test on different browsers

### 8. Common Hosting Provider Notes

**cPanel/Shared Hosting:**
- Database host is usually `localhost`
- Database name often prefixed with your username
- Use phpMyAdmin to create database first

**VPS/Dedicated Server:**
- May need to configure MySQL user permissions
- May need to adjust firewall rules
- Consider using environment variables for sensitive data

### Need Help?

If you encounter issues:
1. Check error logs (usually in cPanel or server logs)
2. Verify database credentials are correct
3. Ensure PHP version is 7.0 or higher
4. Check file permissions
5. Verify .htaccess is working
