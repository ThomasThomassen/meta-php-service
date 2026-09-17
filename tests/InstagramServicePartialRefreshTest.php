<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Services\InstagramService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class InstagramServicePartialRefreshTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['IG_BUSINESS_ACCOUNT_ID'] = '123456789';
        $_ENV['IG_ACCESS_TOKEN'] = 'test-token';
        $_SERVER['IG_BUSINESS_ACCOUNT_ID'] = '123456789';
        $_SERVER['IG_ACCESS_TOKEN'] = 'test-token';
    }

    public function testPartialUserMediaRefreshPreservesPreviousSnapshot(): void
    {
        $outFile = tempnam(sys_get_temp_dir(), 'ig_user_media_');
        $this->assertNotFalse($outFile);

        $previous = [
            'updated_at' => '2026-09-11T22:09:39+00:00',
            'count' => 1500,
            'data' => [['id' => 'keep-me']],
        ];
        file_put_contents($outFile, json_encode($previous));

        $firstPage = [
            'data' => [
                [
                    'id' => 'media-1',
                    'caption' => 'hello',
                    'media_type' => 'IMAGE',
                    'media_url' => 'https://example.com/media-1.jpg',
                    'permalink' => 'https://instagram.com/p/media-1',
                    'thumbnail_url' => 'https://example.com/thumb-1.jpg',
                    'timestamp' => '2026-09-17T08:40:05+00:00',
                    'username' => 'test-user',
                ],
            ],
            'paging' => [
                'next' => 'https://graph.facebook.com/v24.0/123456789/media?after=cursor-2',
            ],
        ];

        $queue = [
            new Response(200, [], json_encode($firstPage)),
        ];
        for ($i = 0; $i < 5; $i++) {
            $queue[] = new ConnectException(
                'cURL error 56: Recv failure: Connection reset by peer',
                new Request('GET', 'https://graph.facebook.com/v24.0/123456789/media?after=cursor-2')
            );
        }

        $mock = new MockHandler($queue);

        $handlerStack = HandlerStack::create($mock);
        $service = new InstagramService(new Client(['handler' => $handlerStack]));

        $summary = $service->refreshAllUserMediaToFile(3, 10, $outFile);

        $this->assertSame('2026-09-11T22:09:39+00:00', $summary['updated_at']);
        $this->assertSame(1500, $summary['count']);

        $payload = json_decode((string) file_get_contents($outFile), true);
        $this->assertSame(1500, (int) ($payload['count'] ?? 0));
        $this->assertSame('keep-me', $payload['data'][0]['id'] ?? null);

        unlink($outFile);
    }
}
