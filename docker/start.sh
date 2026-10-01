#!/bin/sh
set -e

# Only one Apache MPM may be loaded (mod_php needs prefork)
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*

# Railway tells the container which port to listen on via $PORT
PORT="${PORT:-80}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Redirects (e.g. /page.php -> /page) must point at the public https URL,
# not at the container's internal http port.
if [ -n "$APP_URL" ]; then
    printf 'ServerName %s\nUseCanonicalName On\n' "$APP_URL" > /etc/apache2/conf-enabled/zz-public-url.conf
fi

exec apache2-foreground
