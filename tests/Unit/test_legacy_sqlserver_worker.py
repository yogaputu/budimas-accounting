import contextlib
from datetime import datetime
from decimal import Decimal
import importlib.util
import io
import json
from pathlib import Path
import sys
import types
import unittest
from unittest.mock import Mock, patch


spec = importlib.util.spec_from_file_location(
    "legacy_worker", Path(__file__).resolve().parents[2] / "scripts/pull-legacy-sqlserver.py"
)
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)


class LegacySqlServerWorkerTest(unittest.TestCase):
    def source(self):
        return {"host": "db.internal", "port": 1433, "username": "reader", "password": "never-exposed", "database": "DIST"}

    def connection(self, columns, rows):
        conn = Mock()
        cursor = conn.cursor.return_value
        cursor.fetchall.side_effect = [[{"COLUMN_NAME": column} for column in columns], rows]
        return conn, cursor

    def test_customer_snapshot_uses_read_only_connection_and_only_approved_fields(self):
        conn, cursor = self.connection(["Kode", "Nama", "Active", "Password"], [
            {"customer_code": "C01  ", "customer_name": "Toko", "status": True, "Password": "never-exposed"}
        ])
        connect = Mock(return_value=conn)
        result = worker.read_snapshot(self.source(), "tmp_klaten", "customers", 1, connect)
        self.assertEqual((result["entity_code"], result["branch_code"]), ("TMP", "KLT"))
        self.assertEqual(result["records"][0]["external_key"], "C01")
        self.assertEqual(result["records"][0]["payload"]["source_key"], "tmp_klaten")
        self.assertNotIn("never-exposed", json.dumps(result))
        self.assertTrue(connect.call_args.kwargs["read_only"])
        self.assertFalse(connect.call_args.kwargs["autocommit"])
        self.assertTrue(all(call.args[0].startswith("SELECT ") for call in cursor.execute.call_args_list))
        self.assertIn("TOP 1", cursor.execute.call_args.args[0])
        conn.commit.assert_not_called()
        conn.rollback.assert_called_once()
        conn.close.assert_called_once()

    def test_document_snapshots_preserve_money_dates_and_document_identity(self):
        cases = [
            ("sales_invoices", ["Nota", "Tanggal", "KodeCustomer", "TotalPenjualan"], "invoice_number", "invoice_date", "invoice_amount", "HJualSM"),
            ("payments", ["NoKwitansi", "Tanggal", "KodeCustomer", "JumlahBayar"], "receipt_number", "payment_date", "payment_amount", "HBayarSM"),
            ("returns", ["Nota", "Tanggal", "TotalRetur"], "return_number", "return_date", "return_amount", "HReturSM"),
        ]
        for dataset, columns, key, date_key, amount, table in cases:
            with self.subTest(dataset=dataset):
                row = {key: "DOC-1", date_key: datetime(2026, 9, 28, 10, 30), amount: Decimal("9999999999.99")}
                conn, cursor = self.connection(columns, [row])
                result = worker.read_snapshot(self.source(), "bmm_solo", dataset, 100, Mock(return_value=conn))
                record = result["records"][0]
                self.assertEqual(record["occurred_at"], "2026-09-28 10:30:00")
                self.assertEqual(record["payload"][amount], "9999999999.99")
                self.assertEqual(record["payload"]["source_table"], "dbo." + table)
                self.assertEqual(record["payload"]["document_scope"], "document_header")
                self.assertIn("DESC", cursor.execute.call_args.args[0])

    def test_invalid_request_is_rejected_before_connecting(self):
        for source, dataset, limit in [("other", "customers", 1), ("bmm_solo", "dbo.Users", 1), ("bmm_solo", "customers", 101), ("bmm_solo", "customers", True)]:
            with self.subTest(source=source, dataset=dataset, limit=limit):
                connect = Mock()
                with self.assertRaises(ValueError):
                    worker.read_snapshot(self.source(), source, dataset, limit, connect)
                connect.assert_not_called()

    def test_unknown_schema_cannot_expand_the_projection(self):
        conn, cursor = self.connection(["Unknown", "Password", "Kode]; DROP TABLE Customer;--"], [])
        with self.assertRaises(ValueError):
            worker.read_snapshot(self.source(), "bmm_solo", "customers", 1, Mock(return_value=conn))
        self.assertEqual(cursor.execute.call_count, 1)
        conn.rollback.assert_called_once()
        conn.close.assert_called_once()

    def test_duplicate_identifiers_abort_the_snapshot(self):
        rows = [{"customer_code": "C01", "customer_name": "One"}, {"customer_code": "C01 ", "customer_name": "Two"}]
        conn, _ = self.connection(["Kode", "Nama"], rows)
        with self.assertRaises(ValueError):
            worker.read_snapshot(self.source(), "bmm_solo", "customers", 2, Mock(return_value=conn))
        conn.rollback.assert_called_once()
        conn.close.assert_called_once()

    def test_query_failure_rolls_back_and_closes_the_connection(self):
        conn = Mock()
        conn.cursor.return_value.execute.side_effect = RuntimeError("source failure")
        with self.assertRaises(RuntimeError):
            worker.read_snapshot(self.source(), "bmm_solo", "customers", 1, Mock(return_value=conn))
        conn.rollback.assert_called_once()
        conn.close.assert_called_once()

    def test_main_does_not_expose_configuration_errors(self):
        output = io.StringIO()
        args = ["worker", "--config", "/audit/config.php", "--php", "/usr/bin/php", "--source", "bmm_solo", "--dataset", "customers", "--limit", "1"]
        with patch.object(sys, "argv", args), patch.dict(sys.modules, {"pymssql": types.SimpleNamespace(connect=Mock())}), patch.object(worker, "audit_source", side_effect=RuntimeError("password=never-exposed")), contextlib.redirect_stdout(output):
            self.assertEqual(worker.main(), 1)
        self.assertNotIn("never-exposed", output.getvalue())
        self.assertIn("error", json.loads(output.getvalue()))


if __name__ == "__main__":
    unittest.main()
