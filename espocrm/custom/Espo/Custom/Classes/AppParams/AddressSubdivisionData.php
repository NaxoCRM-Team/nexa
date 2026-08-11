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
     * @return array{byCountry: array<string, string[]>, countryCodes: array<string, string>}
     * @throws JsonException
     */
    public function get(): array
    {
        $path = dirname(__DIR__, 2) . '/Resources/data/address-subdivisions.json';
        $contents = file_get_contents($path);

        if (!is_string($contents)) {
            return ['byCountry' => [], 'countryCodes' => []];
        }

        /** @var array{byCountry: array<string, string[]>} $data */
        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $countryPath = dirname(__DIR__, 5) . '/application/Espo/Resources/data/locale/en_US/countryList.json';
        $countryContents = file_get_contents($countryPath);
        $data['countryCodes'] = [];

        if (!is_string($countryContents)) {
            return $data;
        }

        /** @var array<int, array{name?: string, code?: string}> $countryList */
        $countryList = json_decode($countryContents, true, 512, JSON_THROW_ON_ERROR);

        foreach ($countryList as $country) {
            $name = $country['name'] ?? null;
            $code = $country['code'] ?? null;

            if (is_string($name) && is_string($code) && strlen($code) === 2) {
                $data['countryCodes'][$name] = strtoupper($code);
            }
        }

        return $data;
    }
}
