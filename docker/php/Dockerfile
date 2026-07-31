FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    bash \
    git \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    libxml2-dev \
    oniguruma-dev \
    icu-dev \
    autoconf \
    g++ \
    make \
    linux-headers

RUN docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        gd \
        zip \
        pcntl \
        bcmath \
        intl \
        opcache \
        soap

RUN pecl install redis && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN addgroup -g 1000 www && adduser -G www -g www -s /bin/sh -D www

WORKDIR /var/www

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-custom.ini

USER www

EXPOSE 9000

CMD ["php-fpm"]
