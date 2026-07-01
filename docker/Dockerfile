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
    && docker-php-ext-install -j"$(nproc)" pcntl pdo_pgsql pgsql pdo_mysql zip sockets opcache \
    && rm -rf /var/lib/apt/lists/*

# pdo_sqlite, posix, and json are enabled by default in the official image.

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-jobwarden.ini
COPY docker/entrypoint.sh /usr/local/bin/jobwarden-entrypoint
COPY docker/jobwarden-host /usr/local/bin/jobwarden-host
RUN chmod +x /usr/local/bin/jobwarden-entrypoint /usr/local/bin/jobwarden-host

# OPcache's file_cache (see php.ini) needs its directory to EXIST at startup, or
# `opcache.file_cache_only=1` fatals PHP before it runs anything. Bake it in.
RUN mkdir -p /tmp/jobwarden-opcache && chmod 1777 /tmp/jobwarden-opcache

ENV COMPOSER_ALLOW_SUPERUSER=1

# ---- FULL BAKE ------------------------------------------------------------
# The real Laravel app + this package are baked onto the image's overlayfs (OFF
# the virtiofs bind mount), composer-installed with an authoritative classmap and
# `optimize`d. This is what keeps per-job boots ~150ms even at high concurrency:
# nothing the child touches at boot goes through the single virtiofs FUSE daemon
# (which serializes file ops and is what tanked throughput on the bind mount).
#
# The package is copied to /package and required by the app via a NON-symlinked
# path repo, so its source (incl. the ChildRunner timing probes) is baked in too.
# Source edits therefore require a rebuild — the trade-off for a virtiofs-free boot.
COPY composer.json composer.lock /package/
COPY src /package/src
COPY config /package/config
COPY database /package/database
COPY routes /package/routes
COPY resources /package/resources
COPY runtime /srv/app
RUN cd /srv/app \
    && mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer config repositories.jobwarden '{"type":"path","url":"/package","options":{"symlink":false}}' \
    # The app's lock was resolved against the old path repo ("../"), which points
    # nowhere in the image — so `composer install` would silently skip the package.
    # Drop the lock and re-resolve against /package so it mirrors in and lands in
    # the authoritative classmap.
    && rm -f composer.lock \
    && composer update --no-interaction --no-progress --optimize-autoloader --classmap-authoritative \
    && php artisan optimize

# CWD is the baked app on the image's overlayfs — never a bind mount. A child's
# inherited working directory matters: if CWD sat on a virtiofs mount, every
# getcwd()/realpath in the boot path would round-trip the host FUSE daemon.
WORKDIR /srv/app

# PID 1 = tini → entrypoint (mints this container's machine-id) → the CMD.
# Default CMD is a full "host": one container, many roles (see jobwarden-host).
ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/jobwarden-entrypoint"]
CMD ["/usr/local/bin/jobwarden-host"]
