#!/bin/bash

set -e

echo "COMPOSER_HOME: $COMPOSER_HOME"

# Allow the plugin installer
composer global config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true

# Resolve standards together against the supported PHPCS branch.
composer global require drupal/coder 'phpcompatibility/php-compatibility:^9.3' 'squizlabs/php_codesniffer:^3.13' --with-all-dependencies

export PATH="$PATH:$COMPOSER_HOME/vendor/bin"

# Install PHPCS plugin installer
composer global require dealerdirect/phpcodesniffer-composer-installer

composer global show -P
phpcs -i

# Configure PHPCS settings
phpcs --config-set colors 1
phpcs --config-set ignore_warnings_on_exit 1
phpcs --config-set drupal_core_version 11

phpcs --config-show
