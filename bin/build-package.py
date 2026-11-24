#!/usr/bin/env python3
"""Build a review ZIP using .distignore; never publish or change versions."""
import fnmatch
from pathlib import Path
import sys
import zipfile
root = Path(__file__).resolve().parents[1]
output = Path(sys.argv[1]).resolve()
patterns = [p.strip() for p in (root / '.distignore').read_text().splitlines() if p.strip()]
with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
    for path in sorted(root.rglob('*')):
        relative = path.relative_to(root)
        if any(fnmatch.fnmatch(str(relative), pattern) or any(fnmatch.fnmatch(part, pattern) for part in relative.parts) for pattern in patterns):
            continue
        if path.is_file() and path.resolve() != output:
            archive.write(path, 'yoohw-stock-desk/' + relative.as_posix())
print(output)
