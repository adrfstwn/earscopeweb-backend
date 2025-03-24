## 1. Build Stage: Install Composer Dependencies
FROM composer:latest AS build

# Workdir Build Stage
WORKDIR /app

# Copy Composer files lebih awal untuk cache
COPY composer.json composer.lock ./

# Install dependencies Laravel (tanpa dev dependencies)
RUN composer install --ignore-platform-reqs --no-dev -a


## 2. Main PHP Image
FROM dunglas/frankenphp:latest

# Workdir aplikasi
WORKDIR /app

# Install dependencies dengan cleanup agar image lebih kecil
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl ffmpeg \
    && install-php-extensions pcntl zip bcmath pdo_mysql mysqli \
    && rm -rf /var/lib/apt/lists/*

# Copy Composer Dependencies dari Build Stage
COPY --from=build /app/vendor /app/vendor

# Copy seluruh kode aplikasi setelah vendor masuk
COPY . .

# Set permission Laravel storage & cache untuk user www-data
RUN chown -R www-data:www-data storage bootstrap/cache && \
    chmod -R 777 storage bootstrap/cache

# Konfigurasi PHP upload limit (gunakan COPY untuk file custom)
COPY custom-file.ini /usr/local/etc/php/conf.d/custom.ini

# Gunakan user www-data untuk security
USER www-data

# Expose port 8000
EXPOSE 8000

# Jalankan Laravel Octane dengan FrankenPHP
ENTRYPOINT ["php", "artisan", "octane:frankenphp"]
CMD ["--host=0.0.0.0", "--port=8000"]
