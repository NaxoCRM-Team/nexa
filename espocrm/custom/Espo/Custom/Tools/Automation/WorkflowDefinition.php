<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Automation;

use Espo\Core\Exceptions\BadRequest;

/** Immutable graph validation, not action execution or permission authorization. */
final class WorkflowDefinition
{
    public const TRIGGERS = ['manual', 'form.submitted', 'page.viewed', 'asset.downloaded', 'email.opened', 'email.clicked', 'email.replied', 'api.event', 'contact.created', 'contact.updated', 'contact.field_changed', 'segment.entered', 'segment.exited', 'score.reached', 'lifecycle.changed', 'opportunity.stage_changed', 'activity.completed', 'date.reached', 'conversation.received'];
    public const ACTIONS = ['email.send', 'segment.add', 'segment.remove', 'score.increase', 'score.decrease', 'lifecycle.set', 'task.create', 'opportunity.create', 'case.create', 'owner.assign', 'webhook.send', 'notification.create','tags.add','tags.remove','contact.update','account.update','opportunity.update','team.notify'];

    public function validate(array $input): array
    {
        $this->keys($input, ['schemaVersion', 'trigger', 'entry', 'nodes', 'layout']);
        if (($input['schemaVersion'] ?? null) !== 1) $this->fail('Unsupported workflow schema version.');
        $trigger = $input['trigger'] ?? null;
        if (!is_array($trigger)) $this->fail('Choose an enrollment trigger.');
        $this->keys($trigger, ['event', 'reenrollment', 'pageUrl', 'field', 'segmentId', 'threshold']);
        if (!in_array($trigger['event'] ?? null, self::TRIGGERS, true)) $this->fail('Unknown enrollment trigger.');
        if (!in_array($trigger['reenrollment'] ?? null, ['never', 'after_completion'], true)) $this->fail('Choose a reenrollment policy.');
        foreach (['field'=>['contact.field_changed','date.reached'],'threshold'=>['score.reached']] as $key=>$events) {
            if (in_array($trigger['event'],$events,true) && !array_key_exists($key,$trigger)) $this->fail('Missing enrollment setting: ' . $key);
            if (array_key_exists($key,$trigger) && !in_array($trigger['event'],$events,true)) $this->fail('Enrollment setting does not match this trigger.');
        }
        if (array_key_exists('field',$trigger) && (!is_string($trigger['field']) || !preg_match($trigger['event']==='date.reached'?'/^custom:[a-z][a-z0-9_]{0,63}$/D':'/^[a-z][a-zA-Z0-9]{0,63}$/D',$trigger['field']))) $this->fail('Choose a supported Contact enrollment property.');
        if (array_key_exists('threshold',$trigger)) $this->integer($trigger['threshold'],0,1000000,'Score threshold');
        if (in_array($trigger['event'],['segment.entered','segment.exited'],true)) {
            if (!is_string($trigger['segmentId'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$trigger['segmentId'])) $this->fail('Choose an enrollment list.');
        } elseif (array_key_exists('segmentId',$trigger)) $this->fail('Only list enrollment supports a list identifier.');
        if (array_key_exists('pageUrl', $trigger)) {
            $url = $trigger['pageUrl'];
            if ($trigger['event'] !== 'page.viewed' || !is_string($url) || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) || preg_match('/[\x00-\x20\x7f]/', $url) || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) $this->fail('Choose an exact HTTP(S) page URL without credentials or a fragment.');
        }
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
        if (array_key_exists('layout', $input)) {
            if (!is_array($input['layout']) || count($input['layout']) > count($graph)) $this->fail('Invalid canvas layout.');
            foreach ($input['layout'] as $id => $position) {
                if (!isset($graph[$id]) || !is_array($position) || array_diff(array_keys($position), ['x','y']) || count($position) !== 2) $this->fail('Canvas positions must reference existing steps.');
                foreach (['x','y'] as $axis) if (!is_int($position[$axis]) || $position[$axis] < 0 || $position[$axis] > 25000) $this->fail('Canvas positions must be bounded integer coordinates.');
            }
        }
        // Canvas order and JSON key order are not execution identity.
        ksort($graph, SORT_STRING);
        $input['nodes'] = array_values($graph);
        foreach ($input['nodes'] as &$node) if ($node['type'] === 'business_hours') sort($node['weekdays'], SORT_NUMERIC);
        unset($node);
        $canonical = $this->canonical($input);
        $json = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > 131072) $this->fail('Workflow definition exceeds 128 KiB.');
        $execution = $canonical;
        unset($execution['layout']);
        return ['definition' => $canonical, 'fingerprint' => hash('sha256', json_encode($execution, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))];
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
            case 'wait_until':
                $this->keys($node,['id','type','at','next']);
                $this->required($node,['at','next']);
                \Espo\Custom\Tools\MarketingEmail\TemplateHelpers\DateTimeComparison::canonical($node['at']);
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
            case 'ab_split':
                $this->keys($node, ['id','type','weightA','yes','no']);
                $this->integer($node['weightA'] ?? null,1,99,'Variant A percentage');
                $this->required($node,['yes','no']);
                if ($node['yes'] === $node['no']) $this->fail('An A/B split needs two different branches.');
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
            'email.send' => ['sourceId', 'version', 'parameters', 'parameterBindings'],
            'segment.add', 'segment.remove' => ['segmentId'],
            'score.increase', 'score.decrease' => ['amount'],
            'lifecycle.set' => ['stage'],
            'task.create' => ['name', 'ownerId'],
            'opportunity.create' => ['name', 'ownerId', 'pipelineId', 'stage', 'amount', 'currency', 'closeDate'],
            'case.create' => ['name', 'ownerId', 'priority', 'category'],
            'owner.assign' => ['ownerId'],
            'webhook.send' => ['webhookId'],
            'notification.create' => ['ownerId', 'message'],
            'tags.add','tags.remove'=>['tag'],
            'contact.update','account.update'=>['field','value'],
            'opportunity.update'=>['opportunityId','field','value'],
            'team.notify'=>['teamId','message'],
        };
        $this->keys($settings, $allowed);
        $this->required($settings, array_diff($allowed, ['parameters', 'parameterBindings', 'ownerId', 'priority', 'category']));
        if ($action === 'email.send') (new WorkflowEmailBindings())->validate($settings);
        if (in_array($action, ['owner.assign', 'notification.create'], true)) $this->required($settings, ['ownerId']);
        foreach ($settings as $key => $value) {
            if ($key === 'parameterBindings') continue;
            if ($key==='value') {$this->text($value,1000);continue;}
            if ($key==='tag') {$this->text($value,80);continue;}
            if ($key==='field' && (!is_string($value) || !preg_match('/^[a-z][a-zA-Z0-9]{0,63}$/D',$value)))$this->fail('Choose an approved property.');
            if ($action === 'case.create' && $key === 'category' && $value === '') continue;
            if (str_ends_with($key, 'Id')) {
                if (!is_string($value) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value)) $this->fail('Invalid action record identifier.');
            } elseif ($action === 'opportunity.create' && $key === 'amount') {
                if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D', $value)) $this->fail('Opportunity amount must be a nonnegative decimal with at most two decimal places.');
            } elseif ($action === 'opportunity.create' && $key === 'currency') {
                if (!is_string($value) || !preg_match('/^[A-Z]{3}$/D', $value)) $this->fail('Choose an enabled currency.');
            } elseif ($action === 'opportunity.create' && $key === 'closeDate') {
                if (!is_string($value) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) $this->fail('Choose a valid expected close date.');
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

    private function condition(mixed $condition, bool $leaf = false): void
    {
        if (!is_array($condition)) $this->fail('A branch needs a condition.');
        if (array_key_exists('conditions', $condition)) {
            $this->keys($condition, ['matchMode', 'conditions']);
            if ($leaf || !in_array($condition['matchMode'] ?? null, ['all', 'any','sequence'], true) || !is_array($condition['conditions']) || !array_is_list($condition['conditions']) || count($condition['conditions']) < 1 || count($condition['conditions']) > 6) $this->fail('Choose all, any or an event sequence with one to six conditions.');
            foreach ($condition['conditions'] as $item) $this->condition($item, true);
            if($condition['matchMode']==='sequence') {
                if(count($condition['conditions'])<2)$this->fail('A sequence needs at least two events.');
                foreach($condition['conditions'] as $item)if(!str_starts_with($item['field'],'behavior:') || $item['operator']!=='within_days' || $item['value']!==$condition['conditions'][0]['value'])$this->fail('Sequences require recorded events with the same day window.');
            }
            return;
        }
        $this->keys($condition, ['field', 'operator', 'value', 'count', 'pageUrl','campaignId']);
        if (!is_string($condition['field'] ?? null) || !preg_match('/^(?:[a-z][a-zA-Z0-9]{0,63}|custom:[a-z][a-z0-9_]{0,63}|(?:account|opportunity):[a-z][a-zA-Z0-9]{0,63}|segment:[a-zA-Z0-9_-]{1,64}|behavior:[a-z]+\.[a-z]+)$/D', $condition['field'])) $this->fail('Invalid condition property.');
        if (str_starts_with($condition['field'], 'behavior:')) {
            if(isset($condition['campaignId']) && (!in_array($condition['field'],['behavior:email.opened','behavior:email.clicked'],true) || !is_string($condition['campaignId']) || !preg_match('/^[a-zA-Z0-9_-]{1,24}$/D',$condition['campaignId'])))$this->fail('Campaign filters require a native campaign and an email open or click event.');
            if (array_key_exists('pageUrl',$condition)) {
                if ($condition['field']!=='behavior:page.viewed') $this->fail('A page URL requires a website visit condition.');
                \Espo\Custom\Tools\MarketingEmail\TemplateHelpers\BehaviorCondition::pageUrl($condition['pageUrl']);
            }
            if (!in_array($condition['operator'] ?? null, ['within_days', 'not_within_days', 'count_at_least', 'count_at_most', 'count_equals'], true) || !is_string($condition['value'] ?? null) || !preg_match('/^[1-9][0-9]{0,2}$/D', $condition['value']) || (int) $condition['value'] > 365) $this->fail('Behavior conditions need a window from 1 to 365 days.');
            if (str_starts_with($condition['operator'], 'count_')) $this->integer($condition['count'] ?? null, 0, 10000, 'Event count');
            elseif (array_key_exists('count', $condition)) $this->fail('An event count requires a count comparison.');
            return;
        }
        if (array_key_exists('count', $condition)) $this->fail('Only behavior conditions support event counts.');
        if(array_key_exists('campaignId',$condition))$this->fail('Only supported email behavior conditions accept campaigns.');
        if (array_key_exists('pageUrl', $condition)) $this->fail('Only website visit conditions support a page URL.');
        if (!in_array($condition['operator'] ?? null, ['equals', 'not_equals', 'has_value', 'is_empty', 'greater_than', 'less_than', 'before', 'after', 'on_or_before', 'on_or_after', ...\Espo\Custom\Tools\MarketingEmail\TemplateHelpers\DateComparison::RELATIVE_OPERATORS], true)) $this->fail('Unsupported condition comparison.');
        if (in_array($condition['operator'], ['has_value', 'is_empty'], true)) {
            if (array_key_exists('value', $condition)) $this->fail('Empty-value comparisons do not take a value.');
        } else {
            $this->required($condition, ['value']);
            $value = $condition['value'];
            if (in_array($condition['operator'], \Espo\Custom\Tools\MarketingEmail\TemplateHelpers\DateComparison::RELATIVE_OPERATORS, true)) \Espo\Custom\Tools\MarketingEmail\TemplateHelpers\DateComparison::windowDays($value);
            if (in_array($condition['operator'], ['before', 'after', 'on_or_before', 'on_or_after'], true)) {
                if (is_string($value) && str_contains($value, 'T')) \Espo\Custom\Tools\MarketingEmail\TemplateHelpers\DateTimeComparison::canonical($value);
                else \Espo\Custom\Tools\MarketingEmail\TemplateHelpers\DateComparison::canonical($value);
            }
            if (!is_string($value) && !is_int($value) && !is_bool($value)) $this->fail('Condition value must be text, an integer or a boolean.');
            if (is_string($value)) $this->text($value, 255);
            if (in_array($condition['operator'], ['greater_than', 'less_than'], true) && (!is_int($value) && (!is_string($value) || !preg_match('/^-?[0-9]{1,22}(?:\.[0-9]{1,8})?$/D', $value)))) $this->fail('Numeric comparisons need an exact decimal value.');
        }
    }

    private function edges(array $node): array
    {
        return match ($node['type']) {'end' => [], 'branch', 'wait_condition', 'ab_split' => [$node['yes'], $node['no']], default => [$node['next']]};
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
