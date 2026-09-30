# Scratch environment for the private-repo QC tests. Never used for deployment.
FROM php:8.3-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev unzip zip procps curl python3 \
 && docker-php-ext-install zip \
 && rm -rf /var/lib/apt/lists/* \
 && useradd -m -u 1000 bob
