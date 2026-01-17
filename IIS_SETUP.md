# IIS Server Setup Guide

## Important: Your Server is IIS, Not Apache!

Your server is running **Microsoft IIS/10.0**, which means:
- ❌ `.htaccess` files **DO NOT WORK** on IIS
- ✅ You need to use `web.config` instead
- ✅ IIS uses URL Rewrite module for clean URLs

## Step 1: Install URL Rewrite Module (If Not Already Installed)

1. Download URL Rewrite Module 2.1 from Microsoft:
   https://www.iis.net/downloads/microsoft/url-rewrite

2. Install it on your server (or ask your hosting provider to install it)

3. Verify it's installed by checking IIS Manager → Features → URL Rewrite

## Step 2: Upload web.config File

1. **Upload `web.config`** to your server root directory:
   - Location: `C:\Inetpub\vhosts\ayodhyakashiyatra.com\taskflow.ayodhyakashiyatra.com\`
   - Same location as your `.htaccess` file

2. **Set proper permissions** (if needed):
   - Right-click `web.config` → Properties → Security
   - Ensure IIS_IUSRS has Read permissions

## Step 3: Verify Configuration

After uploading `web.config`, test these URLs:
- `https://taskflow.ayodhyakashiyatra.com/dashboard` ✓
- `https://taskflow.ayodhyakashiyatra.com/tasks?project_id=1` ✓
- `https://taskflow.ayodhyakashiyatra.com/projects` ✓

## Step 4: If URL Rewrite Module is Not Available

If your hosting provider cannot install URL Rewrite module, you have two options:

### Option A: Use .php Extensions (Simplest)
Keep all URLs with `.php` extensions. Update all links in code to include `.php`.

### Option B: Use PHP-Based Routing
Create an `index.php` router that handles all requests (more complex).

## Troubleshooting

### Issue: 404 Errors Still Occurring

1. **Check URL Rewrite Module is installed:**
   - Contact hosting provider
   - Or check IIS Manager → Features → URL Rewrite

2. **Verify web.config syntax:**
   - Use XML validator
   - Check for typos

3. **Check file permissions:**
   - `web.config` should be readable by IIS

4. **Check IIS error logs:**
   - Location: `C:\inetpub\logs\LogFiles\`
   - Look for rewrite-related errors

### Issue: 500 Internal Server Error

1. Check `web.config` XML syntax is valid
2. Verify URL Rewrite module is installed
3. Check IIS error logs for specific error messages

## Contact Your Hosting Provider

If you need help, contact your hosting provider with:

1. **Request:** "Please install URL Rewrite Module 2.1 for IIS"
2. **Reason:** "Need clean URLs without .php extensions"
3. **Domain:** `taskflow.ayodhyakashiyatra.com`

Most hosting providers can install this module quickly.

## Alternative: Keep .php Extensions

If URL Rewrite cannot be enabled, you can:
1. Keep all URLs with `.php` extensions
2. This will work on any server without additional configuration
3. Less elegant but fully functional
