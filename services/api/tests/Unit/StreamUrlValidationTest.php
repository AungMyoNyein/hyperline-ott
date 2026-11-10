<?php

namespace Tests\Unit;

use App\Rules\HlsManifestPath;
use App\Rules\SafeStreamUrl;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ArrayHostResolver;
use Tests\TestCase;

class StreamUrlValidationTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function violationsFor(string $url): array
    {
        $resolver = new ArrayHostResolver([
            'cdn.example.test' => ['93.184.216.34'],
            'private.example.test' => ['10.0.0.5'],
            'mixed.example.test' => ['93.184.216.34', '172.16.9.9'],
            'unresolvable.example.test' => [],
        ]);

        return Validator::make(
            ['url' => $url],
            ['url' => ['required', 'string', new SafeStreamUrl($resolver), new HlsManifestPath]],
        )->errors()->all();
    }

    public static function acceptedUrls(): array
    {
        return [
            'https manifest on public host' => ['https://cdn.example.test/vod/a.m3u8'],
            'query string is fine' => ['https://cdn.example.test/vod/a.m3u8?token=abc'],
            'ipv4 literal in public space' => ['https://93.184.216.34/vod/a.m3u8'],
        ];
    }

    public static function rejectedUrls(): array
    {
        return [
            'plain http' => ['http://cdn.example.test/a.m3u8'],
            'ftp' => ['ftp://cdn.example.test/a.m3u8'],
            'javascript pseudo scheme' => ['javascript:alert(1)'],
            'protocol relative' => ['//cdn.example.test/a.m3u8'],
            'not a url' => ['not a url'],
            'empty' => [''],
            'loopback' => ['https://127.0.0.1/a.m3u8'],
            'private range' => ['https://10.0.0.1/a.m3u8'],
            'cloud metadata' => ['https://169.254.169.254/latest/meta-data.m3u8'],
            'host resolving privately' => ['https://private.example.test/a.m3u8'],
            'host with a private answer' => ['https://mixed.example.test/a.m3u8'],
            'localhost name' => ['https://localhost/a.m3u8'],
            'credentials embedded' => ['https://user:pass@cdn.example.test/a.m3u8'],
            'unexpected port' => ['https://cdn.example.test:8443/a.m3u8'],
            'unresolvable host' => ['https://unresolvable.example.test/a.m3u8'],
            'not a manifest' => ['https://cdn.example.test/a.mp4'],
            'manifest with trailing path' => ['https://cdn.example.test/a.m3u8/download'],
        ];
    }

    #[DataProvider('acceptedUrls')]
    public function test_accepted_urls(string $url): void
    {
        $this->assertSame([], $this->violationsFor($url), $url.' should be accepted');
    }

    #[DataProvider('rejectedUrls')]
    public function test_rejected_urls(string $url): void
    {
        $this->assertNotEmpty($this->violationsFor($url), $url.' should be rejected');
    }

    public function test_unresolved_hosts_pass_when_dns_checking_is_disabled(): void
    {
        config(['ott.streams.reject_unresolved_hosts' => false]);

        $this->assertSame([], $this->violationsFor('https://unresolvable.example.test/a.m3u8'));
    }
}
