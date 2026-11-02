#!/bin/sh
# Gordey_ap01 — local capture, no external mail transport.
set -eu
umask 077
cat >> /var/recovery-mail/static-outbox.eml
printf '\n\n--- LOCAL RECOVERY MESSAGE ---\n' >> /var/recovery-mail/static-outbox.eml
