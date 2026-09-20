Higgsfield Platform
===================

Higgsfield platform bridge for Symfony AI.

Higgsfield is an AI-native creative suite for generating images and videos from text prompts or
references. The bridge submits a generation request, polls the Higgsfield API until the media is
ready and returns the result as a `BinaryResult`.

Usage
-----

```php
use Symfony\AI\Platform\Bridge\Higgsfield\Factory;
use Symfony\AI\Platform\Message\Content\Text;

$platform = Factory::createPlatform(
    apiKey: 'YOUR_KEY_ID',
    apiSecret: 'YOUR_KEY_SECRET',
);

$result = $platform->invoke('soul-2', new Text('A cat on a kitchen table'), [
    'aspect_ratio' => '9:16',
]);

$result->asFile(__DIR__.'/cat.png');
```

The model name maps directly to a Higgsfield generation endpoint. Any extra input parameters are
passed as the third `invoke()` argument. The model catalog is fetched from the Higgsfield API, so
`GET /models` decides which model names are available to your account.

Endpoint names are long and carry a version (`higgsfield-ai/soul/v2/standard`), which cannot be
dropped - `higgsfield-ai/soul/standard` exists alongside it. `CuratedModelCatalog`, used by default,
therefore adds a short alias that keeps the version visible:

| Alias           | Model                                            |
| --------------- | ------------------------------------------------ |
| `soul-2`        | `higgsfield-ai/soul/v2/standard`                 |
| `kling-2.5-i2v` | `kling-video/v2.5-turbo/standard/image-to-video` |
| `wan-2.7-t2v`   | `wan/v2.7/text-to-video`                         |

A family and version can serve several operations - `wan/v2.7` also has an image-to-video and a
reference-to-video endpoint - so the alias names the operation whenever the family and version alone
would not identify one.

Aliases are a shortcut, never a restriction: every name the API reports keeps working. Pass your own
`ModelCatalogInterface` to `Factory::createPlatform()` to replace them.

Higgsfield Documentation
------------------------

 * [API documentation](https://docs.higgsfield.ai/docs)
 * [Model catalog](https://console.higgsfield.ai/)

Resources
---------

 * [Contributing](https://symfony.com/doc/current/contributing/index.html)
 * [Report issues](https://github.com/symfony/ai/issues) and
   [send Pull Requests](https://github.com/symfony/ai/pulls)
   in the [main Symfony AI repository](https://github.com/symfony/ai)
