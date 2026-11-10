<?php

namespace Tests\Unit;

use App\Support\ServiceHeartbeat;
use PHPUnit\Framework\TestCase;

class ServiceHeartbeatTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/hl-heartbeat-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function test_worker_and_scheduler_require_their_own_fresh_heartbeat(): void
    {
        $this->assertNull(ServiceHeartbeat::fresh('worker', 120, $this->directory));
        ServiceHeartbeat::mark('worker', 123, $this->directory);
        $this->assertSame(123, ServiceHeartbeat::fresh('worker', 120, $this->directory)['pid']);
        $this->assertNull(ServiceHeartbeat::fresh('scheduler', 150, $this->directory));
        ServiceHeartbeat::mark('scheduler', 456, $this->directory);
        $this->assertSame(456, ServiceHeartbeat::fresh('scheduler', 150, $this->directory)['pid']);
    }

    public function test_stale_future_and_malformed_files_fail_closed(): void
    {
        $path = ServiceHeartbeat::path('worker', $this->directory);
        foreach ([['role' => 'worker', 'pid' => 123, 'time' => time() - 121], ['role' => 'worker', 'pid' => 123, 'time' => time() + 60], ['role' => 'scheduler', 'pid' => 123, 'time' => time()], ['role' => 'worker', 'pid' => 0, 'time' => time()], []] as $data) {
            file_put_contents($path, json_encode($data));
            $this->assertNull(ServiceHeartbeat::fresh('worker', 120, $this->directory));
        }
        file_put_contents($path, 'broken');
        $this->assertNull(ServiceHeartbeat::fresh('worker', 120, $this->directory));
    }
}
