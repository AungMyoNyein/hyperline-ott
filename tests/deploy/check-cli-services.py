#!/usr/bin/env python3
"""Real CLI heartbeat/dependency checks with disposable SQLite + loopback Redis.
Never connects to the existing host/container Redis or consumes production jobs.
"""
import os,base64,secrets,socket,subprocess,tempfile,time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2];api=ROOT/'services/api'
with socket.socket() as sock:
    sock.setsockopt(socket.SOL_SOCKET,socket.SO_REUSEADDR,1);sock.bind(('127.0.0.1',16381))
work=Path(tempfile.mkdtemp(prefix='hyperline-cli-readiness-'));db=work/'test.sqlite';db.touch()
env={**os.environ,'APP_ENV':'testing','APP_KEY':'base64:'+base64.b64encode(secrets.token_bytes(32)).decode(),'DB_CONNECTION':'sqlite','DB_DATABASE':str(db),'DB_URL':'','CACHE_STORE':'array','SESSION_DRIVER':'array','QUEUE_CONNECTION':'redis','REDIS_CLIENT':'predis','REDIS_HOST':'127.0.0.1','REDIS_PORT':'16381','REDIS_PASSWORD':'null','REDIS_DB':'14','REDIS_CACHE_DB':'15','TMPDIR':str(work)}
processes=[]
def start(args,name):
    log=(work/(name+'.log')).open('w');p=subprocess.Popen(args,cwd=api,env=env,stdout=log,stderr=subprocess.STDOUT);processes.append((p,log));return p
def health(role):
    return subprocess.run(['php','docker/healthcheck.php',role],cwd=api,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE).returncode
try:
    redis=start(['redis-server','--port','16381','--bind','127.0.0.1','--save','','--appendonly','no','--dir',str(work)],'redis')
    subprocess.run(['php','artisan','migrate','--force'],cwd=api,env=env,check=True,stdout=subprocess.DEVNULL)
    worker=start(['php','artisan','queue:work','redis','--sleep=1','--tries=1','--timeout=90'],'worker')
    scheduler=start(['sh','docker/run-scheduler.sh'],'scheduler')
    for role in ['worker','scheduler']:
        for attempt in range(30):
            if health(role)==0:break
            if attempt==29:raise RuntimeError(role+' did not become healthy; logs in temporary readiness directory')
            time.sleep(.3)
        print('PASS '+role+' actual heartbeat/process + SQLite/isolated Redis dependencies')
    worker.terminate();worker.wait(timeout=5)
    assert health('worker')!=0
    print('PASS stopped worker is unhealthy despite a recent heartbeat')
    redis.terminate();redis.wait(timeout=5)
    assert health('scheduler')!=0
    print('PASS scheduler dependency outage is unhealthy (no credentials logged)')
finally:
    for process,log in reversed(processes):
        if process.poll() is None:
            process.terminate()
            try:process.wait(timeout=5)
            except subprocess.TimeoutExpired:process.kill();process.wait()
        log.close()
