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

# Pinned by digest, not by tag. A tag moves: `composer:2` silently becomes any
# new 2.x, and even `php:8.4-fpm-bookworm` is rebuilt for base-image security
# updates. That makes the same commit produce different images on different
# days, which undermines DEV-04 — rolling back to a previous release is only
# meaningful if the release is reproducible.
#
# Both digests are manifest-list (OCI image index) digests, not per-platform
# ones, so the same pin resolves correctly for amd64 in CI and arm64 locally.
# Pinning a platform-specific digest here would break one of the two.
#
# To update deliberately:
#   docker buildx imagetools inspect php:8.4-fpm-bookworm
#   docker buildx imagetools inspect composer:2
# then replace the digests below and let CI rebuild. Updating is now a decision
# rather than something that happens on its own.
#
# What this does NOT make reproducible: `apt-get install` below still resolves
# against whatever Debian publishes today, and `pecl install redis` takes the
# current release. Pinning the base closes the largest source of drift, not all
# of it. Full reproducibility would need pinned package versions and a PECL
# version, which is a deliberate later step, not an oversight here.
FROM php:8.4-fpm-bookworm@sha256:c5fb7a0c02f4efe280691910c8b734995fa83598cdcf3115ef5dcb2e4617681c

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

# ── Fonts (Design System §4.1) ───────────────────────────────────────────────
#
# The base image ships NO fonts and not even fontconfig — verified, and it is
# exactly the condition P-01 found on the on-premise server. A template that
# resolves `sans-serif` gets nothing here, and Arabic renders as tofu boxes.
#
# Two defences, deliberately both:
#   1. P-01's rule stands — the PDF template embeds its faces as base64 and
#      never references an OS font. That is the authoritative path (§14.6).
#   2. These packages are the fallback. Any glyph outside the embedded subset,
#      and anything rendering text server-side that is not the PDF template,
#      lands here instead of on a missing-font box.
#
# Installing them also changes what P-01's finding means: the application ships
# as this image, so "the server has no Arabic fonts" stops being true of the
# server that matters. That closes the risk at the infrastructure layer while
# embedding closes it at the document layer.
#
# fonts-noto-core is 41.6 MB and carries far more scripts than Arabic. Extracting
# only NotoSansArabic would save roughly 40 MB at the cost of hand-managing font
# files; against the headless Chrome layer arriving in point 0.5 that saving does
# not justify the fragility. Note the Debian package is the 2020 Noto release.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        fontconfig \
        fonts-noto-core \
        fonts-noto-mono \
        fonts-inter; \
    rm -rf /var/lib/apt/lists/*

# Installing the fonts is not enough. A request by explicit family name resolved
# correctly, but a generic one — what a renderer falls back to, and what Chrome
# uses by default — sent Arabic to DejaVu Sans, which arrives as a dependency of
# fontconfig-config and outranks Noto by default. See the file for the detail.
COPY fonts.conf /etc/fonts/conf.d/60-crm-fonts.conf
RUN fc-cache -f

# ── Headless browser for PDF generation (§14.6, D-57) ────────────────────────
#
# Chromium from Debian, not google-chrome-stable. Google publishes no arm64
# Linux build at all, so google-chrome-stable would build on the CI runner and
# fail on the development machine — the exact split D-66 is trying to keep
# visible rather than accidental.
#
# Node and Puppeteer are here because Browsershot needs them: it is a PHP
# wrapper that drives Chrome through Puppeteer's Node API. P-01 proved the
# rendering with this same engine, so only the wrapper changes for Module 9.
#
# PUPPETEER_SKIP_DOWNLOAD matters. Puppeteer otherwise downloads its own
# Chromium build on install — a second browser, hundreds of megabytes, and on
# arm64 an amd64 binary that cannot run.
ENV PUPPETEER_SKIP_DOWNLOAD=true \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium \
    CHROME_PATH=/usr/bin/chromium

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        chromium \
        nodejs \
        npm; \
    npm install -g --omit=dev puppeteer@23; \
    npm cache clean --force; \
    rm -rf /var/lib/apt/lists/* /root/.npm

# Composer, copied from its official image rather than curl | php.
COPY --from=composer:2@sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040 /usr/bin/composer /usr/bin/composer

# The base image sets no locale, so the default charmap is ANSI_X3.4-1968 —
# plain ASCII. Verified, not assumed: HarfBuzz refused Arabic input with
# "Invalid byte sequence in conversion input" until this was set. Anything that
# passes Arabic through a locale-aware C library — shell tooling, filenames,
# Chrome's own text handling in point 0.5 — is affected by this, and the failure
# mode is corruption rather than an error.
ENV LANG=C.UTF-8 \
    LC_ALL=C.UTF-8

# DB-08 — timestamps are stored UTC and converted for display only.
ENV TZ=UTC
RUN printf 'date.timezone=UTC\n' > /usr/local/etc/php/conf.d/zz-timezone.ini

# opcache ships enabled in the base image but unconfigured. PRF-01 targets an
# API P95 under 500 ms; running without a primed opcode cache forfeits that
# before a single query is written.
#
# validate_timestamps=1 is a DEVELOPMENT setting: it stats every file on every
# request so edits appear without a restart. In production it discards most of
# the benefit opcache exists for, and the symptom is diffuse slowness that gets
# blamed on the database. Production must override it with 0.
#
# The override is a file, not a rebuild: the base image reads every .ini in
# /usr/local/etc/php/conf.d, and later files win. Deployment drops in
# zzz-production.ini with `opcache.validate_timestamps=0` and
# `opcache.revalidate_freq=0`. Carried on the deployment-debt register until a
# real deployment proves it.
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
# No php.ini is active in the official image — verified: `php --ini` reports
# "Loaded Configuration File: (none)". That leaves PHP's built-in defaults, and
# two of them are wrong here in every environment:
#   log_errors=0  — errors go nowhere at all, which makes AUD-06's error centre
#                   impossible to feed.
#   error_log unset — with log_errors on, output must go to stderr so the
#                   container runtime collects it.
# display_errors is left at PHP's default here and turned off by
# zzz-production.ini at deployment; showing errors is useful while developing.
RUN { \
        echo 'upload_max_filesize=12M'; \
        echo 'post_max_size=16M'; \
        echo 'memory_limit=256M'; \
        echo 'expose_php=Off'; \
        echo 'log_errors=On'; \
        echo 'error_log=/proc/self/fd/2'; \
    } > /usr/local/etc/php/conf.d/zz-app.ini

# The container still runs as root by default, which is the official image's
# behaviour — php-fpm's master process is root and only its workers drop to
# www-data. That is deliberate here rather than overlooked: Laravel must be able
# to write to storage/ and bootstrap/cache, and setting USER before those
# directories exist would fix ownership to a tree that has not been created yet.
# The USER directive and the ownership of those paths land in point 0.6, when
# the image is wired into compose and an application directory exists.
# Carried on the deployment-debt register meanwhile.
WORKDIR /var/www/html
