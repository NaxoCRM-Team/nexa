<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\EmailExperiment\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Custom\Tools\EmailExperiment\EmailExperimentService;
final class GetWorkspace implements Action
{
    public function __construct(private EmailExperimentService $service) {}
    public function process(Request $request):Response
    {
        $offset=$request->getQueryParam('offset')??'0';
        if(!is_scalar($offset)||!preg_match('/^[0-9]{1,7}$/D',(string)$offset))throw new \Espo\Core\Exceptions\BadRequest('Scrolling offset is invalid.');
        $search=$request->getQueryParam('search')??'';
        if(!is_string($search))throw new \Espo\Core\Exceptions\BadRequest('Search text is invalid.');
        $sort=$request->getQueryParam('sort')??'createdAt';$direction=$request->getQueryParam('direction')??'desc';
        if(!is_string($sort)||!is_string($direction))throw new \Espo\Core\Exceptions\BadRequest('Sort order is invalid.');
        return ResponseComposer::json($this->service->catalog($search,(int)$offset,$sort,$direction))->setHeader('Cache-Control','private, no-store');
    }
}
