# poisk — Gordey_ap01

- This application is independent of the production site and WordPress staging.
- Deploy ONLY this `poisk/` subtree to `poisk-service101`. Never upload repository-root files.
- Keep credentials, sessions, databases, logs and supplier cookies outside public_html and Git.
- Preserve exact author signature `Gordey_ap01`.
- Compare supplier part prices only. The master determines repair labor independently; do not add labor rates, repair totals or a repair price list.
- Prioritize confirmed stock in Komsomolsk-on-Amur. GreenSpark shop 249529 (Lenina 4) must be selected and verified; never present another city's stock as local.
- Code deployment is from branch `feature/poisk-service101` through its dedicated workflow.
- Never modify either existing site's files/database or PHP configuration. On 16.11.2026 the owner explicitly expanded SSL work to service101.рф and its used subdomains; certificate configuration and DNS changes strictly required for that certificate are authorized. Do not otherwise change existing-site DNS or settings.
