#!/bin/bash

set -vo pipefail

# Install required libs for Drupal
GD_ENABLED=$(php -i | grep 'GD Support' | awk '{ print $4 }')

if [ "$GD_ENABLED" != 'enabled' ]; then
  apk update && \
  apk add libpng libpng-dev libjpeg-turbo-dev libwebp-dev zlib-dev libxpm-dev gd tree rsync && docker-php-ext-install gd
fi

# Create project in a temporary directory inside the container
INSTALL_DIR="/drupal_install_tmp"
composer create-project drupal/recommended-project:11.x-dev "$INSTALL_DIR" --no-interaction --stability=dev

cd "$INSTALL_DIR"

# Allow specific plugins needed by dependencies before requiring them.
composer config --no-plugins allow-plugins.tbachert/spi true --no-interaction

# Create phpstan.neon config file
cat <<EOF > phpstan.neon
parameters:
    paths:
        - web/modules/contrib/local_tts
    # Set the analysis level (0-9)
    level: 5
EOF

mkdir -p web/modules/contrib/

if [ ! -L "web/modules/contrib/local_tts" ]; then
  ln -s /src web/modules/contrib/local_tts
fi

# Install PHPStan extensions for Drupal 11 and Drush for command analysis
composer require --dev phpstan/phpstan mglaman/phpstan-drupal phpstan/phpstan-deprecation-rules drush/drush --with-all-dependencies --no-interaction

# Run phpstan
./vendor/bin/phpstan analyse --memory-limit=-1 -c phpstan.neon
