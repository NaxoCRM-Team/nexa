<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\Exceptions\BadRequest;

/** Pure transition planning. Persistence must authorize and lock the conversation first. */
final class ChatBotTransition
{
    public function advance(array $definition, ?string $waitingAt = null, ?int $choice = null): array
    {
        $validated = (new ChatBotDefinition())->validate($definition);
        $graph = array_column($validated['definition']['nodes'], null, 'id');
        $id = $validated['definition']['entry'];
        if ($waitingAt !== null) {
            $node = $graph[$waitingAt] ?? null;
            if (!$node || $node['type'] !== 'choice' || $choice === null || !isset($node['options'][$choice])) throw new BadRequest('Choose an available chat option.');
            $id = $node['options'][$choice]['next'];
        } elseif ($choice !== null) throw new BadRequest('No chat question is awaiting an answer.');
        $messages = [];
        for ($steps = 0; $steps < 50; $steps++) {
            $node = $graph[$id];
            if (isset($node['body'])) $messages[] = ['step' => $id, 'body' => $node['body']];
            if ($node['type'] === 'message') { $id = $node['next']; continue; }
            return [
                'fingerprint' => $validated['fingerprint'],
                'state' => match ($node['type']) { 'choice' => 'waiting', 'handoff' => 'handoff', default => 'completed' },
                'step' => $id,
                'messages' => $messages,
                'choices' => $node['type'] === 'choice' ? array_column($node['options'], 'label') : [],
            ];
        }
        throw new BadRequest('Chat flow exceeded its step limit.');
    }
}
