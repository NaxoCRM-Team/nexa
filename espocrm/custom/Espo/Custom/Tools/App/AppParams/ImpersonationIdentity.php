<?php
namespace Espo\Custom\Tools\App\AppParams;
use Espo\Custom\Tools\Security\ImpersonationService;use Espo\Tools\App\AppParam;use Throwable;
final class ImpersonationIdentity implements AppParam{public function __construct(private ImpersonationService $service){}public function get():?array{try{return $this->service->current();}catch(Throwable){return null;}}}
