"""Negative contracts for the packaged WordPress.org report gate."""
import importlib.util
import json
import subprocess
import sys
import tempfile
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
    def test_cli_rejects_errors_and_warnings(self):
        with tempfile.TemporaryDirectory() as directory:
            report = Path(directory) / 'report.txt'
            for text, expected in [('Success: Checks complete. No errors found.\n', 0),
                                   (self.block('WARNING'), 1), (self.block('ERROR'), 1)]:
                with self.subTest(report=text):
                    report.write_text(text)
                    result = subprocess.run([sys.executable, str(ROOT / 'bin/check-plugin-check.py'), str(report)],
                                            capture_output=True, text=True)
                    self.assertEqual(result.returncode, expected, result.stdout + result.stderr)
