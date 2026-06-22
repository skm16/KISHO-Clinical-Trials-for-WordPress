#!/usr/bin/env bash
# Install the WordPress test suite using git (no svn required).
#
# Drop-in alternative to bin/install-wp-tests.sh for environments without
# Subversion (e.g. Git for Windows / MINGW64 shells under LocalWP). It
# shallow-clones the WordPress/wordpress-develop mirror, copies the PHPUnit
# test includes + data into WP_TESTS_DIR, downloads WordPress core, writes
# wp-tests-config.php, and creates the throwaway test database.
#
# Usage:
#   bash bin/install-wp-tests-git.sh <db-name> <db-user> <db-pass> [db-host] [wp-version]
#
# Example (LocalWP, MySQL on a dynamic TCP port):
#   bash bin/install-wp-tests-git.sh wordpress_test root root "127.0.0.1:10120" 6.7

if [ $# -lt 3 ]; then
    echo "Usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version]"
    exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}

WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress}

download() {
    if [ "$(which curl)" ]; then
        curl -s "$1" > "$2"
    elif [ "$(which wget)" ]; then
        wget -nv -O "$2" "$1"
    fi
}

# Resolve the develop-repo branch/tag to fetch.
#   x.y / x.y.z -> the matching tag on wordpress-develop
#   latest      -> resolved to the current stable x.y.z via the WP API
#   trunk       -> the develop trunk branch
if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]]; then
    DEVELOP_REF="$WP_VERSION"
    CORE_ARCHIVE="wordpress-$WP_VERSION"
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
    DEVELOP_REF="trunk"
    CORE_ARCHIVE="latest"
else
    download http://api.wordpress.org/core/version-check/1.7/ /tmp/wp-latest.json
    LATEST_VERSION=$(grep -o '"version":"[^"]*"' /tmp/wp-latest.json | head -1 | sed 's/"version":"//;s/"//')
    if [[ -z "$LATEST_VERSION" ]]; then
        echo "Latest WordPress version could not be found"
        exit 1
    fi
    DEVELOP_REF="$LATEST_VERSION"
    CORE_ARCHIVE="latest"
fi

set -ex

install_wp() {
    if [ -d "$WP_CORE_DIR" ]; then
        return
    fi
    mkdir -p "$WP_CORE_DIR"
    download "https://wordpress.org/${CORE_ARCHIVE}.tar.gz" /tmp/wordpress.tar.gz
    tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
    download https://raw.githubusercontent.com/markoheijnen/wp-mysqli/master/db.php "$WP_CORE_DIR/wp-content/db.php"
}

install_test_suite() {
    if [ -d "$WP_TESTS_DIR/includes" ]; then
        return
    fi
    mkdir -p "$WP_TESTS_DIR"

    # Shallow-clone only the develop ref, then copy the two phpunit subtrees.
    local TMP_DEVELOP="/tmp/wordpress-develop-$$"
    rm -rf "$TMP_DEVELOP"
    if ! git clone --depth=1 --branch "$DEVELOP_REF" https://github.com/WordPress/wordpress-develop.git "$TMP_DEVELOP"; then
        # Tag may not exist (e.g. an x.y with no x.y.0 tag); fall back to trunk.
        echo "Branch/tag '$DEVELOP_REF' not found; falling back to trunk."
        git clone --depth=1 --branch trunk https://github.com/WordPress/wordpress-develop.git "$TMP_DEVELOP"
    fi

    cp -r "$TMP_DEVELOP/tests/phpunit/includes" "$WP_TESTS_DIR/includes"
    cp -r "$TMP_DEVELOP/tests/phpunit/data" "$WP_TESTS_DIR/data"
    cp "$TMP_DEVELOP/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
    rm -rf "$TMP_DEVELOP"

    # Point the config at our core dir and DB credentials.
    # On Windows/MSYS, PHP cannot resolve MSYS paths like /tmp/wordpress — it
    # reads them relative to the current drive root (C:\tmp\...). Translate to a
    # native, forward-slash Windows path (C:/Users/.../wordpress) that PHP on
    # Windows resolves directly. cygpath is a no-op fallback on real *nix.
    local CONFIG_CORE_DIR="$WP_CORE_DIR"
    if command -v cygpath >/dev/null 2>&1; then
        CONFIG_CORE_DIR=$(cygpath -m "$WP_CORE_DIR")
    fi
    local ESCAPED_CORE_DIR
    ESCAPED_CORE_DIR=$(echo "$CONFIG_CORE_DIR" | sed "s:/:\\\/:g")
    sed -i "s:dirname( __FILE__ ) . '/src/':'$ESCAPED_CORE_DIR/':" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
    sed -i "s|'localhost'|'${DB_HOST}'|" "$WP_TESTS_DIR/wp-tests-config.php"
}

install_db() {
    # Split host into hostname + port/socket so we can talk TCP to LocalWP.
    local PARTS=(${DB_HOST//\:/ })
    local DB_HOSTNAME=${PARTS[0]}
    local DB_SOCK_OR_PORT=${PARTS[1]}
    local EXTRA=""

    if [ -n "$DB_HOSTNAME" ]; then
        if [ "$(echo "$DB_SOCK_OR_PORT" | grep -e '^[0-9]\{1,\}$')" ]; then
            EXTRA=" --host=$DB_HOSTNAME --port=$DB_SOCK_OR_PORT --protocol=tcp"
        elif [ -n "$DB_SOCK_OR_PORT" ]; then
            EXTRA=" --socket=$DB_SOCK_OR_PORT"
        else
            EXTRA=" --host=$DB_HOSTNAME --protocol=tcp"
        fi
    fi

    # Create the test DB if it doesn't already exist (ignore "exists" error).
    mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS"$EXTRA || true
}

install_wp
install_test_suite
install_db
