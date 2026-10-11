#!/bin/sh
set -eu

if [ "$#" -ne 2 ]; then
	echo "usage: worker-loop.sh PHP_BINARY WORKER_PHP" >&2
	exit 64
fi

PHP_BIN="$1"
WORKER_PHP="$2"
if [ ! -x "$PHP_BIN" ] || [ ! -f "$WORKER_PHP" ]; then
	echo "BASE3 worker: PHP binary or WordPress CLI entry not available" >&2
	exit 66
fi

CHILD_PID=''
stop_worker() {
	trap - TERM INT
	if [ -n "$CHILD_PID" ]; then
		kill -TERM "$CHILD_PID" 2>/dev/null || true
		wait "$CHILD_PID" 2>/dev/null || true
	fi
	exit 0
}
trap stop_worker TERM INT

while :; do
	"$PHP_BIN" "$WORKER_PHP" &
	CHILD_PID=$!
	STATUS=0
	wait "$CHILD_PID" || STATUS=$?
	CHILD_PID=''
	if [ "$STATUS" -ne 0 ]; then
		echo "BASE3 worker: PHP cycle exited with status $STATUS" >&2
		exit "$STATUS"
	fi

	sleep 1 &
	CHILD_PID=$!
	wait "$CHILD_PID" || true
	CHILD_PID=''
done
