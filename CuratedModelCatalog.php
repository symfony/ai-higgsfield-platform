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

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;

/**
 * Decorates a model catalog with short, versioned aliases for the models this bridge recommends.
 *
 * Higgsfield model names double as the generation endpoint, which makes them long and carry a
 * version (`higgsfield-ai/soul/v2/standard`). The version is not noise - `higgsfield-ai/soul/standard`
 * exists alongside it - so an alias shortens the name while keeping the version visible: `soul-2`.
 *
 * A family and version can serve several operations (`wan/v2.7` has a text-to-video, an
 * image-to-video and a reference-to-video endpoint), so the alias names the operation too whenever
 * the family and version alone would not identify one endpoint.
 *
 * Every name the decorated catalog knows keeps working, so an alias is a shortcut, never a restriction.
 *
 * The aliases are maintained by hand and point at a version that can be retired upstream; when that
 * happens the alias moves to its successor.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class CuratedModelCatalog implements ModelCatalogInterface
{
    /**
     * @var array<string, string>
     */
    public const ALIASES = [
        'soul-2' => 'higgsfield-ai/soul/v2/standard',
        'kling-2.5-i2v' => 'kling-video/v2.5-turbo/standard/image-to-video',
        'wan-2.7-t2v' => 'wan/v2.7/text-to-video',
    ];

    public function __construct(
        private readonly ModelCatalogInterface $catalog,
    ) {
    }

    public function getModel(string $modelName): Model
    {
        return $this->catalog->getModel($this->resolve($modelName));
    }

    public function getModels(): array
    {
        $models = $this->catalog->getModels();

        foreach (self::ALIASES as $alias => $modelName) {
            if (isset($models[$modelName])) {
                $models[$alias] = $models[$modelName];
            }
        }

        return $models;
    }

    /**
     * Options are appended to the model name as a query string, so they survive the substitution.
     */
    private function resolve(string $modelName): string
    {
        $name = $modelName;
        $options = '';

        if (str_contains($modelName, '?')) {
            [$name, $queryString] = explode('?', $modelName, 2);
            $options = '?'.$queryString;
        }

        return (self::ALIASES[$name] ?? $name).$options;
    }
}
