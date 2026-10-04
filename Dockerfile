FROM dunglas/frankenphp:php8.4-bookworm

# Install MySQL PDO support
RUN install-php-extensions pdo_mysql

# Application directory
WORKDIR /app

# Copy the project into the container
COPY . /app

# FrankenPHP configuration
COPY Caddyfile /etc/caddy/Caddyfile

# Railway provides PORT at runtime
EXPOSE 80

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]