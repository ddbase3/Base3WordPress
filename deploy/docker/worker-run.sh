#!/bin/sh
set -eu

HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
MODULE=$(CDPATH= cd -- "$HERE/../.." && pwd)
LOOP="$MODULE/cron/worker-loop.sh"
ENTRY="$MODULE/cron/worker.php"
PHP_BIN="${BASE3_WORKER_PHP_BIN:-/usr/local/bin/php}"
PIDFILE="${BASE3_WORKER_PIDFILE:-/tmp/base3-wordpress-worker.pid}"

if [ ! -r "$LOOP" ] || [ ! -r "$ENTRY" ] || [ ! -x "$PHP_BIN" ]; then
	echo "BASE3 worker: module loop, PHP binary or CLI entry not available" >&2
	exit 66
fi

if [ -e "$PIDFILE" ]; then
	OLD_PID=$(cat "$PIDFILE")
	case "$OLD_PID" in
		''|*[!0-9]*) echo "BASE3 worker: invalid existing pidfile" >&2; exit 1 ;;
	esac
	if [ -r "/proc/$OLD_PID/cmdline" ]; then
		OLD_COMMAND=$(tr '\000' ' ' < "/proc/$OLD_PID/cmdline")
		case "$OLD_COMMAND" in
			*"$MODULE/deploy/docker/worker-run.sh"*)
				echo "BASE3 worker: another worker process is already active" >&2
				exit 1
				;;
		esac
	fi
fi

umask 077
printf '%s\n' "$$" > "$PIDFILE.new.$$"
mv -f "$PIDFILE.new.$$" "$PIDFILE"
CHILD_PID=''
cleanup() {
	if [ -f "$PIDFILE" ] && [ "$(cat "$PIDFILE")" = "$$" ]; then
		rm -f "$PIDFILE"
	fi
}
stop_worker() {
	trap - TERM INT
	if [ -n "$CHILD_PID" ]; then
		kill -TERM "$CHILD_PID" 2>/dev/null || true
		wait "$CHILD_PID" 2>/dev/null || true
	fi
	cleanup
	exit 0
}
trap cleanup EXIT
trap stop_worker TERM INT

/bin/sh "$LOOP" "$PHP_BIN" "$ENTRY" &
CHILD_PID=$!
STATUS=0
wait "$CHILD_PID" || STATUS=$?
CHILD_PID=''
exit "$STATUS"
