## KubeTravianZ

TravianZ on self-hosted Kubernetes (Kubespray on AWS EC2)

---

### Architecture

| # | Doc | Scope |
|---|-----|-------|
| 01 | [Requirements mapping](Requirements-Mapping) | Teacher's checklist to component |
| 02 | [AWS infrastructure](AWS-infrastructure) | VPC, subnets, EC2, SGs, IAM, Kubespray |
| 03 | [Kubernetes platform](Kubernetes-platform) | ingress (hostPort), cert-manager, sslip.io, namespaces |
| 04 | [TravianZ workloads](Application-workloads) | web, cron, MariaDB, sessions, shared lock dir, probes |
| 05 | [GitOps, secrets and install automation](GitOps,-secrets-&-install-automation) | ArgoCD, Sealed Secrets, config.php templating, DB init Job |
| 06 | [Auth / SSO](Authentication-&-SSO) | dex + Keycloak, what gets protected |
| 07 | [Observability, backup and budget](Observability,-backups-&-budget) | Datadog, Headlamp, backup CronJob, cost math |

---

### Key decisions

- **Cluster:** Kubespray, containerd, calico, 3x t4g.medium (ARM64), all on-demand
- **Ingress:** ingress-nginx `hostPort` 80/443 + 2 Elastic IPs, no AWS LB
- **TLS/DNS:** cert-manager + Let's Encrypt + sslip.io
- **Game cron:** singleton Deployment, 60 s ticks (not CronJob)
- **Shared locks:** EFS RWX at `GameEngine/Prevention/`
- **DB:** MariaDB primary + replica across 2 AZs, EBS gp3
- **Config:** `config.php` as rendered ConfigMap, wizard is dev-only
- **Secrets:** Sealed Secrets
- **SSO:** dex + Keycloak

---

### Budget

Around $105/mo all-in at 24/7 on $200 credits.
Paused during dead weeks: roughly $38 to $60/mo.
Details in [doc 07](Observability,-backups-&-budget).
