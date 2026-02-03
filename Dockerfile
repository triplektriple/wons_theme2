# Use the official WordPress 8.1 Apache image as base
FROM wordpress:php8.3-apache

# Copy local wp-content into the container
COPY wp-content /var/www/html/wp-content

# Set permissions
RUN chown -R www-data:www-data /var/www/html/wp-content && \
    chmod -R 755 /var/www/html/wp-content    
# Configure PHP settings
RUN echo "upload_max_filesize = 50M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "post_max_size = 50M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/uploads.ini