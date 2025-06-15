#!/bin/bash

# expense.it Installation Script
# Automated setup for Ubuntu/Debian servers

set -e

echo "=== expense.it Installation Script ==="
echo "Setting up AI-powered expense tracker..."

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if running as root
if [ "$EUID" -eq 0 ]; then
    echo -e "${YELLOW}Warning: Running as root. Consider using a non-root user.${NC}"
fi

# Update system packages
echo -e "${GREEN}Updating system packages...${NC}"
sudo apt update

# Install PHP and required extensions
echo -e "${GREEN}Installing PHP and extensions...${NC}"
sudo apt install -y php8.1 php8.1-cli php8.1-fpm php8.1-gd php8.1-curl php8.1-json php8.1-mbstring php8.1-zip

# Install Composer if not present
if ! command -v composer &> /dev/null; then
    echo -e "${GREEN}Installing Composer...${NC}"
    curl -sS https://getcomposer.org/installer | php
    sudo mv composer.phar /usr/local/bin/composer
    sudo chmod +x /usr/local/bin/composer
else
    echo -e "${GREEN}Composer already installed${NC}"
fi

# Install web server (Apache)
echo -e "${GREEN}Installing Apache web server...${NC}"
sudo apt install -y apache2

# Enable required Apache modules
sudo a2enmod rewrite
sudo a2enmod headers

# Install PHP dependencies
echo -e "${GREEN}Installing PHP dependencies...${NC}"
composer install --no-dev --optimize-autoloader

# Create data directories with proper permissions
echo -e "${GREEN}Setting up data directories...${NC}"
mkdir -p data/trips data/archive
chmod 755 data data/trips data/archive

# Set proper ownership for web server
sudo chown -R www-data:www-data data/
sudo chmod -R 755 data/

# Create environment file from example
if [ ! -f .env ]; then
    echo -e "${GREEN}Creating environment file...${NC}"
    if [ -f .env.example ]; then
        cp .env.example .env
        echo -e "${YELLOW}Environment file created from .env.example${NC}"
        echo -e "${YELLOW}Please edit .env file and add your GEMINI_API_KEY${NC}"
    else
        echo -e "${YELLOW}.env.example not found, creating basic .env file...${NC}"
        cat > .env <<EOF
# Gemini AI API Key (required)
# Get your API key from: https://makersuite.google.com/app/apikey
GEMINI_API_KEY=your_gemini_api_key_here

# Application Environment
APP_ENV=production
DEBUG=false
EOF
        echo -e "${YELLOW}Please edit .env file and add your GEMINI_API_KEY${NC}"
    fi
else
    echo -e "${GREEN}.env file already exists${NC}"
fi

# Create Apache virtual host configuration
DOMAIN=${1:-expense.local}
WEBROOT=$(pwd)

echo -e "${GREEN}Creating Apache virtual host for $DOMAIN...${NC}"
sudo tee /etc/apache2/sites-available/expense.conf > /dev/null <<EOF
<VirtualHost *:80>
    ServerName $DOMAIN
    DocumentRoot $WEBROOT
    
    <Directory $WEBROOT>
        AllowOverride All
        Require all granted
        
        # Security headers
        Header always set X-Content-Type-Options nosniff
        Header always set X-Frame-Options DENY
        Header always set X-XSS-Protection "1; mode=block"
    </Directory>
    
    # File upload limits
    php_admin_value upload_max_filesize 10M
    php_admin_value post_max_size 10M
    php_admin_value max_execution_time 60
    
    # Hide sensitive files
    <Files ".env">
        Require all denied
    </Files>
    
    <Directory "$WEBROOT/vendor">
        Require all denied
    </Directory>
    
    ErrorLog \${APACHE_LOG_DIR}/expense_error.log
    CustomLog \${APACHE_LOG_DIR}/expense_access.log combined
</VirtualHost>
EOF

# Enable the site
sudo a2ensite expense.conf
sudo a2dissite 000-default.conf

# Create .htaccess file for URL rewriting
echo -e "${GREEN}Creating .htaccess file...${NC}"
cat > .htaccess <<EOF
RewriteEngine On

# Security headers
Header always set X-Content-Type-Options nosniff
Header always set X-Frame-Options DENY
Header always set X-XSS-Protection "1; mode=block"

# File upload limits
php_value upload_max_filesize 10M
php_value post_max_size 10M
php_value max_execution_time 60

# Hide sensitive files
<Files ".env">
    Require all denied
</Files>

<Files "composer.*">
    Require all denied
</Files>

# Block access to vendor directory
RedirectMatch 403 ^/vendor/.*$

# Block access to data directory (except for file serving)
<LocationMatch "^/data/(?!trips/.*/.*\.(pdf|jpg|jpeg|png|gif))">
    Require all denied
</LocationMatch>
EOF

# Test PHP installation
echo -e "${GREEN}Testing PHP installation...${NC}"
php -v

# Test required extensions
echo -e "${GREEN}Checking PHP extensions...${NC}"
php -m | grep -E "(gd|curl|json|mbstring|zip)" || {
    echo -e "${RED}Missing required PHP extensions${NC}"
    exit 1
}

# Restart Apache
echo -e "${GREEN}Restarting Apache...${NC}"
sudo systemctl restart apache2

# Add domain to hosts file if it's a local domain
if [[ $DOMAIN == *.local ]]; then
    if ! grep -q "$DOMAIN" /etc/hosts; then
        echo -e "${GREEN}Adding $DOMAIN to /etc/hosts...${NC}"
        echo "127.0.0.1 $DOMAIN" | sudo tee -a /etc/hosts
    fi
fi

echo ""
echo -e "${GREEN}=== Installation Complete! ===${NC}"
echo ""
echo "Next steps:"
echo "1. Edit .env file and add your GEMINI_API_KEY"
echo "   Get your API key from: https://makersuite.google.com/app/apikey"
echo ""
echo "2. Access your application at: http://$DOMAIN"
echo ""
echo "3. Check Apache logs if you encounter issues:"
echo "   sudo tail -f /var/log/apache2/expense_error.log"
echo ""
echo -e "${YELLOW}Note: For production use, consider setting up SSL/HTTPS${NC}"
echo ""
echo "Installation script completed successfully!"
EOF