# Server Setup Guide for URL Rewriting

## Quick Checklist

1. **Upload `.htaccess` file** to your server root directory
2. **Check mod_rewrite is enabled** (see test file below)
3. **Verify file permissions** (644 for .htaccess)
4. **Test the configuration** using the test file

## Step 1: Test Your Server Configuration

1. Upload `.htaccess-test.php` to your server
2. Access it via: `https://taskflow.ayodhyakashiyatra.com/.htaccess-test.php`
3. Review the test results
4. **DELETE the test file after testing** (security)

## Step 2: Common Server Issues & Solutions

### Issue 1: mod_rewrite Not Enabled

**Symptom:** URLs without .php return 404 errors

**Solution:** Contact your hosting provider to enable `mod_rewrite` module

**For cPanel:**
- Go to "Select PHP Version"
- Click "Extensions"
- Enable `mod_rewrite`

**For VPS/Dedicated Server:**
```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### Issue 2: .htaccess Not Being Read

**Symptom:** .htaccess file exists but has no effect

**Solutions:**
1. Check file permissions: `chmod 644 .htaccess`
2. Verify `AllowOverride All` is set in Apache config
3. Check if your hosting allows .htaccess files

**For cPanel:**
- Usually enabled by default
- Check "Apache Handlers" if needed

### Issue 3: Site in Subdirectory

**Symptom:** URLs work locally but not on server

**Solution:** If your site is in a subdirectory (e.g., `/taskflow/`), update `.htaccess`:

```apache
RewriteBase /taskflow/
```

Uncomment this line in `.htaccess` and set the correct path.

### Issue 4: Nginx Instead of Apache

**Symptom:** .htaccess has no effect (nginx doesn't use .htaccess)

**Solution:** You need to configure nginx directly. Add this to your nginx config:

```nginx
location / {
    try_files $uri $uri/ $uri.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
}
```

## Step 3: Verify It's Working

After setup, test these URLs:
- `https://taskflow.ayodhyakashiyatra.com/dashboard` ✓
- `https://taskflow.ayodhyakashiyatra.com/tasks?project_id=1` ✓
- `https://taskflow.ayodhyakashiyatra.com/projects` ✓

All should work without `.php` extension.

## Step 4: Contact Hosting Support

If nothing works, contact your hosting provider with:

1. Your domain: `taskflow.ayodhyakashiyatra.com`
2. Issue: "URL rewriting not working, .htaccess not being processed"
3. Request: "Please enable mod_rewrite and verify AllowOverride is set to All"

## Alternative: Use .php Extensions

If .htaccess cannot be made to work, you can:
1. Keep all URLs with .php extensions
2. Update all links in the code to include .php
3. This is less elegant but will work on any server
