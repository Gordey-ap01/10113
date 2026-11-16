# poisk — Gordey_ap01

- This application is independent of the production site and WordPress staging.
- Deploy ONLY this `poisk/` subtree to `poisk-service101`. Never upload repository-root files.
- Keep credentials, sessions, databases, logs and supplier cookies outside public_html and Git.
- Preserve exact author signature `Gordey_ap01`.
- Compare supplier part prices only. The master determines repair labor independently; do not add labor rates, repair totals or a repair price list.
- Code deployment is from branch `feature/poisk-service101` through its dedicated workflow.
- Never modify root-domain SSL, DNS, PHP configuration, or either existing site's files/database.
