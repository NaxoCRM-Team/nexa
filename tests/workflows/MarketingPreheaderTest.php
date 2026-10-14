<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/espocrm/bootstrap.php';
$class = new ReflectionClass(Espo\Custom\Tools\MarketingEmail\MarketingDispatchService::class);
$service = $class->newInstanceWithoutConstructor();
$method = $class->getMethod('withPreheader');
foreach (['<p>Original body</p>', '<!doctype html><html><head><title>Mail</title></head><body><p>Original body</p></body></html>'] as $html) {
    $result = $method->invoke($service, $html, 'Preview <script>alert(1)</script> & offer');
    $document = new DOMDocument();
    $document->loadHTML($result, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    $body = $document->getElementsByTagName('body')->item(0);
    $first = $body->firstChild;
    if (!$first instanceof DOMElement || $first->getAttribute('data-nexa-preheader') !== 'true') throw new RuntimeException('Preheader must be first inside the HTML body.');
    if ($first->textContent !== 'Preview <script>alert(1)</script> & offer') throw new RuntimeException('Preheader text must remain literal.');
    if ($document->getElementsByTagName('script')->length !== 0) throw new RuntimeException('Preheader must not inject active markup.');
    if (!str_contains($first->getAttribute('style'), 'mso-hide:all')) throw new RuntimeException('Preheader must be hidden in Outlook as well as other clients.');
    if (!str_contains($body->textContent, 'Original body')) throw new RuntimeException('Preheader must preserve the email content.');
}
echo "Marketing preheader HTML structure and literal-text tests passed.\n";
