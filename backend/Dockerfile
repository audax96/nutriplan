# Development image for the NutriPlan API (PHP built-in server).
#
# Security: PHP must be 8.3.36 or newer (CVE-2026-109618 / CVE-2026-109619, "PHP2Shell").
# Always rebuild with --pull so Docker fetches the newest 8.3 image instead of a cached one:
#   docker compose build --pull api
FROM php:8.3-cli

# Refuse to build on a PHP that still has the October 2026 remote-code-execution bugs.
RUN php -r 'if (version_compare(PHP_VERSION, "8.3.36", "<")) { fwrite(STDERR, "PHP " . PHP_VERSION . " is vulnerable (CVE-2026-109618/109619). Rebuild with: docker compose build --pull --no-cache api\n"); exit(1); }'

RUN docker-php-ext-install pdo_mysql

# Production php.ini (errors are logged, never printed into responses) plus a few extras.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && { \
      echo 'expose_php = Off'; \
      echo 'display_errors = Off'; \
      echo 'display_startup_errors = Off'; \
      echo 'log_errors = On'; \
      echo 'error_log = /dev/stderr'; \
      echo 'memory_limit = 256M'; \
      echo 'post_max_size = 16M'; \
      echo 'file_uploads = Off'; \
      echo 'allow_url_fopen = Off'; \
      echo 'allow_url_include = Off'; \
      echo 'session.use_strict_mode = 1'; \
    } > "$PHP_INI_DIR/conf.d/zz-nutriplan.ini"

WORKDIR /app
COPY --chown=root:root . /app

# Run as an unprivileged user, never as root.
USER www-data

EXPOSE 8000
# Several workers, because one plan request waits on Claude for a minute or two.
ENV PHP_CLI_SERVER_WORKERS=4
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "public/index.php"]
