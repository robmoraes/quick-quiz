#!/bin/sh
set -eu

runtime_config=/tmp/quickquiz-fpm-runtime.conf

cat > "$runtime_config" <<EOF
[www]
pm.max_children = ${MANAGER_FPM_MAX_CHILDREN:-8}
pm.start_servers = ${MANAGER_FPM_START_SERVERS:-2}
pm.min_spare_servers = ${MANAGER_FPM_MIN_SPARE_SERVERS:-1}
pm.max_spare_servers = ${MANAGER_FPM_MAX_SPARE_SERVERS:-3}
EOF

php-fpm -t
exec php-fpm -F
