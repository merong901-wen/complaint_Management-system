# 1. Use the official PHP image with Apache server preinstalled
FROM php:8.2-apache

# 2. Enable Apache mod_rewrite (essential for clean URLs and routing)
RUN a2enmod rewrite

# 3. Install common database drivers for PHP & MySQL
RUN docker-php-ext-install pdo pdo_mysql mysqli

# Support the chat's 10 MB attachments and 15 MB recorded voice messages.
RUN printf "upload_max_filesize=16M\npost_max_size=32M\nmax_file_uploads=2\n" > /usr/local/etc/php/conf.d/chat-uploads.ini

# 4. Copy all files from your local folder into Apache's default web directory
COPY . /var/www/html/

# 5. Expose port 80 for web traffic
EXPOSE 80