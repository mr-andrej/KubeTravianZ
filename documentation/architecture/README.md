# KubeTravianZ - Architecture

Target: run TravianZ (PHP 8.3 + Apache + MariaDB legacy game) on a self-built
Kubernetes cluster on AWS, satisfying the course requirements (VPC, 3 EC2,
Ansible/Kubespray, default-deny SGs, DEX SSO, monitoring, load-scaling,
$200 budget) with bonus tracks (multi-AZ, DB backups, IaC) where cheap.

Read in order. Each file carries its own Mermaid diagram(s): GitHub renders
them, and the raw blocks can be pasted into any Mermaid renderer for
high-res export.

| # | Doc | Scope |
|---|-----|-------|
| 01 | [Requirements mapping](01-requirements.md) | Teacher's checklist mapped to components |
| 02 | [AWS infrastructure](02-aws-infrastructure.md) | VPC, subnets, EC2, SGs, IAM, Kubespray |
| 03 | [Kubernetes platform](03-kubernetes-platform.md) | ingress (hostPort), cert-manager, sslip.io, namespaces |
| 04 | [TravianZ workloads](04-application-workloads.md) | web, cron, MariaDB, sessions, shared lock dir, probes |
| 05 | [GitOps, secrets and install automation](05-gitops-secrets-install.md) | ArgoCD, Sealed Secrets, config.php templating, DB init Job |
| 06 | [Auth / SSO](06-auth-sso.md) | dex + Keycloak, what gets protected |
| 07 | [Observability, backup and budget](07-ops-backup-budget.md) | Datadog, Headlamp, backup CronJob, cost math |
| 08 | [Roadmap](08-roadmap.md) | Step-by-step build order, manual and Ansible tracks |

## Decisions at a glance (proposed)

| Decision | Choice | Why | Alternatives rejected |
|---|---|---|---|
| Cluster bootstrap | Kubespray via Ansible | Mandatory (teacher) | k3s, EKS (cost, requirement) |
| Ingress exposure | ingress-nginx `hostPort` 80/443 on workers | No AWS LB saves ~$18/mo | NLB/ELB (cost) |
| TLS / DNS | cert-manager + Let's Encrypt + sslip.io | Free, works with any IP | Route53 zone (cost, none owned) |
| Game cron | Singleton Deployment (1 replica, loop) | 1-min tick; CronJob pod/min wasteful and risks overlap | K8s CronJob, sidecar in web |
| Shared state dir | EFS (RWX) mounted at `GameEngine/Prevention/` | Web pods + cron must see the same cron marker and flocks | 1 web replica (breaks scaling req) |
| Sessions | Sticky sessions at ingress (cookie affinity) | Zero code change | Redis session handler (v2) |
| Database | MariaDB StatefulSet (primary + replica), EBS gp3 PVCs | In-cluster is free; game is query-heavy but tiny data; replica gives HA | RDS (cost) |
| Config management | `config.php` rendered from template via ConfigMap | Install wizard is anti-GitOps (writes files, self-deletes) | Running wizard in prod |
| DB initialization | Idempotent init Job (port of `scripts/install_headless.py`) | Reproducible, CI-testable | Manual wizard clicks |
| Secrets | Sealed Secrets (Bitnami) | GitOps-safe, no external service | Vault (weight), SOPS (key mgmt) |
| SSO | dex as hub, Keycloak as identity store | Teacher requires DEX; Keycloak is the user DB | dex static users only (thin demo) |
| Monitoring | Datadog free tier + Headlamp | Teacher requires dashboard; 5 hosts free | Prometheus+Grafana (RAM on 4GB nodes) |
| Backups | mysqldump CronJob to EFS to S3, EBS snapshots nightly | Bonus track, cheap | none |
| Node sizing | 3x t4g.medium (ARM64), all on-demand, multi-AZ | Reliability-first: no spot interruptions; ~$105/mo at 24/7, less when paused | Spot workers (interruption risk), t3.medium (x86, pricier) |
| Ingress IPs | 2 Elastic IPs pinned to workers | Public endpoints never change; certs/DNS immortal across restarts | Ephemeral IPs + sslip re-render (friction on resume) |
| Database HA | MariaDB primary + replica, one per AZ | Survives worker/AZ loss; manual failover runbook | Single DB pod (single point of failure) |
