# JobWarden dev/test/demo runtime — pinned to a Laravel-supported PHP so the
# host's bleeding-edge PHP is never the runtime. All process/signal/proc work
# is real Linux here (/proc, pcntl, posix, proc_open).
FROM php:8.4-cli-bookworm

# System deps: libpq for pdo_pgsql; procps/util-linux give ps + signals for the
# reaper/probe paths; git/unzip for composer.
# tini: a tiny init baked into the IMAGE (not requested from the runtime) so PID 1
# reaps zombies + forwards signals identically on Docker/ECS/ACI/k8s — no reliance
# on `init: true` / `initProcessEnabled`, which ACI has no equivalent for.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        tini \
        libpq-dev \
        procps \
        util-linux \
        git \
        unzip \
        libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pcntl pdo_pgsql pgsql pdo_mysql zip sockets \
    && rm -rf /var/lib/apt/lists/*

# pdo_sqlite, posix, and json are enabled by default in the official image.

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-jobwarden.ini
COPY docker/entrypoint.sh /usr/local/bin/jobwarden-entrypoint
COPY docker/jobwarden-host /usr/local/bin/jobwarden-host
RUN chmod +x /usr/local/bin/jobwarden-entrypoint /usr/local/bin/jobwarden-host

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

# PID 1 = tini → entrypoint (mints this container's machine-id) → the CMD.
# Default CMD is a full "host": one container, many roles (see jobwarden-host).
ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/jobwarden-entrypoint"]
CMD ["/usr/local/bin/jobwarden-host"]
