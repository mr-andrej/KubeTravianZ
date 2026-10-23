FROM php:8.3-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    gd \
    mysqli \
    pdo \
    pdo_mysql \
    zip

# Enable Apache modules
RUN a2enmod rewrite headers

# Use the production PHP configuration as a baseline, then apply our overrides
# so that errors are hidden from end users and logged server-side instead.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php/zz-travianz.ini "$PHP_INI_DIR/conf.d/zz-travianz.ini"

# Set working directory
WORKDIR /var/www/html

# Bake the source into the image (production/K8s path).
# docker-compose bind-mounts ./ over this in local dev, so the copy is
# only authoritative where no mount exists (Kubernetes).
COPY --chown=www-data:www-data . /var/www/html

# Runtime-writable dirs (Kubernetes mounts PVC/Secret over Prevention and
# GameEngine/config.php; the rest still needs to be writable by Apache).
# mkdir -p first: .dockerignore excludes their *contents*, so the dirs may
# not survive COPY.
RUN mkdir -p /var/www/html/var/log /var/www/html/GameEngine/Prevention /var/www/html/GameEngine/Notes \
    && chown -R www-data:www-data /var/www/html/var /var/www/html/GameEngine/Prevention /var/www/html/GameEngine/Notes \
    && chmod -R 775 /var/www/html/var /var/www/html/GameEngine/Prevention /var/www/html/GameEngine/Notes

# Configure Apache to use /var/www/html as DocumentRoot
RUN sed -i 's!/var/www/html!/var/www/html!g' /etc/apache2/sites-available/000-default.conf

# Expose Apache port
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
