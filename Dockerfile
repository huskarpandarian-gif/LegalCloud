
FROM node:22 AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts

COPY . .
RUN npm run build


FROM dunglas/frankenphp:php8.4

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN install-php-extensions \
    pdo_mysql \
    pdo_sqlite \
    gd \
    intl \
    zip \
    bcmath \
    opcache

WORKDIR /app

COPY . /app
COPY --from=frontend /app/public /app/public

RUN composer install --no-dev --optimize-autoloader --no-interaction

ENV APP_ENV=production
ENV SERVER_NAME=:8080

EXPOSE 8080

CMD ["frankenphp", "php-server", "--listen", ":8080", "--root", "/app/public"]
