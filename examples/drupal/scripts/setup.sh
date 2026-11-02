#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

ddev start -y
ddev composer install --no-interaction
ddev exec --raw -- php scripts/install.php
ddev drush en code_mode_demo views menu_link_content path -y
ddev drush theme:enable olivero claro -y
ddev drush php:script /var/www/html/scripts/seed.php
ddev drush php:script /var/www/html/scripts/present.php
ddev drush cr
ddev drush php:script /var/www/html/scripts/snapshot.php
