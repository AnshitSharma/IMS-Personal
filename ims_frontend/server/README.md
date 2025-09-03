# Server Management Module

## Quick Start & Troubleshooting

### Issue: "Add Server" button not working

This is likely due to one of these issues:

#### 1. **Authentication Required** (Most Common)
The server management system requires a valid JWT token. If you don't have one:

**Solution A: Get a real JWT token**
1. Go back to the main login page (`../index.html`)
2. Login with valid credentials
3. Return to server management page

**Solution B: Use test mode**
1. Open `test.html` in this directory
2. Click "Set Test Token" button 
3. Reload the page
4. Now the "Add Server" button should work

#### 2. **JavaScript Errors**
**Solution: Check browser console**
1. Press F12 to open browser dev tools
2. Go to Console tab
3. Look for any red error messages
4. Refresh the page and check for errors during load

#### 3. **Missing Dependencies**
**Solution: Verify all scripts load**
1. Check that all these files exist and load properly:
   - `server-api.js`
   - `server-manager.js` 
   - `server-app.js`
   - Bootstrap CSS/JS
   - Font Awesome CSS

### Files Overview

- `index.html` - Main server management interface
- `test.html` - Test page that bypasses authentication
- `server-api.js` - API communication layer
- `server-manager.js` - Main application logic
- `server-app.js` - Application initialization
- `server-styles.css` - Custom styles

### Testing Steps

1. **Open test.html first** to verify basic functionality
2. **Check console logs** for detailed debugging info
3. **Test with real authentication** after confirming basic functionality works

### Expected Behavior

1. Page loads with server list (may be empty)
2. "Add Server Config" button is clickable
3. Modal opens with form fields
4. Form can be submitted (requires valid API endpoint)

### API Configuration

The system connects to: `https://shubham.staging.cloudmate.in/bdc_ims/api/api.php`

Make sure this endpoint is accessible and your JWT token has proper permissions.