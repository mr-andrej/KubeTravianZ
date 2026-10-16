# 08 - Roadmap: from zero to running cluster

Two tracks that converge at Phase 3. Track A is manual (console/CLI, good for
learning and for understanding what the automation must do). Track B is the
deliverable (Ansible, Terraform optional). Build A first if time allows, then
encode it in B; or go straight to B and use A only when something breaks.

Rule for both tracks: nothing gets created by hand that Track B cannot
reproduce. If you click it in the console, write the playbook the same day.

## Phase 0 - Prerequisites (both tracks)

1. AWS account: verify $200 credits and their **expiry date**.
2. Create IAM user `kubetravianz-admin` (console + programmatic), scoped
   policy: EC2, VPC, EFS, S3, DLM, IAM instance profiles. Lock root with MFA.
3. Local tooling on the admin machine (WSL/Git Bash): `aws` CLI v2 configured
   with the IAM user, `ansible` (with `amazon.aws` collection), `kubectl`,
   `helm`, `docker`, Python 3.
4. Domain plan: none. Use `<eip>.sslip.io` once Elastic IPs exist.
5. Checkpoint: `aws sts get-caller-identity` returns the IAM user, not root.

## Phase 1 - AWS foundation

### Track A (manual)

1. VPC `10.0.0.0/16` + Internet Gateway + public route table.
2. Three public subnets: `10.0.1.0/24`, `10.0.2.0/24`, `10.0.3.0/24`, one per AZ.
3. Security groups per doc 02 table (default deny, explicit allows):
   `sg-controlplane`, `sg-worker`, `sg-efs`.
4. Key pair (ed25519), store the private key locally only.
5. Allocate 2 Elastic IPs.
6. Launch 3x t4g.medium Ubuntu 24.04 ARM64 (one per subnet), EBS gp3
   (20/30/30 GB), instance profile `kubetravianz-node` (create it first:
   SSM, EFS, S3, snapshot permissions).
7. Associate EIP-1 to worker-1, EIP-2 to worker-2.
8. EFS filesystem (multi-AZ mount targets in the two worker subnets, `sg-efs`).
9. S3 bucket `kubetravianz-backups-<suffix>` (private, lifecycle 30 days).
10. DLM lifecycle policy: nightly snapshots of tagged EBS volumes, keep 7.
11. Checkpoint: SSH into all three nodes; workers mount the EFS by hand once
    to prove NFS connectivity.

### Track B (Ansible, Terraform optional)

- **Ansible-only:** `infra/aws/site.yml` runs roles in order: `iam`, `vpc`,
  `security_groups`, `keypair`, `eip`, `ec2`, `efs`, `s3`, `dlm`. One
  `group_vars/aws.yml` holds region, CIDRs, instance types, AMI id, tags.
  Idempotent: re-run converges. `infra/aws/teardown.yml` destroys in reverse.
- **Terraform variant (bonus checkbox):** the same resources in
  `infra/terraform/` (VPC, SGs, EC2, EIPs, EFS, S3, DLM); Ansible then reads
  Terraform outputs for its inventory. Do this only after the Ansible-only
  path is green.
- Dynamic inventory via `amazon.aws.aws_ec2` plugin keyed on tags
  (`Role=controlplane` / `Role=worker`), so Kubespray never hardcodes IPs.
- Checkpoint: teardown, rebuild from zero, SSH works, EIPs re-attached.

## Phase 2 - Kubernetes (Track B only; A borrows the same playbooks)

1. Clone Kubespray (pinned tag), inventory at `infra/kubespray/inventory/
   kubetravianz/` fed from the dynamic inventory.
2. `ansible-playbook cluster.yml`: containerd, calico (VXLAN), Kubernetes
   1.30.x. Control plane only on cp-1.
3. Post-deploy playbook: taint cp-1, label workers (`role=ingress`),
   install `metrics-server`, copy kubeconfig to admin machine.
4. Checkpoint: `kubectl get nodes` shows 3 Ready; `kubectl get pods -A` all
   Running; a throwaway nginx Deployment + Service works.

## Phase 3 - Platform layer (tracks converge; bootstrap by hand once, then ArgoCD owns it)

Order matters; each step has a verify before moving on.

1. **storage**: efs-csi driver, StorageClass `efs`, test RWX PVC from two pods.
2. **ingress-nginx**: DaemonSet on `role=ingress` nodes, hostPort 80/443.
   Verify: `curl http://<eip-1>` returns 404 from nginx (not timeout).
3. **cert-manager** + ClusterIssuer `letsencrypt-prod` (HTTP-01). Verify:
   self-signed test cert, then a real cert on a test ingress.
4. **Sealed Secrets** controller; fetch the public key for `kubeseal`.
5. **ArgoCD**, ingress `argocd.<eip-1>.sslip.io`, admin password sealed.
6. **auth**: Keycloak (with small postgres StatefulSet), then dex with the
   Keycloak connector. Verify: OIDC login into ArgoCD via dex.
7. **observability**: Datadog agent DaemonSet (API key as sealed secret),
   Headlamp behind dex.
8. From here on, **ArgoCD owns the platform**: convert every hand-applied
   manifest into the deploy repo (app-of-apps), let ArgoCD adopt it.
9. Checkpoint: all UIs reachable over HTTPS with valid certs and dex login.

## Phase 4 - TravianZ application

1. GitHub Actions: build `linux/arm64` image, push to GHCR, bump tag in the
   deploy repo. (`php -l`, tests, `docker compose config` in CI first.)
2. Helm chart `deploy/charts/travianz/`: web Deployment (HPA 2-4, sticky
   sessions), cron singleton Deployment, MariaDB StatefulSet (primary +
   replica), EFS PVC for `GameEngine/Prevention/`, config.php ConfigMap
   rendered from values (carry over the current local `config.php` as the
   values baseline, including the enabled feature flags), sealed DB secret,
   ingress `travianz.<eip-1>.sslip.io` + same host on EIP-2.
3. Validate on **minikube locally first** (chart lint, template render,
   install, init Job completes, cron ticks, login works). This loop is free;
   AWS iterations are not.
4. DB init Job (port of `scripts/install_headless.py`): idempotent schema +
   world data + seeded accounts, Helm pre-install hook.
5. ArgoCD Application for the chart; sync; watch init Job, then web rollout.
6. MariaDB replica bootstrap Job (snapshot primary, `CHANGE REPLICATION
   SOURCE TO`), Datadog alert on `Seconds_Behind_Source`.
7. Checkpoint: register a user, build something, watch a cron tick complete
   it; admin panel reachable; newsboxes show our content.

## Phase 5 - Proof and drills (the demo ammo)

1. Load test: `scripts/load/run-k6.sh` ramps to 100 VUs; HPA scales web
   2 to 4; screenshot Datadog dashboard.
2. Failover drill: stop worker-1 (the MariaDB primary), follow the promotion
   runbook, game back in under 5 minutes. Document timings.
3. Backup/restore drill: restore last night's mysqldump into a fresh DB pod.
4. Teardown/rebuild rehearsal: `teardown.yml`, then full rebuild, game state
   restored from S3 + snapshots. Time it; that number goes in the report.
5. Security review against the checklist: SGs default deny (prove with a port
   scan from outside), no root usage, secrets never in plaintext in git.

## Phase 6 - Demo day

1. Fresh rebuild the day before (proves reproducibility, clears gremlins).
2. Runbook printed: URLs, dex logins for the teacher account, recovery steps.
3. Budget screenshot from Cost Explorer in the report.

## Sequencing notes

- Longest pole: Phase 1-2 (first Kubespray run always bites). Start early.
- Highest risk: Phase 4 steps 2-4 (config.php rendering + init Job). That is
  why the minikube loop exists; do not debug the chart on AWS.
- Parallelizable: CI pipeline (Phase 4 step 1) and minikube chart work can
  happen while the AWS foundation is being built.
