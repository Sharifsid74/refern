FROM php:8.2-apache

# Install required extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable Apache rewrite module
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Set proper permissions for data files
RUN chown -R www-data:www-data /var/www/html && \
    chmod 755 /var/www/html && \
    chmod 664 users.json error.log && \
    chown www-data:www-data users.json error.log

# Expose port
EXPOSE 80

# Start Apache in foreground
CMD ["apache2-foreground"]