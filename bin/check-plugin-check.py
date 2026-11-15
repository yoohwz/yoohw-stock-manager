#!/usr/bin/env python3
"""Fail CI on packaged Plugin Check errors; report bounded warnings honestly."""
import json
from pathlib import Path
import re
import sys
text = Path(sys.argv[1]).read_text()
blocks = re.findall(r'FILE: (.*?)\n(\[.*?\])(?=\n\n|$)', text, re.S)
errors = []
warnings = 0
for file, body in blocks:
    for item in json.loads(body):
        if item['type'] == 'ERROR':
            errors.append(f"{file}:{item['line']}: {item['code']}: {item['message']}")
        else:
            warnings += 1
# Successful clean CLI output may have no FILE blocks; reject unexpected/truncated data.
if not blocks and 'Success:' not in text:
    raise SystemExit('Plugin Check did not produce recognized results.')
for error in errors:
    print(error)
print(f'Plugin Check: {len(errors)} errors; {warnings} warnings (review required, not hidden).')
raise SystemExit(bool(errors))
