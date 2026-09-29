<?php
namespace Espo\Custom\EntryPoints;
use Espo\Core\Api\{Request, Response};
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\FileStorage\Manager as FileStorageManager;
use Espo\Custom\Tools\LandingPage\LandingPageService;
final class NexaLandingAsset implements EntryPoint { use NoAuth; public function __construct(private LandingPageService $service,private FileStorageManager $storage) {} public function run(Request $request,Response $response): void { $item=$this->service->publishedAsset((string)$request->getQueryParam('key'),(string)$request->getQueryParam('slug'),(string)$request->getQueryParam('asset')); $file=$item['attachment']; $stream=$this->storage->getStream($file); $type=$file->getType()?:'application/octet-stream';$disposition=str_starts_with($type,'image/')?'inline':'attachment'; $response->setHeader('Content-Type',$type)->setHeader('Content-Disposition',$disposition.'; filename="'.str_replace('"','',(string)$file->getName()).'"')->setHeader('Cache-Control','public, max-age=3600')->setHeader('X-Content-Type-Options','nosniff')->setHeader('Content-Length',(string)($stream->getSize()??$this->storage->getSize($file)))->setBody($stream); } }
