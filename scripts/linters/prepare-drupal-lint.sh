#!/bin/bash

echo "$COMPOSER_HOME: $COMPOSER_HOME"

# Allow the plugin installer
composer global config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true

# Install Drupal coding standards, the PHP compatibility checker and the PHPCS plugin
# installer in one resolve, so the packages agree on the PHP_CodeSniffer version.
composer global require drupal/coder:^8.3 squizlabs/php_codesniffer:^3.7 phpcompatibility/php-compatibility:^9.3 dealerdirect/phpcodesniffer-composer-installer:^1.0

export PATH="$PATH:$COMPOSER_HOME/vendor/bin"

composer global show -P
phpcs -i

# Configure PHPCS settings
phpcs --config-set colors 1
phpcs --config-set ignore_warnings_on_exit 1
phpcs --config-set drupal_core_version 11

phpcs --config-show
