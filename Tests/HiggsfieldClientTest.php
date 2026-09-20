<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\Higgsfield\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Higgsfield\Contract\ImageNormalizer;
use Symfony\AI\Platform\Bridge\Higgsfield\Higgsfield;
use Symfony\AI\Platform\Bridge\Higgsfield\HiggsfieldClient;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Message\Content\Image;
use Symfony\AI\Platform\Model;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HiggsfieldClientTest extends TestCase
{
    private const BASE_URL = 'https://platform.higgsfield.ai';

    public function testSupportsModel()
    {
        $client = new HiggsfieldClient(new MockHttpClient([], self::BASE_URL), new MockClock());

        $this->assertTrue($client->supports(new Higgsfield('higgsfield-ai/soul/v2/standard')));
        $this->assertFalse($client->supports(new Model('any-model')));
    }

    public function testClientGeneratesImageWithImmediateCompletion()
    {
        $imageContent = file_get_contents(\dirname(__DIR__, 6).'/fixtures/image.jpg');

        $httpClient = new MockHttpClient([
            new MockResponse('{"request_id": "req-123", "status": "completed", "images": [{"url": "https://cdn.higgsfield.ai/image.jpg"}]}'),
            new MockResponse($imageContent, ['response_headers' => ['content-type' => 'image/jpeg']]),
        ], self::BASE_URL);

        $client = new HiggsfieldClient($httpClient, new MockClock());

        $client->request(new Higgsfield('higgsfield-ai/soul/v2/standard', [Capability::TEXT_TO_IMAGE]), 'A cat on a kitchen table');

        $this->assertSame(2, $httpClient->getRequestsCount());
    }

    public function testClientPollsUntilCompletion()
    {
        $videoContent = file_get_contents(\dirname(__DIR__, 6).'/fixtures/ocean.mp4');

        $httpClient = new MockHttpClient([
            new MockResponse('{"request_id": "req-123", "status": "queued"}'),
            new MockResponse('{"request_id": "req-123", "status": "in_progress"}'),
            new MockResponse('{"request_id": "req-123", "status": "completed", "video": {"url": "https://cdn.higgsfield.ai/video.mp4"}}'),
            new MockResponse($videoContent, ['response_headers' => ['content-type' => 'video/mp4']]),
        ], self::BASE_URL);

        $clock = new MockClock();
        $startedAt = $clock->now();

        $client = new HiggsfieldClient($httpClient, $clock, 5);

        $client->request(new Higgsfield('kling-video/v2.5-turbo/pro/image-to-video', [Capability::IMAGE_TO_VIDEO]), 'Zoom into the ocean');

        $this->assertSame(4, $httpClient->getRequestsCount());
        $this->assertSame(10, $clock->now()->getTimestamp() - $startedAt->getTimestamp());
    }

    public function testClientSendsPromptAndOptionsToTheModelEndpoint()
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (str_contains($url, 'cdn.higgsfield.ai')) {
                return new MockResponse('binary');
            }

            $this->assertSame('POST', $method);
            $this->assertSame('https://platform.higgsfield.ai/higgsfield-ai/soul/v2/standard', $url);
            $this->assertSame(['prompt' => 'A cat', 'aspect_ratio' => '9:16'], json_decode($options['body'], true));

            return new MockResponse('{"request_id": "req-123", "status": "completed", "images": [{"url": "https://cdn.higgsfield.ai/image.jpg"}]}');
        }, self::BASE_URL);

        $client = new HiggsfieldClient($httpClient, new MockClock());

        $client->request(new Higgsfield('higgsfield-ai/soul/v2/standard', [Capability::TEXT_TO_IMAGE]), 'A cat', ['aspect_ratio' => '9:16']);
    }

    public function testClientMapsNormalizedImageToImageUrl()
    {
        $payload = (new ImageNormalizer())->normalize(Image::fromFile(\dirname(__DIR__, 6).'/fixtures/image.jpg'));

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            if (str_contains($url, 'cdn.higgsfield.ai')) {
                return new MockResponse('binary');
            }

            $body = json_decode($options['body'], true);

            $this->assertArrayNotHasKey('input_images', $body);
            $this->assertStringStartsWith('data:image/jpeg;base64,', $body['image_url']);
            $this->assertSame('Slowly zoom in', $body['prompt']);

            return new MockResponse('{"request_id": "req-123", "status": "completed", "video": {"url": "https://cdn.higgsfield.ai/video.mp4"}}');
        }, self::BASE_URL);

        $client = new HiggsfieldClient($httpClient, new MockClock());

        $client->request(new Higgsfield('kling-video/v2.5-turbo/pro/image-to-video', [Capability::IMAGE_TO_VIDEO]), $payload, ['prompt' => 'Slowly zoom in']);
    }

    public function testClientThrowsOnFailedStatus()
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"request_id": "req-123", "status": "failed", "detail": "generation crashed"}'),
        ], self::BASE_URL);

        $client = new HiggsfieldClient($httpClient, new MockClock());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Higgsfield request "req-123" "failed": "generation crashed".');

        $client->request(new Higgsfield('higgsfield-ai/soul/v2/standard', [Capability::TEXT_TO_IMAGE]), 'A cat');
    }

    public function testClientReportsTheErrorKeyOfAFailedGeneration()
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"request_id": "req-123", "status": "queued"}'),
            new MockResponse('{"request_id": "req-123", "status": "failed", "error": "Generation failed"}'),
        ], self::BASE_URL);

        $client = new HiggsfieldClient($httpClient, new MockClock());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Higgsfield request "req-123" "failed": "Generation failed".');

        $client->request(new Higgsfield('kling-video/v2.5-turbo/pro/image-to-video', [Capability::IMAGE_TO_VIDEO]), 'Zoom in');
    }

    public function testClientThrowsWhenRequestIdMissing()
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"detail": "invalid credentials"}'),
        ], self::BASE_URL);

        $client = new HiggsfieldClient($httpClient, new MockClock());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Higgsfield API error: "invalid credentials".');

        $client->request(new Higgsfield('higgsfield-ai/soul/v2/standard', [Capability::TEXT_TO_IMAGE]), 'A cat');
    }

    public function testClientThrowsWhenNoMediaUrlIsReturned()
    {
        $httpClient = new MockHttpClient([
            new MockResponse('{"request_id": "req-123", "status": "completed"}'),
        ], self::BASE_URL);

        $client = new HiggsfieldClient($httpClient, new MockClock());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The Higgsfield response does not contain any media URL.');

        $client->request(new Higgsfield('higgsfield-ai/soul/v2/standard', [Capability::TEXT_TO_IMAGE]), 'A cat');
    }
}
