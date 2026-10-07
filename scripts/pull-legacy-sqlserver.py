#!/usr/bin/env python3
"""Read bounded legacy snapshots on the audit host; output contains no credentials."""

import argparse
from datetime import date, datetime
from decimal import Decimal
import json
import subprocess
import sys


SOURCES = {
    "bmm_solo": ("BMM", "SLO"),
    "tmp_solo": ("TMP", "SLO"),
    "bmm_klaten": ("BMM", "KLT"),
    "tmp_klaten": ("TMP", "KLT"),
    "bmm_wonogiri": ("BMM", "WNG"),
    "tmp_wonogiri": ("TMP", "WNG"),
}

# Only reviewed tables and columns are eligible. No query or identifier is
# accepted from the web form, the audit configuration, or a source row.
DATASETS = {
    "customers": {
        "table": "Customer",
        "key": "customer_code",
        "date": None,
        "columns": {
            "customer_code": ("Kode",),
            "customer_name": ("Nama", "NamaCustomer"),
            "address": ("Alamat", "Alamat1"),
            "city": ("Kota",),
            "sales_code": ("KodeSales", "Sales"),
            "status": ("Active", "Status", "Aktif"),
        },
        "required": ("customer_code", "customer_name"),
    },
    "sales_invoices": {
        "table": "HJualSM",
        "key": "invoice_number",
        "date": "invoice_date",
        "columns": {
            "invoice_number": ("Nota", "NoNota", "No_Faktur"),
            "invoice_date": ("Tanggal", "Tgl"),
            "customer_code": ("KodeCustomer", "Customer"),
            "sales_code": ("KodeSales", "Sales"),
            "invoice_amount": ("TotalPenjualan",),
            "return_amount": ("TotalRetur",),
            "paid_amount": ("Terbayar",),
            "due_date": ("JatuhTempo", "TglJatuhTempo"),
            "document_status": ("StNota",),
            "cancelled_at": ("TglBatal",),
        },
        "required": ("invoice_number", "invoice_date", "customer_code", "invoice_amount"),
    },
    "payments": {
        "table": "HBayarSM",
        "key": "receipt_number",
        "date": "payment_date",
        "columns": {
            "receipt_number": ("NoKwitansi", "NoBukti", "No_Bukti"),
            "payment_date": ("Tanggal", "Tgl"),
            "customer_code": ("KodeCustomer", "Customer"),
            "payment_amount": ("JumlahBayar", "TotalBayar", "TotalPembayaran", "Jumlah", "Total"),
            "cash_amount": ("Cash",),
            "noncash_amount": ("NonCash",),
            "other_amount": ("Lain",),
        },
        "required": ("receipt_number", "payment_date", "customer_code", "payment_amount"),
    },
    "returns": {
        "table": "HReturSM",
        "key": "return_number",
        "date": "return_date",
        "columns": {
            "return_number": ("Nota", "NoNota", "No_Retur"),
            "return_date": ("Tanggal", "Tgl"),
            "invoice_number": ("NotaSM",),
            "customer_code": ("KodeCustomer", "Customer"),
            "return_amount": ("TotalRetur",),
            "document_status": ("StNota",),
            "cancelled_at": ("TglBatal",),
        },
        "required": ("return_number", "return_date", "return_amount"),
    },
}


def audit_source(php, config_path, source_key):
    result = subprocess.run(
        [php, "-r", '$c=require $argv[1]; echo json_encode($c["sql_servers"], JSON_THROW_ON_ERROR);', config_path],
        capture_output=True,
        text=True,
        timeout=8,
        check=True,
    )
    sources = json.loads(result.stdout)
    matches = [source for source in sources if source.get("key") == source_key]
    if len(matches) != 1:
        raise ValueError("Invalid source configuration")
    return matches[0]


def snapshot_query(dataset, source_columns, limit):
    if dataset not in DATASETS or isinstance(limit, bool) or not isinstance(limit, int) or not 1 <= limit <= 100:
        raise ValueError("Invalid snapshot request")
    spec = DATASETS[dataset]
    available = {column.lower() for column in source_columns}
    mapped = {
        alias: next((column for column in candidates if column.lower() in available), None)
        for alias, candidates in spec["columns"].items()
    }
    if any(mapped[alias] is None for alias in spec["required"]):
        raise ValueError("Source schema does not match the approved dataset")
    fields = ", ".join(f"[{column}] AS [{alias}]" for alias, column in mapped.items() if column)
    order = f'[{mapped[spec["key"]]}]'
    if spec["date"]:
        order = f'[{mapped[spec["date"]]}] DESC, {order} DESC'
    else:
        order += " ASC"
    return f'SELECT TOP {limit} {fields} FROM [dbo].[{spec["table"]}] ORDER BY {order}'


def scalar(value):
    if isinstance(value, datetime):
        return value.isoformat(sep=" ")
    if isinstance(value, date):
        return value.isoformat()
    if isinstance(value, Decimal):
        return str(value)
    if isinstance(value, str):
        return value.strip()
    if value is None or isinstance(value, (int, float, bool)):
        return value
    raise ValueError("Unexpected source value")


def read_snapshot(source, source_key, dataset, limit, connector):
    if source_key not in SOURCES or dataset not in DATASETS or isinstance(limit, bool) or not isinstance(limit, int) or not 1 <= limit <= 100:
        raise ValueError("Invalid snapshot request")
    entity, branch = SOURCES[source_key]
    spec = DATASETS[dataset]
    conn = connector(
        server=source["host"],
        port=int(source["port"]),
        user=source["username"],
        password=source["password"],
        database=source["database"],
        charset="UTF-8",
        tds_version=str(source.get("tds_version") or "7.0"),
        login_timeout=8,
        timeout=12,
        appname="Budimas Accounting staging reader",
        read_only=True,
        autocommit=False,
        as_dict=True,
    )
    try:
        cursor = conn.cursor()
        cursor.execute(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION",
            ("dbo", spec["table"]),
        )
        columns = [row["COLUMN_NAME"] for row in cursor.fetchall()]
        cursor.execute(snapshot_query(dataset, columns, limit))
        rows = cursor.fetchall()
        if len(rows) > limit:
            raise ValueError("Source returned too many rows")
        records = []
        keys = set()
        for row in rows:
            key = scalar(row.get(spec["key"]))
            if key is None or not str(key).strip() or str(key) in keys:
                raise ValueError("Missing or duplicate source identifier")
            keys.add(str(key))
            payload = {alias: scalar(value) for alias, value in row.items() if alias in spec["columns"]}
            payload.update({
                "source_key": source_key,
                "source_database": source["database"],
                "source_table": "dbo." + spec["table"],
                "company_code": entity,
                "branch_code": branch,
                "document_scope": "customer_master" if dataset == "customers" else "document_header",
            })
            records.append({
                "external_key": str(key),
                "occurred_at": payload.get(spec["date"]) if spec["date"] else None,
                "payload": payload,
            })
        return {
            "source_key": source_key,
            "entity_code": entity,
            "branch_code": branch,
            "dataset": dataset,
            "records": records,
        }
    finally:
        try:
            conn.rollback()
        finally:
            conn.close()


def main():
    parser = argparse.ArgumentParser(description="Read an approved SQL Server snapshot")
    parser.add_argument("--config", required=True)
    parser.add_argument("--php", required=True)
    parser.add_argument("--source", choices=tuple(SOURCES), required=True)
    parser.add_argument("--dataset", choices=tuple(DATASETS), required=True)
    parser.add_argument("--limit", type=int, choices=range(1, 101), required=True)
    args = parser.parse_args()
    try:
        import pymssql

        source = audit_source(args.php, args.config, args.source)
        result = read_snapshot(source, args.source, args.dataset, args.limit, pymssql.connect)
        print(json.dumps(result, ensure_ascii=False, allow_nan=False))
        return 0
    except Exception:
        print(json.dumps({"error": "Sumber SQL Server belum dapat dibaca. Periksa koneksi cabang dan konfigurasi aplikasi audit."}))
        return 1


if __name__ == "__main__":
    sys.exit(main())
