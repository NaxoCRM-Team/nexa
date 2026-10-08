<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail;

use Espo\Core\Exceptions\BadRequest;
use stdClass;

/** Pure validation: caller data never becomes template source or renderer context. */
final class MarketingParameterContract
{
    /**
     * @param stdClass $schema Approved parameter names mapped to their maximum text length.
     * @return array{values: array<string, string>, fingerprint: string}
     */
    public function validate(stdClass $schema, stdClass $input): array
    {
        $limits = get_object_vars($schema);
        $values = get_object_vars($input);
        if (count($limits) > 20 || count($values) > 20) {
            throw new BadRequest('At most 20 email parameters are allowed.');
        }
        foreach ($limits as $name => $limit) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $name) ||
                !is_int($limit) || $limit < 1 || $limit > 255) {
                throw new BadRequest('The approved email parameter contract is invalid.');
            }
        }
        if (array_diff_key($values, $limits) || array_diff_key($limits, $values)) {
            throw new BadRequest('Provide exactly the parameters declared by the approved email.');
        }
        $bytes = 0;
        foreach ($values as $name => $value) {
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') ||
                mb_strlen($value, 'UTF-8') > $limits[$name] || trim($value) === '' ||
                preg_match('/[\x00-\x1f\x7f{}<>]|\p{Cf}/u', $value)) {
                throw new BadRequest('Email parameter ' . $name . ' must be bounded plain text without template markers or control characters.');
            }
            $bytes += strlen($value);
        }
        if ($bytes > 4096) {
            throw new BadRequest('Email parameters exceed the total size limit.');
        }
        ksort($values, SORT_STRING);
        ksort($limits, SORT_STRING);
        $canonical = json_encode(['schema' => (object) $limits, 'values' => (object) $values], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        return ['values' => $values, 'fingerprint' => hash('sha256', $canonical)];
    }
}
