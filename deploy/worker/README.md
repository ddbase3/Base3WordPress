# BASE3 WordPress worker service

The worker implementation and runtime remain BASE3 Framework services. The
repeat loop, WordPress CLI entry and deployment configuration belong to
`Base3WordPress`. One PHP cycle loads the **normal WordPress and BASE3**
composition, executes the existing `Runtime::runWorker()`, and exits. The loop
starts another PHP process after one second. No second job scheduler is added.

## Production site

- Host site: `/srv/sites/base3.de/wordpress.base3.de`
- WordPress container: `docker-site-wordpress-base3-de-apache`
- Document root in container: `/var/www/html`
- BASE3 module: `wp-content/plugins/base3-wordpress/modules/Base3WordPress`
- Unit: `base3-worker-wordpress-base3-de.service`

## Scope and host scheduler

This installation adds a dedicated BASE3 worker only. It neither changes nor
removes WordPress cron hooks, schedules or native WordPress jobs. The standalone
worker CLI disables automatic WordPress-cron spawning **inside its own PHP
process only**, so each cycle runs solely the existing BASE3 worker.

The existing native integration currently also registers a BASE3-specific
`base3_worker_tick` hook with WordPress cron. This patch intentionally does not
modify that existing code or its schedule. Operators must check whether it is
already active before enabling another BASE3 execution source; both may invoke
BASE3 jobs. Any separate change to that older integration is out of scope.

## Install and enable (host root shell)

```sh
cd /srv/sites/base3.de/wordpress.base3.de
MODULE=wp-content/plugins/base3-wordpress/modules/Base3WordPress
UNIT=base3-worker-wordpress-base3-de.service

docker update --restart unless-stopped docker-site-wordpress-base3-de-apache
install -m 0644 "$MODULE/deploy/systemd/$UNIT" "/etc/systemd/system/$UNIT"
systemd-analyze verify "/etc/systemd/system/$UNIT"
systemctl daemon-reload
systemctl enable --now "$UNIT"
systemctl is-enabled "$UNIT"
systemctl is-active "$UNIT"
systemctl status "$UNIT" --no-pager -l
journalctl -u "$UNIT" -n 100 --no-pager
```

The host root shell is needed for systemd and Docker socket access, not for PHP.
The PHP process itself runs as `www-data` inside the WordPress container.
Docker must start the WordPress container on reboot. The systemd unit retries
if the container is not ready immediately after `docker.service` starts.

Manual **one-cycle** check:

```sh
docker exec -u www-data -w /var/www/html \
  -e BASE3_WORKER_HOST=wordpress.base3.de \
  -e BASE3_WORKER_DOCUMENT_ROOT=/var/www/html \
  docker-site-wordpress-base3-de-apache \
  php wp-content/plugins/base3-wordpress/modules/Base3WordPress/cron/worker.php
```

Service control:

```sh
systemctl stop base3-worker-wordpress-base3-de.service
systemctl start base3-worker-wordpress-base3-de.service
systemctl restart base3-worker-wordpress-base3-de.service
journalctl -u base3-worker-wordpress-base3-de.service -f
```

After a host reboot, verify `is-enabled`, `is-active`, `ExecStart`, the actual
container process, and one *real* job/policy execution. A green systemd state
alone is not a successful functional test. Unit changes require copying the
unit file again, `daemon-reload` and `restart`. The container-stop script
forwards termination to the loop and its current PHP child.

## Capture Docker configuration in Git

From the independent `Base3WordPress` repository on the **Docker host**:

```sh
cd /srv/sites/base3.de/wordpress.base3.de/wp-content/plugins/base3-wordpress/modules/Base3WordPress
sh deploy/docker/capture-snapshot.sh docker-site-wordpress-base3-de-apache
cat deploy/docker/wordpress-container.snapshot.txt
git add cron deploy
git commit -m "feat: add persistent wordpress worker and systemd deployment"
```

Review the generated snapshot before `git add`. It records only selected
container metadata (image, restart policy, network mode, mounts and ports).
It deliberately omits environment variables and all volumes' data. **This is
not a full Docker backup.** A restorable deployment requires the actual
reviewed Compose/stack definition and external secret storage, which cannot
be reconstructed safely from these files. Never commit `.env`, passwords,
secret mounts or complete raw `docker inspect` output.

No native WordPress integration files, framework files, project module files,
or unrelated BASE3 module sources are changed.
