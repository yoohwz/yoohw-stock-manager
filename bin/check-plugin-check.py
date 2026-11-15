#!/usr/bin/env python3
"""Fail CI on packaged Plugin Check errors, malformed or incomplete reports."""
import json
from pathlib import Path
import re
import sys

def parse_report(text):
    if text.strip() == 'Success: Checks complete. No errors found.':
        return []
    segments = re.split(r'^FILE: ([^\n]+)\n', text, flags=re.M)
    if len(segments) < 3 or segments[0].strip() or len(segments) % 2 != 1:
        raise ValueError('Unrecognized Plugin Check report framing')
    records = []
    for index in range(1, len(segments), 2):
        file, body = segments[index:index + 2]
        items = json.loads(body.strip())  # Consume the entire segment, including any trailing data.
        if not isinstance(items, list):
            raise ValueError('Plugin Check file results must be an array')
        for item in items:
            if not isinstance(item, dict) or item.get('type') not in {'ERROR', 'WARNING'}:
                raise ValueError('Unknown Plugin Check result type')
            if not isinstance(item.get('line'), int) or not isinstance(item.get('code'), str) or not isinstance(item.get('message'), str):
                raise ValueError('Incomplete Plugin Check result')
            records.append((file, item))
    return records

def main():
    try:
        records = parse_report(Path(sys.argv[1]).read_text())
    except (ValueError, OSError) as error:
        print(f'Invalid Plugin Check report: {error}', file=sys.stderr)
        return 1
    errors = [(file, item) for file, item in records if item['type'] == 'ERROR']
    for file, item in errors:
        print(f"{file}:{item['line']}: {item['code']}: {item['message']}")
    print(f'Plugin Check: {len(errors)} errors; {len(records) - len(errors)} warnings (review required, not hidden).')
    return int(bool(errors))

if __name__ == '__main__':
    raise SystemExit(main())
