Service 101 local recovery — Gordey_ap01

This branch preserves the public production index recovered from the hosting
snapshot of 26 October 2026. Its advertising markup is kept intact. The existing
source folders and the hosting account are not overwritten by these tools.

The launcher runs two sites and a separate MySQL database with existing Docker
images. Web ports are bound to loopback. Runtime credentials, database dumps,
media, private reports and mail capture stay outside the Git worktree.

Expected local layout:
recovery-2026-11-02/
  source/                 this Git worktree
  runtime/                restored files and private local configuration
  RECOVERY-REPORT.txt      local inventory and verification details
  github-history.bundle   offline Git history

Initial preparation requires the verified local hosting backup pair. The paths
and snapshot IDs are explicit in prepare.py. Run prepare.py once, then restore.py.
Do not use initial preparation to update an already edited local instance.
restore.py records a successful database import and does not repeat it on retry.

Start existing copies: powershell -File tools/local-recovery/Start.ps1
Stop, retaining data: powershell -File tools/local-recovery/Stop.ps1
Smoke checks: python tools/local-recovery/verify.py

Static: http://127.0.0.1:8184/
WordPress: http://127.0.0.1:8185/
Admin: http://127.0.0.1:8185/wp-admin/
Local login details: ../runtime/LOCAL-ACCESS.txt

Static-site mail is captured locally. WordPress retains its original staging
form simulation. Its catalog preview includes drafts, preserving publication
statuses. The snapshot contains WordPress 7.1, PHP dependencies, uploads and
the catalog database; this does not claim to reproduce changes after the backup.

Source repository: https://github.com/Gordey-ap01/10113
WordPress source: branch feature/wordpress-migration; security snapshot also
available in codex/security-wp-101-20261026. Database contents are not in Git.

Runtime reference:
https://github.com/docker-library/docs/blob/master/wordpress/README.md
Serialization-aware database replacement:
https://developer.wordpress.org/cli/commands/search-replace/
