FROM php:8.4.4-fpm

# Instala dependências do sistema
RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev zip unzip \
    libssl-dev pkg-config \
    libbrotli-dev \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Instala extensões essenciais do PHP + OPcache
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd opcache

# Instala o Redis e o SWOOLE
RUN pecl install redis-6.1.0 swoole-6.0.1 \
    && docker-php-ext-enable redis swoole opcache

# Pega o Composer mais recente
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Copia o código da aplicação para dentro da imagem
COPY . .

# Cria .env a partir do .env.example (valores reais vêm das vars de ambiente do Docker)
RUN cp -n .env.example .env || true

# Instala dependências PHP (sem pacotes de dev, otimizado para produção)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Permissões corretas
RUN chown -R www-data:www-data /var/www \
    && chmod -R 755 /var/www/storage \
    && chmod -R 755 /var/www/bootstrap/cache