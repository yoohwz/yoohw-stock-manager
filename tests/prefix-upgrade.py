"""Preserve an existing ledger across prefix upgrade, failures and concurrent bootstraps."""
import os
from pathlib import Path
import subprocess

root = Path(__file__).resolve().parents[1]
command = [os.environ.get('YOOHWSM_TEST_WP_CLI', 'wp'), '--path=' + os.environ['YOOHWSM_TEST_WP_PATH'],
           'eval-file', str(root / 'tests/integration/prefix-upgrade.php')]
def run(mode):
    result = subprocess.run(command, env=dict(os.environ, YOOHWSM_UPGRADE_MODE=mode), capture_output=True, text=True)
    assert result.returncode == 0, result.stdout + result.stderr

run('setup')
try:
    run('legacy')
    run('verify')
    run('lookup_failure')
    run('rename_failure')
    run('verify')
    run('conflict')
    run('blocked')
    run('recover_conflict')
    run('verify')
    run('missing')
    run('blocked')
    run('recover_missing')
    run('verify')
    run('legacy')
    processes = [subprocess.Popen(command, env=dict(os.environ, YOOHWSM_UPGRADE_MODE='verify'),
                                  stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) for _ in range(2)]
    for process in processes:
        out, err = process.communicate(timeout=60)
        assert process.returncode == 0, out + err
    print('PASS legacy ledger/receipt/stock/notice preservation, replay, schema failures/conflicts and concurrent upgrade')
finally:
    # Recovery helpers only operate on this test's known table names and empty conflict table.
    run('cleanup')
