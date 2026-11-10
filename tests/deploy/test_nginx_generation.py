"""Regression checks for worker exit races and finite observation deadlines."""
import importlib.util
from pathlib import Path
import subprocess
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[2]/'deploy/https/wait-nginx-generation.py'
spec = importlib.util.spec_from_file_location('nginx_generation', SCRIPT)
generation = importlib.util.module_from_spec(spec)
spec.loader.exec_module(generation)


class NginxGenerationTest(unittest.TestCase):
    def test_worker_disappearing_during_proc_read_is_ignored(self):
        with patch.object(generation, 'identity', return_value='1:100'), patch.object(Path, 'read_text', return_value='2'), patch.object(Path, 'read_bytes', side_effect=ProcessLookupError()):
            self.assertEqual(generation.snapshot(1), {'master': '1:100', 'workers': []})

    def test_nonfinite_or_unbounded_cli_deadlines_are_rejected_before_pid_read(self):
        for timeout in ['nan', 'inf', '0', '-1', '31']:
            with self.subTest(timeout=timeout):
                p = subprocess.run(['python3', str(SCRIPT), 'snapshot', '--timeout', timeout, '--pid-file', '/nonexistent'], capture_output=True, text=True)
                self.assertEqual(p.returncode, 2)
                self.assertIn('finite', p.stderr)


if __name__ == '__main__':
    unittest.main()
