"""Bounded real-process race; WP database must explicitly opt in to fixtures."""
import concurrent.futures
import os
from pathlib import Path
import subprocess
import uuid

root = Path(__file__).resolve().parents[1]
command = [os.environ.get('YSM_TEST_WP_CLI', 'wp'), '--path=' + os.environ['YSM_TEST_WP_PATH'],
           'eval-file', str(root / 'tests/integration/concurrency.php')]

def run(mode, product_id='', operation=''):
    env = dict(os.environ, YSM_RACE_MODE=mode, YSM_RACE_ID=str(product_id), YSM_RACE_UUID=operation)
    result = subprocess.run(command, env=env, capture_output=True, text=True, check=True)
    return result.stdout.strip()

product_id = run('create')
assert product_id.isdigit(), product_id
try:
    shared = str(uuid.uuid4())
    operations = [('adjust', shared), ('adjust', shared), ('adjust', str(uuid.uuid4())),
                  ('adjust', str(uuid.uuid4())), ('external', '')]
    with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
        results = list(pool.map(lambda pair: run(pair[0], product_id, pair[1]), operations))
    assert results.count('success') == 3 and results.count('skipped') == 1, results
    print(run('verify', product_id))
finally:
    run('cleanup', product_id)
