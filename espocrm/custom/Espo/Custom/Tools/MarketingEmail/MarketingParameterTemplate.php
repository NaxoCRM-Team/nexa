<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail;

use DOMDocument;
use DOMXPath;
use Espo\Core\Exceptions\BadRequest;
use stdClass;

/** Parameters occupy literal text only, never HTML attributes or native helper arguments. */
final class MarketingParameterTemplate
{
    private const TOKEN = '/\[\[nexa:([a-z][a-z0-9_]{0,39}):([1-9][0-9]{0,2})\]\]/';

    public function __construct(private MarketingParameterContract $contract) {}

    public function schema(string $subject, string $body, string $preheader = ''): stdClass
    {
        $limits = [];
        foreach ([$subject, $body, $preheader] as $text) {
            if (str_contains(preg_replace(self::TOKEN, '', $text), '[[nexa:')) throw new BadRequest('An email parameter declaration is invalid.');
            preg_match_all(self::TOKEN, $text, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $limit = (int) $match[2];
                if ($limit > 255 || (isset($limits[$match[1]]) && $limits[$match[1]] !== $limit)) throw new BadRequest('Email parameter declarations must use consistent limits from 1 to 255.');
                $limits[$match[1]] = $limit;
            }
            preg_match_all('/\{.*?\}/s', $text, $helpers);
            foreach ($helpers[0] as $helper) if (str_contains($helper, '[[nexa:')) throw new BadRequest('Parameters cannot be native template helper arguments.');
        }
        if (count($limits) > 20) throw new BadRequest('At most 20 email parameters are allowed.');
        if ($limits) {
            if (mb_strlen($subject) > 255 || mb_strlen($preheader) > 255) throw new BadRequest('Parameterized subjects and preheaders must fit within 255 characters.');
            if (preg_match('/<[^>]*\[\[nexa:/s', $body)) throw new BadRequest('Email parameters cannot appear inside HTML markup.');
            $doc = $this->document($body);
            $xpath = new DOMXPath($doc);
            foreach ($xpath->query('//@* | //comment() | //processing-instruction() | //script//text() | //style//text()') as $node) {
                if (str_contains($node->nodeValue ?? '', '[[nexa:')) throw new BadRequest('Email parameters may appear only in visible text, subject or preheader.');
            }
            preg_match_all(self::TOKEN, $body, $sourceTokens);
            $textTokens = [];
            foreach ($xpath->query('//text()') as $node) {
                preg_match_all(self::TOKEN, $node->nodeValue ?? '', $found);
                array_push($textTokens, ...$found[0]);
            }
            sort($sourceTokens[0]); sort($textTokens);
            if ($sourceTokens[0] !== $textTokens) throw new BadRequest('Email parameter declarations must remain complete text nodes, not markup.');
        }
        return (object) $limits;
    }

    /** @return array{subject:string,bodyHtml:string,preheader:string,fingerprint:string} */
    public function resolve(string $subject, string $body, string $preheader, stdClass $input): array
    {
        $validated = $this->contract->validate($this->schema($subject, $body, $preheader), $input);
        $replace = static fn (string $text): string => preg_replace_callback(self::TOKEN, static fn (array $match): string => $validated['values'][$match[1]], $text);
        // Replace source text spans only. Escaping prevents supplied text from becoming HTML.
        $html = preg_replace_callback(self::TOKEN, static fn (array $match): string => htmlspecialchars($validated['values'][$match[1]], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $body);
        if (mb_strlen($replace($subject)) > 255 || mb_strlen($replace($preheader)) > 255) throw new BadRequest('Resolved subject or preheader exceeds 255 characters.');
        return ['subject' => $replace($subject), 'bodyHtml' => $html, 'preheader' => $replace($preheader), 'fingerprint' => $validated['fingerprint']];
    }

    public function requireResolved(string $subject, string $body, string $preheader = ''): void
    {
        if ((array) $this->schema($subject, $body, $preheader)) throw new BadRequest('This email requires parameters. Use the reusable-send API with the declared values.');
    }

    private function document(string $body): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new DOMDocument();
            $doc->loadHTML('<?xml encoding="UTF-8">' . $body, LIBXML_NONET);
            return $doc;
        } finally {libxml_clear_errors(); libxml_use_internal_errors($previous);}
    }
}
