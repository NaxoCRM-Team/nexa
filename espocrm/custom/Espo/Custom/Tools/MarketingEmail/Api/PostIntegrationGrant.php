<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\MarketingEmail\MarketingIntegrationAccess;
final class PostIntegrationGrant implements Action
{
    public function __construct(private MarketingIntegrationAccess $access) {}
    public function process(Request $request): Response
    {
        $input = $request->getParsedBody();
        if (!in_array($input->action ?? null,['grant','revoke'],true) || !is_string($input->userId ?? null) || $input->userId === '' || strlen($input->userId)>24 || !is_int($input->sourceVersion ?? null) || $input->sourceVersion<1 || array_diff(array_keys(get_object_vars($input)),['action','userId','sourceVersion','hours'])) throw new BadRequest('Provide action, userId, integer sourceVersion and grant hours.');
        if ($input->action === 'grant' && !is_int($input->hours ?? null)) throw new BadRequest('Grant hours must be an integer.');
        if ($input->action === 'revoke' && property_exists($input,'hours')) throw new BadRequest('Revocation does not accept an expiry override.');
        $id = (string) $request->getRouteParam('id');
        if ($input->action === 'grant') $this->access->grant($input->userId,$id,$input->sourceVersion,$input->hours);
        else $this->access->revoke($input->userId,$id,$input->sourceVersion);
        return ResponseComposer::json(['updated'=>true]);
    }
}
