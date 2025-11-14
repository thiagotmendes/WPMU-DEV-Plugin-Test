#!/usr/bin/env bash

# Lightweight clone of the WP-CLI scaffolded installer for the WordPress test suite.

set -e

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

TMPDIR=${TMPDIR:-/tmp}
WP_CLI_CACHE_DIR="${WP_CLI_CACHE_DIR-${TMPDIR}/wp-cli-cache}"

WP_TESTS_DIR=${WP_TESTS_DIR-${TMPDIR}/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-${TMPDIR}/wordpress/}

download() {
	if command -v curl >/dev/null; then
		curl -s "$1" > "$2"
	elif command -v wget >/dev/null; then
		wget -q -O "$2" "$1"
	else
		echo "Neither curl nor wget are available. Please install one to continue."
		exit 1
	fi
}

mkdir -p "$WP_CLI_CACHE_DIR" "$WP_CORE_DIR"

install_wp() {
	local ARCHIVE_NAME

	if [ "$WP_VERSION" = 'latest' ]; then
		ARCHIVE_NAME='latest'
	else
		ARCHIVE_NAME="wordpress-$WP_VERSION"
	fi

	if [ ! -f "$WP_CLI_CACHE_DIR/$ARCHIVE_NAME.tar.gz" ]; then
		download "https://wordpress.org/${ARCHIVE_NAME}.tar.gz" "$WP_CLI_CACHE_DIR/$ARCHIVE_NAME.tar.gz"
	fi

	if [ ! -d "$WP_CORE_DIR" ] || [ ! -f "$WP_CORE_DIR/wp-load.php" ]; then
		tar --strip-components=1 -zxmf "$WP_CLI_CACHE_DIR/$ARCHIVE_NAME.tar.gz" -C "$WP_CORE_DIR"
	fi
}

install_test_suite() {
	if ! command -v svn >/dev/null; then
		echo "Subversion is required to download the WordPress test suite."
		exit 1
	fi

	mkdir -p "$WP_TESTS_DIR"

	local WP_TESTS_TAG='trunk'

	if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]]; then
		WP_TESTS_TAG="tags/$WP_VERSION"
	fi

	svn export --force "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
	svn export --force "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data" "$WP_TESTS_DIR/data"

	cat > "$WP_TESTS_DIR/wp-tests-config.php" <<EOF
<?php
define( 'DB_NAME', '${DB_NAME}' );
define( 'DB_USER', '${DB_USER}' );
define( 'DB_PASSWORD', '${DB_PASS}' );
define( 'DB_HOST', '${DB_HOST}' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define( 'WP_DEBUG', true );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
\$table_prefix = 'wptests_';
define( 'ABSPATH', '${WP_CORE_DIR}' );
EOF
}

install_db() {
	if [ "$SKIP_DB_CREATE" = "true" ]; then
		return 0
	fi

	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" --silent || true
}

install_wp
install_test_suite
install_db
