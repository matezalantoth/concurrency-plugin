#!/bin/sh
# Local Docker fixture: no real users, no original database writes, no mail or HTTP.
set -eu
test_db="eds_progression_test_$(date +%s)_$$"
created=0
db_sql() {
    docker exec -i wp-db-1 sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot --init-command="SET SESSION sql_mode = 0"'
}
cleanup() {
    if [ "$created" = 1 ]; then
        echo "DROP DATABASE \`$test_db\`;" | db_sql
    fi
}
trap cleanup EXIT HUP INT TERM
echo "CREATE DATABASE \`$test_db\`;" | db_sql
created=1
for table in wp_options wp_users wp_usermeta wp_posts wp_postmeta wp_terms wp_termmeta wp_term_taxonomy wp_term_relationships wp_comments wp_commentmeta wp_learndash_user_activity wp_learndash_user_activity_meta; do
    echo "CREATE TABLE \`$test_db\`.\`$table\` LIKE wp_dev.\`$table\`;" | db_sql
done
# Only course fixtures and selected settings; never copy users, usermeta or learner activity.
db_sql <<SQL
INSERT INTO \`$test_db\`.wp_options SELECT * FROM wp_dev.wp_options WHERE option_name IN ('siteurl','home','blogname','db_version','wp_user_roles','learndash_settings_courses_builder');
INSERT INTO \`$test_db\`.wp_posts SELECT * FROM wp_dev.wp_posts WHERE post_type IN ('sfwd-courses','sfwd-lessons','sfwd-topic','sfwd-quiz','sfwd-question');
INSERT INTO \`$test_db\`.wp_postmeta SELECT m.* FROM wp_dev.wp_postmeta m JOIN \`$test_db\`.wp_posts p ON p.ID=m.post_id;
SQL
docker exec -e EDS_TEST_DB="$test_db" wp-wordpress-1 php /var/www/html/wp-content/plugins/concurrency-plugin/tests/test-daily-integration.php
