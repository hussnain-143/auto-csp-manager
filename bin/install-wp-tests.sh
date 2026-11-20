#!/usr/bin/env bash
# Install the WordPress test library and a test database, for the integration
# suite. Standard WP-CLI scaffold script, trimmed to what this plugin needs.
#
# Usage: bash bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]

set -euo pipefail

DB_NAME=${1:-wordpress_test}
DB_USER=${2:-root}
DB_PASS=${3:-}
DB_HOST=${4:-localhost}
WP_VERSION=${5:-latest}

WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress/}

download() {
	if command -v curl >/dev/null; then
		curl -s "$1" >"$2"
	else
		wget -nv -O "$2" "$1"
	fi
}

if [ "$WP_VERSION" = "latest" ]; then
	download https://api.wordpress.org/core/version-check/1.7/ /tmp/wp-latest.json
	WP_VERSION=$(grep -o '"version":"[^"]*' /tmp/wp-latest.json | head -1 | sed 's/.*:"//')
fi

echo "Installing WordPress ${WP_VERSION} test environment"

mkdir -p "$WP_CORE_DIR" "$WP_TESTS_DIR"

if [ ! -f "${WP_CORE_DIR}wp-settings.php" ]; then
	download "https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" /tmp/wordpress.tar.gz
	tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
fi

download https://raw.githubusercontent.com/WordPress/WordPress/master/wp-content/db.php "${WP_CORE_DIR}wp-content/db.php" 2>/dev/null || true

if [ ! -d "${WP_TESTS_DIR}/includes" ]; then
	TAG="tags/${WP_VERSION}"
	svn co --quiet "https://develop.svn.wordpress.org/${TAG}/tests/phpunit/includes/" "${WP_TESTS_DIR}/includes" \
		|| svn co --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/ "${WP_TESTS_DIR}/includes"
	svn co --quiet "https://develop.svn.wordpress.org/${TAG}/tests/phpunit/data/" "${WP_TESTS_DIR}/data" \
		|| svn co --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/data/ "${WP_TESTS_DIR}/data"
fi

if [ ! -f "${WP_TESTS_DIR}/wp-tests-config.php" ]; then
	download https://develop.svn.wordpress.org/trunk/wp-tests-config-sample.php "${WP_TESTS_DIR}/wp-tests-config.php"
	SED_EXTRA=()
	if [[ $(uname -s) == 'Darwin' ]]; then
		SED_EXTRA=(-i .bak)
	else
		SED_EXTRA=(-i)
	fi
	sed "${SED_EXTRA[@]}" "s:dirname( __FILE__ ) . '/src/':'${WP_CORE_DIR}':" "${WP_TESTS_DIR}/wp-tests-config.php"
	sed "${SED_EXTRA[@]}" "s/youremptytestdbnamehere/${DB_NAME}/" "${WP_TESTS_DIR}/wp-tests-config.php"
	sed "${SED_EXTRA[@]}" "s/yourusernamehere/${DB_USER}/" "${WP_TESTS_DIR}/wp-tests-config.php"
	sed "${SED_EXTRA[@]}" "s/yourpasswordhere/${DB_PASS}/" "${WP_TESTS_DIR}/wp-tests-config.php"
	sed "${SED_EXTRA[@]}" "s|localhost|${DB_HOST}|" "${WP_TESTS_DIR}/wp-tests-config.php"
fi

if ! mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" 2>/dev/null; then
	echo "Database ${DB_NAME} already exists, or could not be created — continuing."
fi

echo
echo "Done. Run the integration suite with:"
echo "  WP_TESTS_DIR=${WP_TESTS_DIR} vendor/bin/phpunit --testsuite integration"
