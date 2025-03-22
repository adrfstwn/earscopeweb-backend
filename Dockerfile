# FrankenPHP base image
FROM dunglas/frankenphp:latest

# Install dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    ffmpeg \
    && rm -rf /var/lib/apt/lists/*

# Install PHP Extensions
RUN install-php-extensions \
    pcntl \
    zip \
    bcmath \
    pdo_mysql \
    mysqli

# Install Composer secara manual
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Set working directory
WORKDIR /app

# Copy composer files lebih awal untuk caching layer
COPY composer.json composer.lock ./

# Install dependencies sebagai root (pastikan file artisan ada)
RUN composer install --ignore-platform-reqs --no-dev -a --no-scripts

# Copy semua file Laravel setelah composer install
COPY . .

# Jalankan ulang perintah yang butuh akses ke Laravel (pastikan artisan ada)
RUN composer dump-autoload --optimize && \
    php artisan package:discover --ansi

# Install Octane dengan FrankenPHP tanpa interaksi
RUN yes | php artisan octane:install --server=frankenphp --no-interaction

# Set permission Laravel storage & cache dengan user www-data
RUN chown -R www-data:www-data storage bootstrap/cache && \
    chmod -R 777 storage bootstrap/cache

# Konfigurasi PHP upload limit
COPY custom-file.ini /usr/local/etc/php/conf.d/custom.ini

# Expose port 8000
EXPOSE 8000

# Set User
USER www-data

# Jalankan Laravel Octane dengan FrankenPHP
ENTRYPOINT ["php", "artisan", "octane:frankenphp"]
CMD ["--host=0.0.0.0", "--port=8000"]
