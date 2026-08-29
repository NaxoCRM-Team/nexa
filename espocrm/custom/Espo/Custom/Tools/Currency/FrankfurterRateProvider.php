<?php

namespace Espo\Custom\Tools\Currency;

use RuntimeException;

/** Fetches daily reference rates without exposing an external request to the browser. */
final class FrankfurterRateProvider
{
    private const ENDPOINT = 'https://api.frankfurter.dev/v2/rates';

    /**
     * @param array<int, string> $currencies
     * @return array{provider: string, effectiveDate: string, rates: array<string, float>}
     */
    public function fetch(string $base, array $currencies): array
    {
        $quotes = array_values(array_filter($currencies, static fn (string $code): bool => $code !== $base));

        if ($quotes === []) {
            return ['provider' => 'frankfurter', 'effectiveDate' => gmdate('Y-m-d'), 'rates' => [$base => 1.0]];
        }

        $url = self::ENDPOINT . '?' . http_build_query([
            'base' => $base,
            'quotes' => implode(',', $quotes),
        ]);
        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException('The exchange-rate provider could not be initialized.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: NexaCRM/1.0'],
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if (!is_string($body) || $status < 200 || $status >= 300) {
            throw new RuntimeException('Latest exchange rates are temporarily unavailable.' . ($error !== '' ? ' ' . $error : ''));
        }

        $rows = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($rows)) {
            throw new RuntimeException('The exchange-rate provider returned an invalid response.');
        }

        $rates = [$base => 1.0];
        $effectiveDate = null;

        foreach ($rows as $row) {
            if (!is_array($row) || ($row['base'] ?? null) !== $base) {
                continue;
            }

            $quote = strtoupper((string) ($row['quote'] ?? ''));
            $providerRate = (float) ($row['rate'] ?? 0);

            if (!in_array($quote, $quotes, true) || $providerRate <= 0) {
                continue;
            }

            // Frankfurter returns quote units per base. EspoCRM stores base
            // units per quote, so the provider value must be inverted.
            $rates[$quote] = (float) number_format(1 / $providerRate, 10, '.', '');
            $effectiveDate ??= (string) ($row['date'] ?? '');
        }

        $missing = array_values(array_diff($quotes, array_keys($rates)));
        if ($missing !== []) {
            throw new RuntimeException('No current provider rate is available for: ' . implode(', ', $missing) . '.');
        }

        return [
            'provider' => 'frankfurter',
            'effectiveDate' => $effectiveDate ?: gmdate('Y-m-d'),
            'rates' => $rates,
        ];
    }
}
