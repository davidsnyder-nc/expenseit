# Installation Guide for expense.it

## Server Requirements

### System Dependencies
- PHP 8.1 or higher
- Composer (PHP package manager)
- Web server (Apache/Nginx)
- Write permissions for data directory

### PHP Extensions Required
- php-gd (for image processing)
- php-curl (for API requests to Gemini)
- php-json (for JSON handling)
- php-mbstring (for string handling)
- php-zip (for file compression)
- php-fileinfo (for MIME type detection)

**Critical**: The `php-curl` and `php-fileinfo` extensions are essential for AI receipt processing. Without them, you'll get 500 errors when uploading files.

## Installation Steps

### 1. Install System Dependencies

#### Ubuntu/Debian
```bash
sudo apt update
sudo apt install php8.1 php8.1-gd php8.1-curl php8.1-json php8.1-mbstring php8.1-zip php8.1-fileinfo composer
```

#### CentOS/RHEL
```bash
sudo yum install php php-gd php-curl php-json php-mbstring php-zip composer
```

#### macOS (with Homebrew)
```bash
brew install php composer
```

### 2. Clone and Setup Project
```bash
# Clone the repository
git clone [your-repo-url] expense-tracker
cd expense-tracker

# Install PHP dependencies
composer install

# Set proper permissions
chmod 755 data/
chmod 755 data/trips/
chmod 755 data/archive/
```

### 3. Configure Environment

#### Create Environment File
Create a `.env` file in the project root:
```bash
# Gemini AI API Key (required for receipt processing)
GEMINI_API_KEY=your_gemini_api_key_here

# Application settings
APP_ENV=production
DEBUG=false
```

#### Web Server Configuration

##### Apache (.htaccess)
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]

# Security headers
Header always set X-Content-Type-Options nosniff
Header always set X-Frame-Options DENY
Header always set X-XSS-Protection "1; mode=block"

# File upload limits
php_value upload_max_filesize 10M
php_value post_max_size 10M
php_value max_execution_time 60
```

##### Nginx
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/expense-tracker;
    index index.html index.php;

    # File upload limits
    client_max_body_size 10M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    # Security
    location ~ /\. {
        deny all;
    }
}
```

### 4. API Key Setup

#### Get Gemini API Key
1. Visit [Google AI Studio](https://makersuite.google.com/app/apikey)
2. Create a new API key
3. Add the key to your `.env` file

#### Test API Connection
```bash
php -r "
require 'vendor/autoload.php';
\$key = getenv('GEMINI_API_KEY');
if (\$key) {
    echo 'API key configured successfully\n';
} else {
    echo 'ERROR: GEMINI_API_KEY not found\n';
}
"
```

### 5. Verify Installation

#### Check PHP Requirements
```bash
php -m | grep -E "(gd|curl|json|mbstring|zip)"
```

#### Test File Permissions
```bash
touch data/test_file && rm data/test_file && echo "Permissions OK" || echo "Permission Error"
```

#### Access Application
Open your browser and navigate to your domain. You should see the expense.it homepage.

## Troubleshooting

### Common Issues

#### 500 Internal Server Error on Receipt Processing
This typically occurs when required PHP extensions are missing or the API key is not properly configured.

**Step 1: Check PHP Extensions**
```bash
php -m | grep -E "(curl|fileinfo|gd|json)"
```

If any are missing:
```bash
sudo apt install php8.1-curl php8.1-fileinfo php8.1-gd php8.1-json
sudo systemctl restart apache2
```

**Step 2: Verify API Key Configuration**
```bash
# Check if .env file exists and has API key
cat .env | grep GEMINI_API_KEY

# Test API key with a simple call
php -r "
\$key = getenv('GEMINI_API_KEY') ?: (file_exists('.env') ? trim(explode('=', file_get_contents('.env'))[1]) : null);
if (\$key && \$key !== 'your_gemini_api_key_here') {
    echo 'API key found: ' . substr(\$key, 0, 10) . '...\n';
} else {
    echo 'ERROR: Valid API key not found in .env file\n';
}
"
```

**Step 3: Check Error Logs**
```bash
# Apache error log
sudo tail -f /var/log/apache2/error.log

# PHP error log
sudo tail -f /var/log/php8.1-fpm.log
```

#### "Class not found" errors
```bash
composer dump-autoload
```

#### Permission denied errors
```bash
sudo chown -R www-data:www-data data/
sudo chmod -R 755 data/
```

#### Upload errors
Check PHP configuration:
```bash
php -i | grep -E "(upload_max_filesize|post_max_size|max_execution_time)"
```

#### API connection issues
Verify your Gemini API key and internet connectivity:
```bash
curl -H "Content-Type: application/json" \
     -H "x-goog-api-key: YOUR_API_KEY" \
     https://generativelanguage.googleapis.com/v1beta/models
```

#### Files upload but processing fails
- Ensure `.env` file has valid `GEMINI_API_KEY`
- Check that `php-curl` extension is installed and enabled
- Verify write permissions on `data/trips/` directory
- Check server error logs for specific PHP errors

## Security Considerations

1. **Environment Variables**: Never commit `.env` files to version control
2. **File Permissions**: Ensure data directory is not publicly accessible
3. **API Keys**: Rotate API keys regularly
4. **HTTPS**: Use SSL certificates in production
5. **Updates**: Keep dependencies updated with `composer update`

## Production Deployment

### Additional Security Headers
```apache
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
Header always set Content-Security-Policy "default-src 'self'"
Header always set Referrer-Policy "strict-origin-when-cross-origin"
```

### Performance Optimization
```bash
# Enable OPCache
echo "opcache.enable=1" >> /etc/php/8.1/apache2/php.ini

# Restart web server
sudo systemctl restart apache2
```

## Support

If you encounter issues during installation:
1. Check the troubleshooting section above
2. Verify all requirements are met
3. Check server logs for specific error messages
4. Ensure proper file permissions and API key configuration