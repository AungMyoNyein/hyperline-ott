import importlib.util
from pathlib import Path
import subprocess
import unittest

SCRIPT = Path(__file__).resolve().parents[2]/'tools/cms-disposable-acceptance.py'
spec = importlib.util.spec_from_file_location('cms_acceptance', SCRIPT)
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

class CmsAcceptanceGuardTest(unittest.TestCase):
    def fixture(self):
        return {'id': 9, 'slug': 'hyperline-acceptance-unique', 'title': '[ACCEPTANCE ONLY] unique', 'created_by': {'id': 4}}
    def allowed(self, movie, baseline=None):
        return m.is_owned_record(movie, 9, 'hyperline-acceptance-unique', '[ACCEPTANCE ONLY] unique', 4, baseline or {1: 'existing', 2: 'existing'})
    def test_only_new_acknowledged_owned_record_is_mutable(self):
        self.assertTrue(self.allowed(self.fixture()))
    def test_preexisting_id_is_never_adopted_even_with_matching_markers(self):
        self.assertFalse(self.allowed(self.fixture(), {9: 'existing'}))
    def test_foreign_creator_is_never_mutated_or_cleaned_up(self):
        movie = self.fixture(); movie['created_by']['id'] = 5
        self.assertFalse(self.allowed(movie))
    def test_wrong_id_slug_or_title_is_never_mutated(self):
        for field, value in [('id', 2), ('slug', 'someone-elses-slug'), ('title', 'Existing draft')]:
            with self.subTest(field=field):
                movie = self.fixture(); movie[field] = value
                self.assertFalse(self.allowed(movie))
    def test_default_invocation_is_plan_only_without_credentials_or_network(self):
        r = subprocess.run(['python3', str(SCRIPT)], capture_output=True, text=True, check=True)
        self.assertIn('PLAN ONLY', r.stdout)
        self.assertIn('No network request made', r.stdout)

if __name__ == '__main__': unittest.main()
