# ERP read-only staging

The Accounting web page at `/integrasi/staging` only reads snapshots already
stored in its own MySQL database. It never connects to an ERP or SQL Server
source during a browser request.

An authorised operator opens the local-only PostgreSQL tunnel in one terminal:

```bash
export ACCOUNTING_ERP_SSH_KEY='/absolute/path/to/key'
export ACCOUNTING_ERP_SSH_HOST='erp-server-host'
./scripts/open-erp-staging-tunnel.sh
```

With that terminal still running, a second authorised terminal can stage a
bounded snapshot:

```bash
php artisan accounting:stage-erp --entity=BMM --dataset=all --limit=100
php artisan accounting:stage-erp --entity=TMP --dataset=all --limit=100
```

The worker accepts only the reviewed datasets `customers`, `sales_invoices`,
`payments`, and `returns`; it uses PostgreSQL read-only transactions and a
fixed 15-second source query limit. `payments` are labelled as Mobile Sales
claims, not final Finance receipts.

Closing the SSH tunnel stops future pulls but does not affect snapshots that
are already visible in Accounting.
