<?php

namespace Tests\Unit;

use App\Support\Playback\SignedPlaybackUrl;
use Carbon\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SignedPlaybackUrlTest extends TestCase
{
    public static function insecureUrls(): array
    {
        return [['http://cdn.example.test/master.m3u8'], ['https://user:secret@cdn.example.test/master.m3u8'], ['//cdn.example.test/master.m3u8'], ['javascript:alert(1)']];
    }

    #[DataProvider('insecureUrls')]
    public function test_insecure_signed_urls_are_rejected(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SignedPlaybackUrl($url, Carbon::now()->addMinute());
    }
}
