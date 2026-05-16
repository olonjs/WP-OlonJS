#!/bin/sh
# One-shot WordPress + plugin bootstrap for local Docker testing.
# Idempotent: safe to re-run.
#
# Run with:  docker compose run --rm --entrypoint /bin/sh wpcli /scripts/seed.sh

set -e

cd /var/www/html

echo "▸ Waiting for database..."
# wp db check shells out to the mariadb-check binary which has an SSL
# negotiation bug against the mariadb:10.11 server. wp db query goes through
# PHP's mysqli, which connects cleanly.
until wp db query 'SELECT 1' >/dev/null 2>&1; do
  sleep 1
done
echo "  database ready."

if ! wp core is-installed >/dev/null 2>&1; then
  echo "▸ Installing WordPress..."
  wp core install \
    --url=http://localhost:8080 \
    --title='OlonJS Local Test' \
    --admin_user=admin \
    --admin_password=admin \
    --admin_email=admin@example.com \
    --skip-email
else
  echo "▸ WordPress already installed."
fi

echo "▸ Setting permalink structure to /%postname%/"
wp rewrite structure '/%postname%/' --hard >/dev/null
wp rewrite flush --hard >/dev/null

if [ -f /var/www/html/wp-content/plugins/wp-olonjs/vendor/autoload.php ]; then
  echo "▸ Activating wp-olonjs plugin..."
  wp plugin activate wp-olonjs
else
  echo "✗ vendor/autoload.php is missing in the plugin directory."
  echo "  Run this first:  docker compose run --rm composer install"
  exit 1
fi

if ! wp post list --post_type=page --name=about --field=ID --format=count | grep -q '^1$'; then
  echo "▸ Creating /about page..."
  wp post create \
    --post_type=page \
    --post_status=publish \
    --post_name=about \
    --post_title='About the OlonJS test installation' \
    --post_excerpt='A description long enough to satisfy the Page schema minLength of fifty characters.' \
    --post_content='<!-- wp:heading --><h2>Hello from WP-OlonJS</h2><!-- /wp:heading --><!-- wp:paragraph --><p>This is the body paragraph of the about page.</p><!-- /wp:paragraph -->' \
    >/dev/null
fi

if ! wp post list --post_type=page --name=parent --field=ID --format=count | grep -q '^1$'; then
  echo "▸ Creating /parent/child nested pages..."
  PARENT_ID=$(wp post create \
    --post_type=page --post_status=publish \
    --post_name=parent \
    --post_title='Parent test page' \
    --post_excerpt='Parent page with an excerpt long enough to satisfy the schema description minLength.' \
    --post_content='<!-- wp:paragraph --><p>Parent body content paragraph.</p><!-- /wp:paragraph -->' \
    --porcelain)
  wp post create \
    --post_type=page \
    --post_status=publish \
    --post_name=child \
    --post_parent="$PARENT_ID" \
    --post_title='Nested child test page' \
    --post_excerpt='A description for the nested child page that is long enough for the schema minLength.' \
    --post_content='<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Left column content.</p><!-- /wp:paragraph --></div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Right column content.</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->' \
    >/dev/null
fi

echo ""
echo "✓ Setup complete."
echo ""
echo "  curl -i http://localhost:8080/about.json"
echo "  curl -i http://localhost:8080/parent/child.json"
echo "  wp-admin: http://localhost:8080/wp-admin    (admin / admin)"
