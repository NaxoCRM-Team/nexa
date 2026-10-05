<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\MarketingEmail\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\MarketingEmail\MarketingUnsubscribeService;

final class MarketingUnsubscribe implements Action
{
    public function __construct(private MarketingUnsubscribeService $service) {}

    public function process(Request $request): Response
    {
        $id = (string) $request->getRouteParam('id');
        $token = (string) $request->getRouteParam('token');
        $submitted = strtolower($request->getMethod()) === 'post';
        if ($submitted) $this->service->unsubscribe($id, $token);
        else $this->service->inspect($id, $token);
        $title = $submitted ? 'You have unsubscribed' : 'Unsubscribe from marketing email';
        $content = $submitted ? '<p>Your email address will no longer receive marketing emails from this workspace.</p>' :
            '<p>Confirm that you want to stop receiving marketing emails from this workspace.</p>' .
            '<form method="post"><button type="submit">Unsubscribe</button></form>';
        $html = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' .
            '<title>' . $title . '</title><style>body{margin:0;background:#f3f5f5;color:#182824;font:16px system-ui}' .
            'main{max-width:560px;margin:12vh auto;padding:32px}h1{font-size:26px}p{line-height:1.6}' .
            'button{background:#087763;color:white;border:0;border-radius:4px;padding:12px 24px;font:inherit;cursor:pointer}' .
            'button:hover{background:#065c4c}button:focus-visible{outline:3px solid #192d28;outline-offset:3px}</style>' .
            '<main><h1>' . $title . '</h1>' . $content . '</main></html>';
        return ResponseComposer::empty()->writeBody($html)->setHeader('Content-Type', 'text/html; charset=utf-8')->setHeader('Cache-Control', 'no-store')
            ->setHeader('Referrer-Policy', 'no-referrer')->setHeader('X-Content-Type-Options', 'nosniff');
    }
}
