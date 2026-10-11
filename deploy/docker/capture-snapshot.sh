#!/bin/sh
set -eu

# Deliberately record non-secret container configuration only.
CONTAINER="${1:-docker-site-wordpress-base3-de-apache}"
HERE=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
TARGET="$HERE/wordpress-container.snapshot.txt"

{
	printf '# Docker configuration inventory: %s\n' "$CONTAINER"
	printf '# NO environment, container files, credentials, secrets or volume contents.\n'
	printf 'name: '
	docker inspect --format '{{.Name}}' "$CONTAINER"
	printf 'image_reference: '
	docker inspect --format '{{.Config.Image}}' "$CONTAINER"
	printf 'image_id: '
	docker inspect --format '{{.Image}}' "$CONTAINER"
	printf 'restart_policy: '
	docker inspect --format '{{.HostConfig.RestartPolicy.Name}}' "$CONTAINER"
	printf 'network_mode: '
	docker inspect --format '{{.HostConfig.NetworkMode}}' "$CONTAINER"
	printf 'mounts:\n'
	docker inspect --format '{{range .Mounts}}{{printf "%s -> %s (%s)\n" .Source .Destination .Type}}{{end}}' "$CONTAINER"
	printf 'port_bindings:\n'
	docker inspect --format '{{range $port, $bindings := .HostConfig.PortBindings}}{{printf "%s " $port}}{{range $bindings}}{{printf "%s:%s " .HostIp .HostPort}}{{end}}{{println}}{{end}}' "$CONTAINER"
} > "$TARGET.new.$$"
mv "$TARGET.new.$$" "$TARGET"
printf 'Configuration inventory written: %s\n' "$TARGET"
printf 'Review before committing. Not a full backup or a Compose definition.\n'
