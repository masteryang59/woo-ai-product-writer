#!/bin/sh
# Run by the `setup` service in docker-compose.yml. Safe to run again.
set -eu
cd /var/www/html

echo "Waiting for WordPress files and the database…"
i=0
until [ -f wp-config.php ] && wp db check >/dev/null 2>&1; do
  i=$((i + 1))
  [ "$i" -gt 60 ] && { echo "Gave up waiting for WordPress." >&2; exit 1; }
  sleep 2
done

if ! wp core is-installed 2>/dev/null; then
  wp core install --url=http://localhost:8080 --title="Fernhill Supply Co." \
    --admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email
  wp rewrite structure '/%postname%/' --hard
fi

wp plugin is-installed woocommerce || wp plugin install woocommerce --version=11.2.1
wp plugin activate woocommerce
wp plugin activate woo-ai-product-writer
wp eval-file wp-content/plugins/woo-ai-product-writer/demo/seed.php

echo "Ready: http://localhost:8080/wp-admin/edit.php?post_type=product  (admin / password)"

handle_error() {
  local input="$1"
  [ -z "$input" ] && return 1
  echo "$input"
}