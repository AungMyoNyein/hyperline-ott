<?php

namespace App\Support;

final class ServiceHeartbeat
{
    public static function path(string $role, ?string $directory = null): string
    {
        if (! in_array($role, ['worker', 'scheduler'], true)) {
            throw new \InvalidArgumentException('Unknown service role.');
        }

        return ($directory ?? sys_get_temp_dir()).'/hyperline-'.$role.'-heartbeat.json';
    }

    public static function mark(string $role, ?int $pid = null, ?string $directory = null): void
    {
        $path = self::path($role, $directory);
        $temporary = $path.'.'.getmypid().'.tmp';
        file_put_contents($temporary, json_encode(['role' => $role, 'pid' => $pid ?? getmypid(), 'time' => time()]), LOCK_EX);
        rename($temporary, $path);
    }

    public static function fresh(string $role, int $maxAge, ?string $directory = null): ?array
    {
        $path = self::path($role, $directory);
        $data = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        if (! is_array($data) || ($data['role'] ?? null) !== $role || ! is_int($data['time'] ?? null)
            || ! is_int($data['pid'] ?? null) || $data['pid'] < 1 || $data['time'] > time() || time() - $data['time'] > $maxAge) {
            return null;
        }

        return $data;
    }
}
