<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\Exceptions\BadRequest;

/** Conversational steps only; CRM actions remain owned by the automation engine. */
final class ChatBotDefinition
{
    public function validate(array $input): array
    {
        $this->keys($input, ['schemaVersion', 'entry', 'nodes']);
        if (($input['schemaVersion'] ?? null) !== 1) $this->fail('Unsupported chat flow version.');
        $nodes = $input['nodes'] ?? null;
        if (!is_array($nodes) || !array_is_list($nodes) || !$nodes || count($nodes) > 50) $this->fail('Choose between 1 and 50 chat steps.');
        $graph = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) $this->fail('Invalid chat step.');
            $id = $node['id'] ?? null;
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $id) || isset($graph[$id])) $this->fail('Each chat step needs a unique identifier.');
            switch ($node['type'] ?? null) {
                case 'message':
                    $this->keys($node, ['id', 'type', 'body', 'next']);
                    $this->text($node['body'] ?? null, 4000);
                    break;
                case 'choice':
                    $this->keys($node, ['id', 'type', 'body', 'options']);
                    $this->text($node['body'] ?? null, 1000);
                    $options = $node['options'] ?? null;
                    if (!is_array($options) || !array_is_list($options) || count($options) < 2 || count($options) > 8) $this->fail('A question needs 2 to 8 choices.');
                    $labels = [];
                    foreach ($options as $option) {
                        if (!is_array($option)) $this->fail('Invalid chat choice.');
                        $this->keys($option, ['label', 'next']);
                        $this->text($option['label'] ?? null, 80);
                        $label = mb_strtolower(trim($option['label']));
                        if (isset($labels[$label])) $this->fail('Choice labels must be distinct.');
                        $labels[$label] = true;
                    }
                    break;
                case 'handoff':
                    $this->keys($node, ['id', 'type', 'body']);
                    $this->text($node['body'] ?? null, 1000);
                    break;
                case 'end':
                    $this->keys($node, ['id', 'type']);
                    break;
                default: $this->fail('Unsupported chat step.');
            }
            $graph[$id] = $node;
        }
        $entry = $input['entry'] ?? null;
        if (!is_string($entry) || !isset($graph[$entry])) $this->fail('Choose an existing starting step.');
        foreach ($graph as $node) foreach ($this->edges($node) as $target) {
            if (!is_string($target) || !isset($graph[$target])) $this->fail('Connect every chat step to an existing step.');
        }
        $visited = $active = [];
        $walk = function (string $id) use (&$walk, &$visited, &$active, $graph): void {
            if (isset($active[$id])) $this->fail('Chat flows cannot contain loops.');
            if (isset($visited[$id])) return;
            $active[$id] = true;
            foreach ($this->edges($graph[$id]) as $target) $walk($target);
            unset($active[$id]);
            $visited[$id] = true;
        };
        $walk($entry);
        if (count($visited) !== count($graph)) $this->fail('Connect or remove unused chat steps.');
        ksort($graph, SORT_STRING);
        $definition = ['schemaVersion' => 1, 'entry' => $entry, 'nodes' => array_values($graph)];
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value, SORT_STRING);
            return array_map($canonical, $value);
        };
        $definition = $canonical($definition);
        $json = json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 65536) $this->fail('Chat flow exceeds 64 KiB.');
        return ['definition' => $definition, 'fingerprint' => hash('sha256', $json)];
    }

    private function edges(array $node): array
    {
        return match ($node['type']) {
            'message' => [$node['next'] ?? null],
            'choice' => array_map(fn ($option) => $option['next'] ?? null, $node['options']),
            default => [],
        };
    }
    private function keys(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed)) $this->fail('Unknown chat flow setting.');
    }
    private function text(mixed $text, int $max): void
    {
        if (!is_string($text) || !mb_check_encoding($text, 'UTF-8') || trim($text) === '' || mb_strlen($text) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text)) $this->fail('Enter valid chat text within the character limit.');
    }
    private function fail(string $message): never { throw new BadRequest($message); }
}
