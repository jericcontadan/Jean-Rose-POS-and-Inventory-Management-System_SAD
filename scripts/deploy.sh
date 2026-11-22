#!/usr/bin/env bash
# Deploys the newest code of the main branch. Runs ON THE SERVER (docs/07-deployment.md, section 9).
# GitHub Actions runs it after the tests pass. By hand: bash /var/www/jeanrose-pos/scripts/deploy.sh
#
# It NEVER touches the database: no migrations and no SQL files. It never reads or changes .env.

# Stop at the first error (-e), at an unset variable (-u) and at an error inside a pipe (pipefail).
set -euo pipefail

APP_DIR=/var/www/jeanrose-pos

# azureuser (deploys) and www-data (Nginx, PHP) must both be able to write in these folders.
fix_permissions() {
    sudo chown -R azureuser:www-data storage bootstrap/cache
    sudo chmod -R 775 storage bootstrap/cache
}

# Runs at the end, ALWAYS: after a good deploy and after an error. It puts the site back up.
finish() {
    local status=$?     # 0 = every step worked
    trap - EXIT
    set +e              # from here, keep going even if a command fails

    if [ "$status" -ne 0 ]; then
        echo "DEPLOY FAILED (exit code $status). Putting the site back up."
        fix_permissions # a failed artisan command may have made a log file that www-data cannot write
    fi

    if ! php artisan up; then
        # Laravel itself cannot start, so remove its "site is down" files directly.
        rm -f storage/framework/down storage/framework/maintenance.php
        if [ "$status" -eq 0 ]; then status=1; fi
    fi

    exit "$status"      # GitHub Actions shows red when this is not 0
}

# All steps are inside a function so bash reads the whole file first: git replaces this file in step 2.
main() {
    cd "$APP_DIR"
    trap finish EXIT
    fix_permissions

    # 1. Show the maintenance page. If Laravel cannot start (a broken earlier deploy), go on and repair it.
    echo "==> Maintenance mode on"
    php artisan down --retry=15 || echo "WARNING: could not turn on maintenance mode, continuing."

    # 2. Make the code exactly the same as main on GitHub. (.env and uploads are not in git, so they stay.)
    echo "==> Get the code"
    git fetch origin main
    git reset --hard origin/main

    # 3. Install the PHP packages of composer.lock, without the test tools.
    echo "==> Composer"
    composer install --no-dev --optimize-autoloader --no-interaction

    # 4. Rebuild Laravel's caches.
    echo "==> Caches"
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache

    # 5. Product images need the public/storage link. Make it only when it is missing.
    if [ ! -L public/storage ]; then
        php artisan storage:link
    fi

    # 6. Permissions again (steps 2 to 4 made new files), then let PHP load the new code.
    echo "==> Permissions and PHP reload"
    fix_permissions
    sudo systemctl reload php8.3-fpm

    echo "==> Code is now at: $(git log -1 --oneline)"
    # finish() runs now and does "php artisan up".
}

main "$@"
