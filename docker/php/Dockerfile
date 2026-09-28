# PHP для коробки Битрикс24: apache + mod_php, как на BitrixVM.
FROM php:8.2-apache

RUN apt-get update \
	&& apt-get install -y --no-install-recommends \
		libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
		libzip-dev libicu-dev libxml2-dev libonig-dev \
		unzip curl ffmpeg default-mysql-client \
	&& docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
	&& docker-php-ext-install -j"$(nproc)" gd mysqli pdo_mysql zip intl opcache bcmath exif soap sockets \
	&& a2enmod rewrite headers \
	&& rm -rf /var/lib/apt/lists/*

COPY bitrix.ini /usr/local/etc/php/conf.d/zz-bitrix.ini
COPY apache.conf /etc/apache2/conf-enabled/bitrix.conf

# Каталог логов вне корня сайта — как /home/bitrix/sh_log на BitrixVM
# (shef.problems кладёт логи на уровень выше DOCUMENT_ROOT).
RUN mkdir -p /var/www/sh_log && chown -R www-data:www-data /var/www
