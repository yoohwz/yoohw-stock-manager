"""Negative contracts for the packaged WordPress.org report gate."""
import importlib.util
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('pcp', ROOT / 'bin/check-plugin-check.py')
pcp = importlib.util.module_from_spec(spec)
spec.loader.exec_module(pcp)

class PackageReportTests(unittest.TestCase):
    def block(self, kind='WARNING'):
        return 'FILE: inc/a.php\n' + json.dumps([{'type': kind, 'line': 1, 'code': 'sample', 'message': 'Fixture'}]) + '\n\n'
    def test_complete_reports(self):
        self.assertEqual(pcp.parse_report('Success: Checks complete. No errors found.\n'), [])
        self.assertEqual(len(pcp.parse_report(self.block() + self.block('ERROR'))), 2)
    def test_partial_and_unknown_results_fail_closed(self):
        for text in ['', 'Success: fake', self.block() + 'FILE: b.php\n[{"type":"ERROR"',
                     self.block('FATAL'), self.block() + 'trailing data',
                     'prefix warning\n' + self.block(), 'FILE: a.php\n{}',
                     'FILE: a.php\n[{"type":"WARNING"}]',
                     'Success: Checks complete. No errors found.\n' + self.block('ERROR')]:
            with self.subTest(text=text):
                with self.assertRaises(ValueError):
                    pcp.parse_report(text)
