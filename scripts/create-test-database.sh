#!/bin/sh
set -eu
# Executed inside the MariaDB container; the password is not printed or in argv.
export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
mariadb -uroot -e "CREATE DATABASE IF NOT EXISTS sentinel_testing; GRANT ALL ON sentinel_testing.* TO 'sentinel'@'%';"
