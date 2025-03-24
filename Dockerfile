## Main PHP Image
FROM dunglas/frankenphp:latest

# Install dependencies dengan cleanup agar image lebih kecil
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl ffmpeg \
    && install-php-extensions pcntl zip bcmath pdo_mysql mysqli \
    && rm -rf /var/lib/apt/lists/*
    
# Workdir aplikasi
WORKDIR /app

# Copy seluruh kode aplikasi setelah vendor masuk
COPY . .

# Install dependencies lebih awal untuk caching
RUN composer install --ignore-platform-reqs --no-dev -a

# Install Octane dengan FrankenPHP tanpa interaksi
RUN echo "yes" | php artisan octane:install --server=frankenphp --no-interaction

# Set permission Laravel storage & cache dengan user www-data
RUN chown -R www-data:www-data storage bootstrap/cache && \
    chmod -R 777 storage bootstrap/cache

# Konfigurasi PHP upload limit (gunakan COPY untuk file custom)
COPY custom-file.ini /usr/local/etc/php/conf.d/custom.ini

# Expose port 8000
EXPOSE 8000

# Jalankan Laravel Octane dengan FrankenPHP
ENTRYPOINT ["php", "artisan", "octane:frankenphp"]
CMD ["--host=0.0.0.0", "--port=8000"]
