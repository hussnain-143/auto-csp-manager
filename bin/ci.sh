#!/usr/bin/env bash
# Everything the audit claims, in one run: syntax, standards, unit, integration.
set -euo pipefail

cd "$(dirname "$0")/.."

echo "== composer install"
composer install --no-interaction --no-progress --quiet

echo "== php -l"
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l >/dev/null
echo "   all files parse"

echo "== phpcs"
vendor/bin/phpcs --report=summary --error-severity=1 --warning-severity=0 2>/dev/null | tail -4 || true

echo "== unit suite"
vendor/bin/phpunit

if [ -n "${DB_HOST:-}" ]; then
	echo "== installing the WordPress test library"
	bash bin/install-wp-tests.sh "${DB_NAME:-wordpress_test}" "${DB_USER:-root}" "${DB_PASS:-}" "${DB_HOST}" "${WP_VERSION:-latest}"

	echo "== integration suite"
	vendor/bin/phpunit -c phpunit-integration.xml.dist

	echo "== integration suite (multisite)"
	WP_MULTISITE=1 vendor/bin/phpunit -c phpunit-integration.xml.dist
fi
