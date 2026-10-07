#!/usr/bin/env bash

# Opens the local-only tunnel used by the Accounting read-only worker.
#
# Required environment variables:
#   ACCOUNTING_ERP_SSH_KEY   Absolute path to the private SSH key.
#   ACCOUNTING_ERP_SSH_HOST  ERP server host/IP.
#
# Optional environment variables:
#   ACCOUNTING_ERP_SSH_PORT       Defaults to 9999.
#   ACCOUNTING_ERP_LOCAL_PG_PORT  Defaults to 15432.
#   ACCOUNTING_ERP_REMOTE_PG_HOST Defaults to 127.0.0.1.
#   ACCOUNTING_ERP_REMOTE_PG_PORT Defaults to 5432.

set -euo pipefail

: "${ACCOUNTING_ERP_SSH_KEY:?Set ACCOUNTING_ERP_SSH_KEY to the SSH key path.}"
: "${ACCOUNTING_ERP_SSH_HOST:?Set ACCOUNTING_ERP_SSH_HOST to the ERP server host.}"

accounting_ssh_port="${ACCOUNTING_ERP_SSH_PORT:-9999}"
accounting_local_pg_port="${ACCOUNTING_ERP_LOCAL_PG_PORT:-15432}"
accounting_remote_pg_host="${ACCOUNTING_ERP_REMOTE_PG_HOST:-127.0.0.1}"
accounting_remote_pg_port="${ACCOUNTING_ERP_REMOTE_PG_PORT:-5432}"

exec ssh \
    -N \
    -o ExitOnForwardFailure=yes \
    -o ServerAliveInterval=30 \
    -o ServerAliveCountMax=3 \
    -L "127.0.0.1:${accounting_local_pg_port}:${accounting_remote_pg_host}:${accounting_remote_pg_port}" \
    -p "${accounting_ssh_port}" \
    -i "${ACCOUNTING_ERP_SSH_KEY}" \
    "root@${ACCOUNTING_ERP_SSH_HOST}"
