<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Automation;

use Espo\Core\Exceptions\BadRequest;

/** Immutable graph validation, not action execution or permission authorization. */
final class WorkflowDefinition
{
    public const TRIGGERS = ['manual', 'form.submitted', 'page.viewed', 'asset.downloaded', 'email.opened', 'email.clicked', 'email.replied', 'api.event', 'contact.created', 'contact.updated', 'score.reached', 'lifecycle.changed', 'opportunity.stage_changed', 'date.reached'];
    public const ACTIONS = ['email.send', 'segment.add', 'segment.remove', 'score.increase', 'score.decrease', 'lifecycle.set', 'task.create', 'opportunity.create', 'case.create', 'owner.assign', 'webhook.send', 'notification.create'];

    public function validate(array $input): array
    {
        $this->keys($input, ['schemaVersion', 'trigger', 'entry', 'nodes']);
        if (($input['schemaVersion'] ?? null) !== 1) $this->fail('Unsupported workflow schema version.');
        $trigger = $input['trigger'] ?? null;
        if (!is_array($trigger)) $this->fail('Choose an enrollment trigger.');
        $this->keys($trigger, ['event', 'reenrollment']);
        if (!in_array($trigger['event'] ?? null, self::TRIGGERS, true)) $this->fail('Unknown enrollment trigger.');
        if (!in_array($trigger['reenrollment'] ?? null, ['never', 'after_completion'], true)) $this->fail('Choose a reenrollment policy.');
        $nodes = $input['nodes'] ?? null;
        if (!is_array($nodes) || !array_is_list($nodes) || count($nodes) < 1 || count($nodes) > 100) $this->fail('A workflow needs 1 to 100 steps.');
        $graph = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) $this->fail('Invalid workflow step.');
            $id = $node['id'] ?? null;
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $id) || isset($graph[$id])) $this->fail('Each step needs a unique identifier.');
            $this->node($node);
            $graph[$id] = $node;
        }
        $entry = $input['entry'] ?? null;
        if (!is_string($entry) || !isset($graph[$entry])) $this->fail('Choose an existing starting step.');
        foreach ($graph as $node) foreach ($this->edges($node) as $target) {
            if (!is_string($target) || !isset($graph[$target])) $this->fail('Every connection must point to an existing step.');
        }
        $visited = $active = [];
        $walk = function (string $id) use (&$walk, &$visited, &$active, $graph): void {
            if (isset($active[$id])) $this->fail('Workflow cycles are not allowed. Use a bounded wait step.');
            if (isset($visited[$id])) return;
            $active[$id] = true;
            foreach ($this->edges($graph[$id]) as $target) $walk($target);
            unset($active[$id]);
            $visited[$id] = true;
        };
        $walk($entry);
        if (count($visited) !== count($graph)) $this->fail('Remove or connect unreachable steps.');
        // Canvas order and JSON key order are not execution identity.
        ksort($graph, SORT_STRING);
        $input['nodes'] = array_values($graph);
        foreach ($input['nodes'] as &$node) if ($node['type'] === 'business_hours') sort($node['weekdays'], SORT_NUMERIC);
        unset($node);
        $canonical = $this->canonical($input);
        $json = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 131072) $this->fail('Workflow definition exceeds 128 KiB.');
        return ['definition' => $canonical, 'fingerprint' => hash('sha256', $json)];
    }

    private function node(array $node): void
    {
        switch ($node['type'] ?? null) {
            case 'end':
                $this->keys($node, ['id', 'type']);
                break;
            case 'delay':
                $this->keys($node, ['id', 'type', 'seconds', 'next']);
                $this->integer($node['seconds'] ?? null, 1, 31536000, 'Wait duration');
                $this->required($node, ['next']);
                break;
            case 'branch':
            case 'wait_condition':
                $wait = $node['type'] === 'wait_condition';
                $this->keys($node, $wait ? ['id', 'type', 'condition', 'timeoutSeconds', 'pollSeconds', 'yes', 'no'] : ['id', 'type', 'condition', 'yes', 'no']);
                $this->required($node, ['yes', 'no']);
                $this->condition($node['condition'] ?? null);
                if ($wait) {
                    $this->integer($node['timeoutSeconds'] ?? null, 60, 31536000, 'Wait timeout');
                    $this->integer($node['pollSeconds'] ?? null, 60, 86400, 'Check interval');
                    if ($node['pollSeconds'] > $node['timeoutSeconds']) $this->fail('Check interval exceeds the wait timeout.');
                }
                break;
            case 'business_hours':
                $this->keys($node, ['id', 'type', 'timezone', 'weekdays', 'start', 'end', 'next']);
                $this->required($node, ['next']);
                if (!is_string($node['timezone'] ?? null) || !in_array($node['timezone'], \DateTimeZone::listIdentifiers(), true)) $this->fail('Choose an IANA time zone.');
                $days = $node['weekdays'] ?? null;
                if (!is_array($days) || !array_is_list($days) || !$days || count($days) > 7) $this->fail('Choose working weekdays.');
                foreach ($days as $day) $this->integer($day, 1, 7, 'Working weekday');
                if (count(array_unique($days)) !== count($days)) $this->fail('Working weekdays must be distinct.');
                foreach (['start', 'end'] as $key) if (!is_string($node[$key] ?? null) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $node[$key])) $this->fail('Business times must use HH:MM.');
                if ($node['start'] >= $node['end']) $this->fail('Working hours must end after they start on the same day.');
                break;
            case 'action':
                $this->keys($node, ['id', 'type', 'action', 'settings', 'maxAttempts', 'retrySeconds', 'next']);
                $this->required($node, ['next']);
                if (!in_array($node['action'] ?? null, self::ACTIONS, true)) $this->fail('Unsupported workflow action.');
                $this->integer($node['maxAttempts'] ?? null, 1, 5, 'Maximum attempts');
                $this->integer($node['retrySeconds'] ?? null, 60, 86400, 'Retry interval');
                $this->action($node['action'], $node['settings'] ?? null);
                break;
            default:
                $this->fail('Unsupported workflow step type.');
        }
    }

    private function action(string $action, mixed $settings): void
    {
        if (!is_array($settings)) $this->fail('Action settings are required.');
        $allowed = match ($action) {
            'email.send' => ['sourceId', 'version', 'parameters'],
            'segment.add', 'segment.remove' => ['segmentId'],
            'score.increase', 'score.decrease' => ['amount'],
            'lifecycle.set' => ['stage'],
            'task.create', 'opportunity.create', 'case.create' => ['name', 'ownerId'],
            'owner.assign' => ['ownerId'],
            'webhook.send' => ['webhookId'],
            'notification.create' => ['ownerId', 'message'],
        };
        $this->keys($settings, $allowed);
        $this->required($settings, array_diff($allowed, ['parameters', 'ownerId']));
        if (in_array($action, ['owner.assign', 'notification.create'], true)) $this->required($settings, ['ownerId']);
        foreach ($settings as $key => $value) {
            if (str_ends_with($key, 'Id')) {
                if (!is_string($value) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value)) $this->fail('Invalid action record identifier.');
            } elseif ($key === 'version') {
                $this->integer($value, 1, 2147483647, 'Approved email version');
            } elseif ($key === 'amount') {
                $this->integer($value, 1, 1000, 'Score adjustment');
            } elseif ($key === 'parameters') {
                if (!$value instanceof \stdClass || count((array) $value) > 20) $this->fail('Email parameters must be an object with at most 20 entries.');
                foreach ((array) $value as $name => $text) {
                    if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/D', (string) $name)) $this->fail('Invalid email parameter name.');
                    $this->text($text, 255);
                }
            } else $this->text($value, $key === 'message' ? 1000 : 255);
        }
    }

    private function condition(mixed $condition): void
    {
        if (!is_array($condition)) $this->fail('A branch needs a condition.');
        $this->keys($condition, ['field', 'operator', 'value']);
        if (!is_string($condition['field'] ?? null) || !preg_match('/^(?:[a-z][a-zA-Z0-9]{0,63}|custom:[a-z][a-z0-9_]{0,63})$/D', $condition['field'])) $this->fail('Invalid condition property.');
        if (!in_array($condition['operator'] ?? null, ['equals', 'not_equals', 'has_value', 'is_empty', 'greater_than', 'less_than'], true)) $this->fail('Unsupported condition comparison.');
        if (in_array($condition['operator'], ['has_value', 'is_empty'], true)) {
            if (array_key_exists('value', $condition)) $this->fail('Empty-value comparisons do not take a value.');
        } else {
            $this->required($condition, ['value']);
            $value = $condition['value'];
            if (!is_string($value) && !is_int($value) && !is_bool($value)) $this->fail('Condition value must be text, an integer or a boolean.');
            if (is_string($value)) $this->text($value, 255);
            if (in_array($condition['operator'], ['greater_than', 'less_than'], true) && (!is_int($value) && (!is_string($value) || !preg_match('/^-?[0-9]{1,22}(?:\.[0-9]{1,8})?$/D', $value)))) $this->fail('Numeric comparisons need an exact decimal value.');
        }
    }

    private function edges(array $node): array
    {
        return match ($node['type']) {'end' => [], 'branch', 'wait_condition' => [$node['yes'], $node['no']], default => [$node['next']]};
    }

    private function keys(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed)) $this->fail('Unknown workflow setting.');
    }

    private function required(array $input, array $keys): void
    {
        foreach ($keys as $key) if (!array_key_exists($key, $input) || $input[$key] === null) $this->fail('Missing workflow setting: ' . $key . '.');
    }

    private function integer(mixed $value, int $min, int $max, string $label): void
    {
        if (!is_int($value) || $value < $min || $value > $max) $this->fail($label . ' must be an integer between ' . $min . ' and ' . $max . '.');
    }

    private function text(mixed $value, int $max): void
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || trim($value) === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/u', $value)) $this->fail('Invalid action text.');
    }

    private function canonical(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $map = (array) $value;
            ksort($map, SORT_STRING);
            return (object) array_map(fn ($item) => $this->canonical($item), $map);
        }
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value, SORT_STRING);
        return array_map(fn ($item) => $this->canonical($item), $value);
    }

    private function fail(string $message): never {throw new BadRequest($message);}
}
