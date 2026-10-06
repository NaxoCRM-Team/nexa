<?php
declare(strict_types=1);
namespace Espo\Custom\EntryPoints;

use Espo\Core\Api\{Request, Response};
use Espo\Core\EntryPoint\{EntryPoint, Traits\NoAuth};
use Espo\Custom\Tools\MarketingEmail\MarketingImageService;

final class NexaMarketingImage implements EntryPoint
{
    use NoAuth;
    public function __construct(private MarketingImageService $service) {}
    public function run(Request $request, Response $response): void
    {
        $image = $this->service->publicImage((string) $request->getQueryParam('token'));
        $response->setHeader('Content-Type', $image['mimeType'])
            ->setHeader('Content-Disposition', 'inline')
            ->setHeader('Content-Length', (string) strlen($image['bytes']))
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->setBody((new \Slim\Psr7\Factory\StreamFactory())->createStream($image['bytes']));
    }
}
