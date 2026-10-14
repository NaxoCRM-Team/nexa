<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Automation;

use Espo\Core\Exceptions\{BadRequest, Forbidden};
use stdClass;

/** Resolve literal parameters only; the native email renderer owns HTML escaping. */
final class WorkflowEmailBindings
{
    public function validate(array $settings): void
    {
        $bindings = $settings['parameterBindings'] ?? new stdClass();
        if (!$bindings instanceof stdClass || count((array) $bindings) > 20) throw new BadRequest('Email bindings must be an object with at most 20 entries.');
        $fixed = (array) ($settings['parameters'] ?? new stdClass());
        if (count($fixed) + count((array) $bindings) > 20) throw new BadRequest('At most 20 email parameters are allowed.');
        foreach ((array) $bindings as $name => $binding) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/D', (string) $name) || array_key_exists($name, $fixed)) throw new BadRequest('Each email parameter needs exactly one value source.');
            if (!$binding instanceof stdClass || array_diff(array_keys((array) $binding), ['field', 'fallback'])) throw new BadRequest('Invalid email property binding.');
            if (!is_string($binding->field ?? null) || !preg_match('/^(?:[a-z][a-zA-Z0-9]{0,63}|custom:[a-z][a-z0-9_]{0,63})$/D', $binding->field)) throw new BadRequest('Choose a Contact property for the email parameter.');
            if (property_exists($binding, 'fallback')) $this->text($binding->fallback);
        }
    }

    public function resolve(array $settings, array $fields, ?callable $read = null): stdClass
    {
        $this->validate($settings);
        $values = (array) ($settings['parameters'] ?? new stdClass());
        $catalogue = array_column($fields, null, 'name');
        foreach ((array) ($settings['parameterBindings'] ?? new stdClass()) as $name => $binding) {
            $field = $catalogue[$binding->field] ?? null;
            if (!$field || $field['type'] === 'behavior') throw new Forbidden('An email property is unavailable to the execution user.');
            // Publication checks the complete contract; record values are reread at execution.
            $value = $read === null ? ($binding->fallback ?? 'x') : $read($field);
            if ($value === null || $value === '') $value = $binding->fallback ?? null;
            if ($value === null) throw new BadRequest('An email parameter property has no value or fallback.');
            if (is_int($value)) $value = (string) $value;
            if (!is_string($value)) throw new BadRequest('An email parameter property must be a supported scalar.');
            $this->text($value);
            $values[$name] = $value;
        }
        return (object) $values;
    }

    private function text(mixed $value): void
    {
        (new \Espo\Custom\Tools\MarketingEmail\MarketingParameterContract())->validate((object) ['value'=>255], (object) ['value'=>$value]);
    }
}
