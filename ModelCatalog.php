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

use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\AbstractModelCatalog;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Dynamic catalog fetching the model list from the Higgsfield API (`GET /models`).
 *
 * The model slug doubles as the generation endpoint this bridge POSTs to, and the endpoint
 * reports the operations a model serves (`text2image`, `image2video`, `text2video`,
 * `video2video`, `image_edit`, ...), which capabilities are derived from. An operation this
 * bridge has no capability for leaves the model listed but without that capability, so it
 * stays invocable without promising something it cannot serve.
 *
 * @author Oskar Stark <oskarstark@googlemail.com>
 */
final class ModelCatalog extends AbstractModelCatalog
{
    private bool $modelsAreLoaded = false;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
        $this->models = [];
    }

    public function getModel(string $modelName): Model
    {
        $this->preloadRemoteModels();

        return parent::getModel($modelName);
    }

    public function getModels(): array
    {
        $this->preloadRemoteModels();

        return parent::getModels();
    }

    private function preloadRemoteModels(): void
    {
        if ($this->modelsAreLoaded) {
            return;
        }

        $response = $this->httpClient->request('GET', '/models');

        try {
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new RuntimeException(\sprintf('Cannot connect to the Higgsfield API: "%s".', $e->getMessage()), previous: $e);
        }

        if (200 !== $statusCode) {
            throw new RuntimeException(\sprintf('Cannot retrieve models from the Higgsfield API (Status code: %d).', $statusCode));
        }

        $payload = $response->toArray();
        $items = $payload['items'] ?? [];

        if (!\is_array($items)) {
            throw new RuntimeException('The Higgsfield API returned a malformed model list.');
        }

        $catalog = [];

        foreach ($items as $model) {
            if (!\is_array($model)) {
                continue;
            }

            if (!isset($model['slug']) || !\is_string($model['slug'])) {
                continue;
            }

            $operations = $model['operation_type'] ?? [];

            $catalog[$model['slug']] = [
                'class' => Higgsfield::class,
                'capabilities' => $this->capabilitiesForOperations(\is_array($operations) ? $operations : []),
            ];
        }

        ksort($catalog);

        $this->models = $catalog;
        $this->modelsAreLoaded = true;
    }

    /**
     * @param array<mixed> $operations
     *
     * @return list<Capability>
     */
    private function capabilitiesForOperations(array $operations): array
    {
        $capabilities = [];

        foreach ($operations as $operation) {
            if (!\is_string($operation)) {
                continue;
            }

            $capability = match ($operation) {
                'text2image' => Capability::TEXT_TO_IMAGE,
                'image_edit' => Capability::IMAGE_TO_IMAGE,
                'text2video' => Capability::TEXT_TO_VIDEO,
                'image2video' => Capability::IMAGE_TO_VIDEO,
                'video2video' => Capability::VIDEO_TO_VIDEO,
                default => null,
            };

            if (null !== $capability && !\in_array($capability, $capabilities, true)) {
                $capabilities[] = $capability;
            }
        }

        return $capabilities;
    }
}
