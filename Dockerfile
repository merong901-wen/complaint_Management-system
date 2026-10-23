# 1. Use the official PHP image with Apache server preinstalled
FROM php:8.2-apache

# 2. Enable Apache mod_rewrite (essential for clean URLs and routing)
RUN a2enmod rewrite

# 3. Install common database drivers for PHP & MySQL
RUN docker-php-ext-install pdo pdo_mysql mysqli

# 4. Copy all files from your local folder into Apache's default web directory
COPY . /var/www/html/

# 5. Expose port 80 for web traffic
EXPOSE 80