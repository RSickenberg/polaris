#!/bin/sh
# Runs the Messenger worker of the "worker" service.
#
# The worker does not install vendors nor run migrations: compose starts it once the
# "php" service is healthy, and the "php" service does both.
set -e

if php bin/console list --raw messenger 2>/dev/null | grep -q '^messenger:consume '; then
	# Stop regularly so that the container restarts with fresh code and memory.
	exec php bin/console messenger:consume --all --time-limit=3600 --memory-limit=256M -vv
fi

# symfony/messenger is not installed yet: stay idle instead of restarting in a loop.
echo 'symfony/messenger is not installed, the worker has nothing to consume and stays idle.'
exec sleep infinity
