#!/bin/sh
set -eu

HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
MODULE=$(CDPATH= cd -- "$HERE/../.." && pwd)
RUN_SCRIPT="$MODULE/deploy/docker/worker-run.sh"
PIDFILE="${BASE3_WORKER_PIDFILE:-/tmp/base3-wordpress-worker.pid}"

if [ ! -f "$PIDFILE" ]; then
	exit 0
fi

PID=$(cat "$PIDFILE")
case "$PID" in
	''|*[!0-9]*) echo "BASE3 worker: invalid pidfile" >&2; exit 1 ;;
esac
if [ ! -r "/proc/$PID/cmdline" ]; then
	rm -f "$PIDFILE"
	exit 0
fi
COMMAND=$(tr '\000' ' ' < "/proc/$PID/cmdline")
case "$COMMAND" in
	*"$RUN_SCRIPT"*) ;;
	*) echo "BASE3 worker: pidfile does not belong to this module" >&2; exit 1 ;;
esac

kill -TERM "$PID"
COUNT=0
while kill -0 "$PID" 2>/dev/null; do
	COUNT=$((COUNT + 1))
	if [ "$COUNT" -ge 75 ]; then
		echo "BASE3 worker: timed out while stopping container worker" >&2
		exit 1
	fi
	sleep 1
done
