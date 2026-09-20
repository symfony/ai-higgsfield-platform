<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\Higgsfield;

use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\JsonBodyEncodingTrait;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client for the Higgsfield API (https://higgsfield.ai).
 *
 * Higgsfield works asynchronously: a generation request is submitted, then its status is polled
 * until the media is ready. The resulting media URL is downloaded and handed over to the
 * result converter as binary content.
 *
 * @author Oskar Stark <oskarstark@googlemail.com>
 */
final class HiggsfieldClient implements ModelClientInterface
{
    use JsonBodyEncodingTrait;

    public const DEFAULT_POLLING_INTERVAL = 5;

    private const TERMINAL_STATUSES = ['completed', 'failed', 'nsfw'];

    /**
     * @param positive-int $pollingInterval Seconds to wait between two status polls
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ClockInterface $clock,
        private readonly int $pollingInterval = self::DEFAULT_POLLING_INTERVAL,
    ) {
    }

    public function supports(Model $model): bool
    {
        return $model instanceof Higgsfield;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        $endpoint = ltrim($model->getName(), '/');

        $response = $this->httpClient->request('POST', \sprintf('/%s', $endpoint), [
            'body' => $this->encodeJsonBody($this->createInput($payload, $options)),
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $data = $response->toArray(false);

        $requestId = $data['request_id'] ?? throw new RuntimeException(\sprintf('Higgsfield API error: "%s".', $this->extractError($data)));
        $status = $data['status'] ?? 'queued';

        while (!\in_array($status, self::TERMINAL_STATUSES, true)) {
            $this->clock->sleep($this->pollingInterval); // we need to wait until the generation is ready

            $data = $this->httpClient->request('GET', \sprintf('/requests/%s/status', $requestId))->toArray(false);

            $status = $data['status'] ?? 'queued';
        }

        if ('completed' !== $status) {
            throw new RuntimeException(\sprintf('Higgsfield request "%s" "%s": "%s".', $requestId, $status, $this->extractError($data)));
        }

        return new RawHttpResult($this->httpClient->request('GET', $this->extractMediaUrl($data)));
    }

    /**
     * @param array<string|int, mixed>|string $payload
     * @param array<string, mixed>            $options
     *
     * @return array<string, mixed>
     */
    private function createInput(array|string $payload, array $options): array
    {
        if (\is_string($payload)) {
            return ['prompt' => $payload, ...$options];
        }

        // Image content normalized by the ImageNormalizer, e.g. for image-to-video generation.
        if (isset($payload['type'], $payload['image_url']) && 'image_url' === $payload['type']) {
            return ['image_url' => $payload['image_url'], ...$options];
        }

        // Text content normalized to ['text' => '...'] by the default contract.
        if (isset($payload['text'])) {
            return ['prompt' => $payload['text'], ...$options];
        }

        return [...$payload, ...$options];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractMediaUrl(array $data): string
    {
        if (isset($data['video']['url']) && \is_string($data['video']['url'])) {
            return $data['video']['url'];
        }

        if (isset($data['images'][0]['url']) && \is_string($data['images'][0]['url'])) {
            return $data['images'][0]['url'];
        }

        throw new RuntimeException('The Higgsfield response does not contain any media URL.');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractError(array $data): string
    {
        foreach (['detail', 'error', 'message'] as $key) {
            if (isset($data[$key]) && \is_string($data[$key])) {
                return $data[$key];
            }
        }

        return 'Unknown error';
    }
}
