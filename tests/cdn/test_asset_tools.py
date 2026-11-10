import json
from pathlib import Path
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
class AssetToolsTest(unittest.TestCase):
    def test_auditor_walks_playlists_init_segments_and_keys(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)
            (root/'master.m3u8').write_text('#EXTM3U\nvideo/variant.list\n')
            (root/'video').mkdir()
            (root/'video'/'variant.list').write_text('#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI="key.bin"\n#EXT-X-MAP:URI="init.mp4"\nsegment.m4s\n')
            for name in ['key.bin','init.mp4','segment.m4s']:(root/'video'/name).write_bytes(b'test-only')
            result=subprocess.run(['python3',str(ROOT/'deploy/cdn/audit-hls-tree.py'),directory,'master.m3u8'],capture_output=True,text=True)
            self.assertEqual(result.returncode,0,result.stderr)
            self.assertIn('5 local',result.stdout)
    def test_auditor_rejects_every_external_or_ambiguous_reference(self):
        for uri in ['https://cdn.example/segment.ts','/public/segment.ts','../segment.ts','%2e%2e/segment.ts','segment.ts?token=secret','file:///etc/passwd']:
            with self.subTest(uri=uri), tempfile.TemporaryDirectory() as directory:
                (Path(directory)/'master.m3u8').write_text(f'#EXTM3U\n#EXT-X-KEY:METHOD=AES-128,URI="{uri}"\n')
                result=subprocess.run(['python3',str(ROOT/'deploy/cdn/audit-hls-tree.py'),directory,'master.m3u8'],capture_output=True)
                self.assertNotEqual(result.returncode,0)
    def test_map_renderer_rejects_unsafe_origin_prefix(self):
        with tempfile.TemporaryDirectory() as directory:
            registry=Path(directory)/'assets.json'
            registry.write_text(json.dumps({'42':{'movie_id':7,'origin_directory':'/vod/../private/','manifest':'master.m3u8'}}))
            result=subprocess.run(['python3',str(ROOT/'deploy/cdn/render-assets.py'),str(registry)],capture_output=True)
            self.assertNotEqual(result.returncode,0)
if __name__=='__main__':unittest.main()
