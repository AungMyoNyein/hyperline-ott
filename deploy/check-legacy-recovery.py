#!/usr/bin/env python3
"""Independent legacy CLI recovery checks; never consumes/injects queue jobs.
Suitable before rollout or after legacy worker/scheduler rollback. Does not
claim FPM-based legacy Docker health probes are valid for CLI containers.
"""
import json
from pathlib import Path
import subprocess
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
RELEASE = (ROOT / 'deploy/releases/LATEST_RUNTIME_RELEASE').read_text().strip()
DIRECTORY = ROOT / 'deploy/releases' / RELEASE

PROCESS_PROBE = r'''$out=[];foreach(glob("/proc/[0-9]*/cmdline") as $path){$c=@file_get_contents($path);if($c!==false){$parts=explode("\0",$c);if(($parts[0]??"")==="php" && in_array("artisan",$parts,true)){if(in_array("queue:work",$parts,true))$out[]="worker";}if(($parts[0]??"")==="sh" && str_contains($parts[2]??"","while true; do php artisan schedule:run"))$out[]="scheduler-loop";}}echo json_encode(array_values(array_unique($out)));'''
DEPENDENCY_PROBE = r'''require "vendor/autoload.php";try{$app=require "bootstrap/app.php";$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$ok=(bool)Illuminate\Support\Facades\Redis::connection()->ping();$db=Illuminate\Support\Facades\DB::select("SELECT 1");echo json_encode(["redis"=>$ok,"db"=>count($db)===1]);}catch(Throwable $e){exit(1);}'''


def run(args):
    p = subprocess.run(args, cwd=ROOT, capture_output=True, text=True)
    if p.returncode:
        raise RuntimeError('Read-only check failed; details suppressed')
    return p.stdout


def main():
    checks = {}
    manifest = json.loads((DIRECTORY / 'manifest.json').read_text())
    for role in ['worker', 'scheduler']:
        name = 'hyperline-' + role + '-1'
        c = json.loads(run(['docker', 'inspect', name]))[0]
        assert c['Config']['Labels']['com.docker.compose.project'] == 'hyperline'
        assert c['Config']['Labels']['com.docker.compose.service'] == role
        assert c['Image'] == manifest['services'][role]['image_id']
        signatures = json.loads(run(['docker', 'exec', name, 'php', '-r', PROCESS_PROBE]))
        checks[role + '_process'] = ('worker' if role == 'worker' else 'scheduler-loop') in signatures
        checks[role + '_redis_db_connectivity'] = all(json.loads(
            run(['docker', 'exec', name, 'php', '-r', DEPENDENCY_PROBE])).values())
        if role == 'scheduler':
            p = subprocess.run(['docker', 'logs', '--since', '240s', name], capture_output=True, text=True)
            checks['scheduler_recent_successful_pass'] = p.returncode == 0 and (
                'No scheduled commands are ready' in p.stdout + p.stderr)
    for host, path, key in [('ott.zentunnel.net', '/', 'web'), ('cms.zentunnel.net', '/', 'cms'),
                            ('api.zentunnel.net', '/api/v1/health', 'api_health'),
                            ('api.zentunnel.net', '/api/v1/movies', 'public_catalog')]:
        req = urllib.request.Request('http://127.0.0.1:18080' + path, headers={'Host': host})
        with urllib.request.urlopen(req, timeout=10) as response:
            checks[key] = response.status == 200
            if key == 'api_health':
                checks[key] = checks[key] and json.loads(response.read()).get('status') == 'ok'
    for label, ok in checks.items():
        print('PASS' if ok else 'FAIL', label)
    evidence = {'checks': checks, 'limits': [
        'Legacy Docker FPM probes remain unhealthy for CLI containers.',
        'No queue job was dispatched/consumed by these checks; real job processing not proven.',
        'Recent normal empty scheduler passes observed; no due task forced.',
        'Browser admin CRUD/login and protected CDN playback are not verified here.',
        'Running API is legacy until approved activation; raw legacy API rollback forbidden.',
    ]}
    (DIRECTORY / 'legacy-cli-recovery-checks.json').write_text(json.dumps(evidence, indent=2) + '\n')
    return 0 if all(checks.values()) else 1


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except Exception:
        print('FAIL legacy recovery check incomplete; sensitive details suppressed')
        raise SystemExit(1)
