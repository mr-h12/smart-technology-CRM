# CRM application image — production-matched (D-66).
#
# Debian (bookworm), not Alpine, and the reasons are specific to this project:
#   1. Point 0.5 installs headless Chrome for Browsershot (§14.6, D-57). Chrome
#      on musl is not a supported path; Debian is.
#   2. Point 0.4 installs Arabic fonts. P-01 proved the Linux server carries
#      none, and Debian's fontconfig packaging is what that finding assumes.
#   3. intl links against ICU. Debian ships full locale data; musl's locale
#      support is limited, and Arabic collation is not somewhere to economise.
#   4. postgres:17.5-bookworm was chosen in point 0.1 for the same reason.
#
# PHP 8.4 satisfies Laravel 12 (>= 8.2) and Laravel 13 (>= 8.3), verified
# against laravel.com rather than assumed. OD-03 is still open, so the exact
# version is a recorded assumption.
#
# ARCHITECTURE: this builds for the host's architecture, which on Apple Silicon
# means linux/arm64 while the server is almost certainly linux/amd64. Decided on
# 2026-08-19 to continue on arm64 and switch when OD-03 names the architecture.
# Parity therefore covers the OS, libraries, locale and fonts — not the
# instruction set. Carried on the deployment-debt register in CHECKLIST.md.
# The switch is `docker build --platform linux/amd64`.

FROM php:8.4-fpm-bookworm

# Build-time only. Removed in the same layer so they never reach the runtime
# image; the matching runtime libraries are installed separately below.
ARG BUILD_DEPS="libpq-dev libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev"
ARG RUNTIME_DEPS="libpq5 libicu72 libzip4 libpng16-16 libjpeg62-turbo libfreetype6 libwebp7"

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends $BUILD_DEPS $RUNTIME_DEPS; \
    \
    # gd is configured before install so JPEG, FreeType and WebP support is
    # compiled in. §17 allows WEBP uploads and requires automatic image
    # compression, neither of which works with a bare gd.
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    \
    docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        bcmath \
        intl \
        zip \
        gd \
        pcntl \
        exif; \
    \
    # phpredis comes from PECL, not the bundled extensions. The blank lines
    # answer its igbinary/lzf/zstd prompts with the defaults.
    printf "\n\n\n\n" | pecl install redis; \
    docker-php-ext-enable redis; \
    \
    # Strip the toolchain and headers. Keeping them would roughly double the
    # image and hand a compiler to anything that reaches the container.
    apt-get purge -y --auto-remove $BUILD_DEPS; \
    rm -rf /var/lib/apt/lists/* /tmp/pear

# Composer, copied from its official image rather than curl | php.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# DB-08 — timestamps are stored UTC and converted for display only.
ENV TZ=UTC
RUN printf 'date.timezone=UTC\n' > /usr/local/etc/php/conf.d/zz-timezone.ini

# opcache ships enabled in the base image but unconfigured. PRF-01 targets an
# API P95 under 500 ms; running without a primed opcode cache forfeits that
# before a single query is written. validate_timestamps is left on here because
# this image is also the development image — the production override belongs to
# the deployment-debt register (D-66).
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=1'; \
        echo 'opcache.revalidate_freq=0'; \
    } > /usr/local/etc/php/conf.d/zz-opcache.ini

# §17 sets the configurable file-size ceiling at 10 MB (D-39). PHP must not
# reject an upload before the application can apply its own documented rule and
# error message, so its limits sit just above and the application enforces.
RUN { \
        echo 'upload_max_filesize=12M'; \
        echo 'post_max_size=16M'; \
        echo 'memory_limit=256M'; \
        echo 'expose_php=Off'; \
    } > /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html
