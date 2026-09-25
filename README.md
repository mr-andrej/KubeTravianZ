# KubeTravianZ

A school project: run a TravianZ game server (PHP 8.3 + Apache + MariaDB) on a
self-hosted Kubernetes cluster on AWS, provisioned with Ansible and Kubespray,
managed with GitOps.

The game code is a fork of [TravianZ](https://github.com/Shadowss/TravianZ)
(upstream v11). Everything else in this repo (Docker packaging, install
automation, architecture docs, and the upcoming Kubernetes and AWS tooling) is
ours.

## Goals

- 3 EC2 instances (1 control plane + 2 workers) in a dedicated VPC, one
  subnet and one instance per availability zone
- Cluster bootstrap with Kubespray via Ansible, fully reproducible
  (one playbook to build, one to tear down)
- GitOps deployments with ArgoCD, secrets via Sealed Secrets
- SSO for all operations UIs with dex + Keycloak
- Monitoring dashboard (Datadog free tier + Headlamp)
- MariaDB primary + replica across two AZs, nightly backups to S3
- Budget: fits in $200 of AWS credits, about $105/mo at 24/7, less when paused

The full design lives in [documentation/architecture/](documentation/architecture/README.md)
and on the GitHub wiki.

## Local development (docker compose)

Requirements: Docker Desktop.

```bash
cp .env.example .env
docker compose up -d --build
```

Then either click through the installer at `http://localhost:8080/install/`
or run it headless:

```bash
python scripts/install_headless.py
```

When finished:

- Game: http://localhost:8080 (default admin login: `admin` / `adminpass`)
- phpMyAdmin: http://localhost:8081
- The installer renames `install/` to `installed_<timestamp>/` and creates
  `var/installed`. This is intended. Restore with `git restore install/` if
  you want to reinstall.
- Full reset: `docker compose down -v`, remove `var/installed`,
  `GameEngine/config.php` and `installed_*`, then start again.

### Game automation (cron)

Game ticks (battles, building completion, training) run through `cron.php`:

```bash
docker exec travianz-web php /var/www/html/cron.php --once
```

In production this runs as a dedicated singleton pod ticking every 60 seconds.

### Administering the game

Log in as the admin account and use the red ADMIN PANEL link in the sidebar
(`/Admin/admin.php`): player management, bans, gold and Plus grants, newsboxes,
config editor, quest editor, promo codes, maintenance mode, server reset.

## Repository layout

| Path | What |
|---|---|
| `GameEngine/`, `Templates/`, root `*.php` | The game itself (upstream TravianZ fork) |
| `Admin/` | In-game admin panel |
| `Dockerfile`, `docker-compose.yml`, `.env.example` | Local dev stack (PHP 8.3 Apache, MariaDB, phpMyAdmin) |
| `scripts/install_headless.py` | Headless driver for the install wizard |
| `scripts/check.sh` | Lint + tests + compose validation |
| `tests/` | PHP and shell tests |
| `documentation/architecture/` | K8s/AWS design docs (mermaid diagrams included) |
| `documentation/wiki/` | Sidebar and footer for the GitHub wiki |

## Checks

```bash
scripts/check.sh all    # php lint, unit tests, compose validation, shell tests
```

## Credits and license

Game engine: TravianZ by Dzoki, Shadow and contributors
(upstream: https://github.com/Shadowss/TravianZ). Infrastructure, packaging
and documentation in this fork are part of the KubeTravianZ school project.
Not affiliated with Travian Games.
