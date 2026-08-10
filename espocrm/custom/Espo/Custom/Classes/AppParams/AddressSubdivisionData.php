<?php

declare(strict_types=1);

namespace Espo\Custom\Classes\AppParams;

use Espo\Tools\App\AppParam;
use JsonException;

final class AddressSubdivisionData implements AppParam
{
    /**
     * Supplies the client with locally hosted region data so address entry never
     * depends on a third-party API or sends customer-entered addresses elsewhere.
     *
     * @return array{byCountry: array<string, string[]>}
     * @throws JsonException
     */
    public function get(): array
    {
        $path = dirname(__DIR__, 2) . '/Resources/data/address-subdivisions.json';
        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            return ['byCountry' => []];
        }

        /** @var array{byCountry: array<string, string[]>} */
        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }
}
