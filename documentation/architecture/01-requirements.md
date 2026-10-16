# 01 - Requirements mapping

Every checkbox from the assignment, mapped to the component that satisfies it
and the doc that describes it.

## Mandatory

| Requirement | Component | Doc |
|---|---|---|
| VPC | Dedicated VPC `10.0.0.0/16`, no default VPC use | 02 |
| 3 subnets, 1 instance each | 3 public subnets across 3 AZs, one EC2 per subnet | 02 |
| Default-deny security groups | SG per role (cp / worker), explicit allows only | 02 |
| IAM, not root | IAM user + role for EC2 (SSM, EFS, S3, snapshots); root untouched | 02 |
| 3 EC2: 1 control plane + 2 workers | t4g.medium x3 (ARM64, all on-demand), workers carry 2 Elastic IPs | 02 |
| Ansible / Kubespray | Kubespray playbook from admin machine (`infra/`) | 02 |
| Load-scaling scripts | HPA on web + k6 load script + manual worker-join playbook | 07 |
| DEX everywhere | dex fronts ArgoCD and Headlamp; Keycloak as user store | 06 |
| Monitoring dashboard | Datadog (free tier) + Headlamp for cluster | 07 |

## Bonus

| Requirement | Component | Doc |
|---|---|---|
| Terraform (IaC) | Optional re-implementation of VPC/EC2 after Ansible path works | 02 |
| Multi-AZ / multi-region | Subnets span 3 AZs; multi-region is out of budget, documented only | 02 |
| DB replica | MariaDB replica pod in the second AZ (async binlog), included in the HA build, manual failover runbook | 04 |
| Persistence | EBS gp3 PVCs for DB, EFS for shared locks/assets | 04 |
| Snapshots | Nightly EBS snapshot lifecycle + mysqldump to S3 | 07 |

## Application realities discovered during local testing

These come from actually running the stack (docker compose), not from reading
docs. They are constraints the architecture must respect:

1. **cron.php is the game's heartbeat.** Battles, builds, training all advance
   on a ~60 s automation tick. If cron stops, the game freezes. See 04.
2. **cron and web pods must share `GameEngine/Prevention/`.** The cron writes
   `cron_active.txt` every tick; web requests check its freshness (< 90 s) to
   decide whether *they* must run automation as fallback. Per-pod filesystems
   mean duplicate automation and broken `flock` mutexes. EFS, see 04.
3. **The install wizard is stateful and self-destructing.** It writes
   `GameEngine/config.php` (585 lines of constants), renames `install/` away,
   touches `var/installed`. Never run it in the cluster. See 05.
4. **PHP sessions are on-disk per container.** Multi-replica needs sticky
   sessions or an external handler. See 04.
5. **Liveness must not depend on the DB.** A DB hiccup must not cascade-restart
   web pods (liveness is a static file; readiness may check DB). See 04.
6. **UID discipline.** The image must run web and cron as the same non-root UID
   (local bug: cron-as-root created lock files Apache could not open). See 04.
