<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail;

use Espo\Core\Acl;
use Espo\Core\Htmlizer\TemplateRenderer;
use Espo\Core\InjectableFactory;
use Espo\Core\Exceptions\{BadRequest, Forbidden};
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\{Config, Language, Metadata};
use Espo\Entities\User;
use stdClass;

/** Builds native conditional helpers; the native processor remains the only renderer. */
final class MarketingConditionalContentService
{
    private const FIELDS = ['firstName','lastName','title','department','accountName','addressCity','addressState',
        'addressCountry','website','contactSource','lifecycleStage','leadStatus','marketingStatus','customerStage','preferredTimezone','leadScore'];

    public function __construct(
        private TenantContextStore $contexts,
        private Acl $acl,
        private User $user,
        private Metadata $metadata,
        private Language $language,
        private Config $config,
        private EntityManager $em,
        private InjectableFactory $factory,
        private \Espo\Custom\Tools\Customization\CustomizationService $customization,
    ) {}

    public function catalog(): array
    {
        $this->requireAccess(); $fields = [];
        foreach (self::FIELDS as $name) {
            $definition = $this->metadata->get(['entityDefs','Contact','fields',$name]);
            if (!is_array($definition) || !$this->acl->checkField('Contact',$name) ||
                !in_array($definition['type'] ?? '', ['varchar','enum','url','link','int'], true)) continue;
            $options = [];
            foreach (($definition['options'] ?? []) as $value) {
                if (!is_string($value)) continue;
                $options[] = ['value'=>$value,'label'=>$this->language->translateOption($value,$name,'Contact')];
            }
            $fields[] = ['name'=>$name,'label'=>$this->language->translate($name,'fields','Contact'),'options'=>$options,'type'=>$definition['type']];
        }
        foreach ($this->customization->definitions('Contact')['fields'] as $field) {
            if (!$field['is_enabled'] || !in_array($field['data_type'],['text','single_select','boolean','number','currency','date','datetime'],true)) continue;
            $fields[] = ['name'=>'custom:' . $field['field_key'],'label'=>$field['label'] . ' (custom)',
                'type'=>in_array($field['data_type'],['date','datetime'],true) ? $field['data_type'] : (in_array($field['data_type'],['number','currency'],true) ? 'decimal' : (in_array($field['data_type'],['single_select','boolean'],true) ? 'enum' : 'varchar')),
                'options'=>$field['data_type'] === 'boolean' ? [['value'=>'true','label'=>'True'],['value'=>'false','label'=>'False']] : array_map(static fn (string $value): array => ['value'=>$value,'label'=>$value],$field['options']),
                'customKey'=>$field['field_key']];
        }
        foreach (['page.viewed'=>'Recorded website visit','email.opened'=>'Recorded marketing email open','email.clicked'=>'Recorded marketing email click','email.replied'=>'Recorded email reply'] as $event=>$label) {
            $fields[]=['name'=>'behavior:' . $event,'label'=>$label,'type'=>'behavior','options'=>[],'event'=>$event];
        }
        return ['fields'=>$fields];
    }

    public function compose(stdClass $input, ?string $decisionTag = null): array
    {
        if (isset($input->conditions)) return $this->composeGroup($input, $decisionTag);
        $catalog = $this->catalog();
        $field = $input->field ?? null; $operator = $input->operator ?? null;
        $definition = null;
        foreach ($catalog['fields'] as $item) if ($item['name'] === $field) {$definition = $item; break;}
        if (!$definition) throw new BadRequest('Choose an available Contact property.');
        $numeric = in_array($definition['type'],['int','decimal'],true);
        $behavior = $definition['type']==='behavior';
        $operators = in_array($definition['type'],['date','datetime'],true) ? ['equals','not_equals','before','after','on_or_before','on_or_after','has_value','no_value', ...TemplateHelpers\DateComparison::RELATIVE_OPERATORS] : ($behavior ? ['within_days','not_within_days','count_at_least','count_at_most','count_equals'] : ($numeric ? ['equals','not_equals','greater_than','at_least','less_than','at_most','has_value','no_value'] : ['equals','not_equals','has_value','no_value']));
        if (!in_array($operator,$operators,true)) throw new BadRequest('Choose a supported condition.');
        $countRule=$behavior && str_starts_with($operator,'count_');
        if ($countRule && (!is_int($input->count ?? null) || $input->count<0 || $input->count>10000)) throw new BadRequest('Choose a whole-number event count from 0 to 10000.');
        if (!$countRule && property_exists($input,'count')) throw new BadRequest('An event count requires a behavioral count comparison.');
        $pageUrl=null;
        if (property_exists($input,'pageUrl')) {
            if (!$behavior || $definition['event']!=='page.viewed') throw new BadRequest('A page URL requires a recorded website visit condition.');
            $pageUrl=TemplateHelpers\BehaviorCondition::pageUrl($input->pageUrl);
        }
        $value = $input->value ?? ''; $yes = $input->matchingText ?? ''; $no = $input->fallbackText ?? '';
        [$yesHtml,$noHtml] = $this->branches($input, $decisionTag);
        if (!is_string($value) || mb_strlen($value) > 255 || preg_match('/[\x00-\x1f{}]/u',$value)) throw new BadRequest('Enter a comparison value of up to 255 characters without template markers.');
        if (in_array($definition['type'],['date','datetime'],true)) {
            $needsValue = !in_array($operator, ['has_value','no_value'], true);
            $datetime = $definition['type'] === 'datetime';
            if (in_array($operator, TemplateHelpers\DateComparison::RELATIVE_OPERATORS, true)) TemplateHelpers\DateComparison::windowDays($value);
            elseif ($needsValue) $value = $datetime ? TemplateHelpers\DateTimeComparison::canonical($value) : TemplateHelpers\DateComparison::canonical($value);
            $helper = $datetime ? 'nexaEmailDateTime' : 'nexaEmailDate';
            $body = '{{#' . $helper . ' (nexaEmailCustomProperty id "' . $definition['customKey'] . '") "' . $operator . '" "' . ($needsValue ? $value : '') . '"}}' . $yesHtml . '{{else}}' . $noHtml . '{{/' . $helper . '}}';
            $this->validate('', $body);
            return ['bodyHtml' => $body];
        }
        if ($behavior) {
            if (!preg_match('/^[1-9][0-9]{0,2}$/D',$value) || (int)$value>365) throw new BadRequest('Choose a behavioral window from 1 to 365 days.');
            $body='{{#nexaEmailBehavior id "' . $definition['event'] . '" "' . $operator . '" "' . $value . '"' . ($countRule || $pageUrl!==null ? ' "' . ($countRule ? $input->count : '') . '"' : '') . ($pageUrl!==null ? ' "' . base64_encode($pageUrl) . '"' : '') . '}}' . $yesHtml . '{{else}}' . $noHtml . '{{/nexaEmailBehavior}}';
            $this->validate('',$body);
            return ['bodyHtml'=>$body];
        }
        if ($numeric) {
            $needsValue = !in_array($operator,['has_value','no_value'],true);
            $decimal = $definition['type'] === 'decimal';
            if ($needsValue && $decimal) TemplateHelpers\DecimalComparison::canonical($value);
            elseif ($needsValue && (!preg_match('/^(0|[1-9][0-9]{0,9})$/D',$value) || (float) $value > 2147483647)) throw new BadRequest('Enter a whole-number lead score between 0 and 2147483647.');
            $helper = $decimal ? 'nexaEmailDecimal' : 'nexaEmailNumeric';
            $operand = $decimal ? '(nexaEmailCustomProperty id "' . $definition['customKey'] . '")' : $field . '_RAW';
            $body = '{{#' . $helper . ' ' . $operand . ' "' . $operator . '" "' . ($needsValue ? $value : '') . '"}}' . $yesHtml . '{{else}}' . $noHtml . '{{/' . $helper . '}}';
            $this->validate('', $body);
            return ['bodyHtml'=>$body];
        }
        if (in_array($operator,['equals','not_equals'],true)) {
            if ($value === '') throw new BadRequest('Choose a comparison value.');
            if ($definition['options'] && !in_array($value,array_column($definition['options'],'value'),true)) throw new BadRequest('Choose one of the property values.');
        }
        $helper = match ($operator) {'equals'=>'ifEqual','not_equals'=>'ifNotEqual','has_value'=>'if','no_value'=>'unless'};
        $comparison = in_array($operator,['equals','not_equals'],true) ? ' (nexaEmailValue "' . base64_encode($value) . '")' : '';
        $operand = isset($definition['customKey']) ? '(nexaEmailCustomProperty id "' . $definition['customKey'] . '")' : $field . '_RAW';
        $body = '{{#' . $helper . ' ' . $operand . $comparison . '}}' . $yesHtml . '{{else}}' . $noHtml . '{{/' . $helper . '}}';
        $this->validate('', $body);
        return ['bodyHtml'=>$body];
    }

    private function composeGroup(stdClass $input, ?string $decisionTag = null): array
    {
        $this->requireAccess();
        $conditions = $input->conditions;
        $mode = $input->matchMode ?? 'all';
        if (!is_array($conditions) || count($conditions) < 1 || count($conditions) > 6 || !in_array($mode, ['all','any'], true)) {
            throw new BadRequest('Choose all or any and provide between one and six conditions.');
        }
        // Generate validated native scalar branches, then combine their helper envelopes.
        $envelopes = [];
        foreach ($conditions as $condition) {
            if (!$condition instanceof stdClass || isset($condition->conditions)) throw new BadRequest('Use a flat list of Contact property conditions.');
            $rule = clone $condition;
            $rule->matchingText = $input->matchingText ?? '';
            $rule->fallbackText = $input->fallbackText ?? '';
            if (property_exists($input,'matchingHtml') || property_exists($input,'fallbackHtml')) {
                unset($rule->matchingText,$rule->fallbackText);
                $rule->matchingHtml=$input->matchingHtml ?? null;
                $rule->fallbackHtml=$input->fallbackHtml ?? null;
            }
            $body = $this->compose($rule)['bodyHtml'];
            $openEnd = strpos($body, '}}') + 2;
            $closeStart = strrpos($body, '{{/');
            $envelopes[] = [substr($body, 0, $openEnd), substr($body, $closeStart)];
        }
        [$yes,$no] = $this->branches($input, $decisionTag);
        $body = $mode === 'all' ? $yes : $no;
        foreach (array_reverse($envelopes) as [$open, $close]) {
            $body = $open . ($mode === 'all' ? $body : $yes) . '{{else}}' . ($mode === 'all' ? $no : $body) . $close;
        }
        $this->validate('', $body);
        return ['bodyHtml' => $body];
    }

    /** Validate with an unsaved native Contact and no attachment copying or outbound side effects. */
    public function validate(string $subject, string $body): void
    {
        if (!str_contains($subject,'{{') && !str_contains($body,'{{')) return;
        if ($this->config->get('emailTemplateHtmlizerDisabled')) throw new BadRequest('Conditional email rendering is disabled in application settings.');
        if (strlen($body) > 2_000_000 || mb_strlen($subject) > 255) throw new BadRequest('Email content is too large.');
        if (substr_count($subject,'{{') + substr_count($body,'{{') > 128) throw new BadRequest('Use at most 128 native template expressions per email.');
        foreach ([$subject,$body] as $text) {
            if (substr_count($text,'{{') !== substr_count($text,'}}')) throw new BadRequest('Close each conditional content expression.');
        }
        $this->contexts->require();
        if (str_contains($subject . $body,'nexaEmailBehavior')) {
            preg_match_all('/\{\{#nexaEmailBehavior id "([a-z.]+)" "(within_days|not_within_days|count_at_least|count_at_most|count_equals)" "([1-9][0-9]{0,2})"(?: "(0|[1-9][0-9]{0,4}|)"(?: "([A-Za-z0-9+\/=]+)")?)?\}\}/',$subject . $body,$rules,PREG_SET_ORDER);
            if (count($rules)!==substr_count($subject . $body,'#nexaEmailBehavior')) throw new BadRequest('Use generated behavioral conditions.');
            foreach ($rules as $rule) if (!isset(TemplateHelpers\BehaviorCondition::EVENTS[$rule[1]]) || (int)$rule[3]>365) throw new BadRequest('Choose a supported event and a window from 1 to 365 days.');
            foreach ($rules as $rule) {
                $countRule=str_starts_with($rule[2],'count_');
                if (($countRule && (!isset($rule[4]) || $rule[4]==='' || (int)$rule[4]>10000)) || (!$countRule && isset($rule[4]) && !(isset($rule[5]) && $rule[4]===''))) throw new BadRequest('Use a bounded count only with behavioral count comparisons.');
                if (isset($rule[5])) {
                    $decoded=base64_decode($rule[5],true);
                    if ($rule[1]!=='page.viewed' || $decoded===false || base64_encode($decoded)!==$rule[5]) throw new BadRequest('Use a generated website page condition.');
                    TemplateHelpers\BehaviorCondition::pageUrl($decoded);
                }
            }
        }
        if (str_contains($subject . $body,'nexaEmailCustomProperty')) {
            preg_match_all('/\(nexaEmailCustomProperty id "([a-zA-Z0-9_]+)"\)/',$subject . $body,$references);
            if (count($references[0]) !== substr_count($subject . $body,'nexaEmailCustomProperty')) throw new BadRequest('Use generated custom Contact property conditions.');
            $available = [];
            foreach ($this->customization->definitions('Contact')['fields'] as $field) {
                if ($field['is_enabled'] && in_array($field['data_type'],['text','single_select','boolean','number','currency','date','datetime'],true)) $available[] = $field['field_key'];
            }
            if (array_diff($references[1],$available)) throw new BadRequest('A conditional Contact property is archived, disabled or unavailable.');
        }
        try {
            $this->factory->create(TemplateRenderer::class)->setEntity($this->em->getNewEntity('Contact'))
                ->setSkipRelations()->setSkipInlineAttachmentHandling()->renderMultipleTemplates($subject,$body);
        } catch (\Throwable $error) {
            throw new BadRequest('Conditional content is invalid. Check each condition, fallback and closing marker.',0,$error);
        }
    }

    private function paragraph(string $text): string
    {
        return '<p>' . nl2br(htmlspecialchars(trim($text),ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8'),false) . '</p>';
    }

    /** Both branches share one explicit format; never interpret legacy text as HTML. */
    private function branches(stdClass $input, ?string $decisionTag = null): array
    {
        $rich = property_exists($input,'matchingHtml') || property_exists($input,'fallbackHtml');
        if ($rich && (property_exists($input,'matchingText') || property_exists($input,'fallbackText'))) throw new BadRequest('Choose either rich content or plain text for both branches.');
        $result = [];
        foreach ($rich ? ['matchingHtml','fallbackHtml'] : ['matchingText','fallbackText'] as $name) {
            $value = $input->$name ?? null;
            if (!is_string($value) || trim($value) === '' || mb_strlen($value) > ($rich ? 100000 : 10000)) throw new BadRequest('Enter bounded content for both matching and fallback branches.');
            if (str_contains($value,'{{')) throw new BadRequest('Use Contact personalization tokens rather than nested conditions in this content.');
            if (!$rich) {$result[] = $this->paragraph($value); continue;}
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('Cache.DefinitionImpl',null);
            $config->set('HTML.Allowed','p[style],div[style],span[style],h1[style],h2[style],h3[style],strong,b,em,i,u,s,br,hr,ul,ol,li,a[href|title],img[src|alt|width|height],table[style],tbody,tr,td[style|colspan|rowspan],th[style]');
            $config->set('URI.AllowedSchemes',['http'=>true,'https'=>true,'mailto'=>true]);
            $html = (new \HTMLPurifier($config))->purify($value);
            if (trim(html_entity_decode(strip_tags($html),ENT_QUOTES,'UTF-8')) === '' && !str_contains($html,'<img')) throw new BadRequest('Rich content must contain visible text or an image.');
            $this->factory->create(MarketingImageService::class)->manifest($html);
            $result[] = $html;
        }
        if ($decisionTag !== null) {
            $result[0] = '<p>' . htmlspecialchars($decisionTag, ENT_QUOTES, 'UTF-8') . 'matching</p>' . $result[0];
            $result[1] = '<p>' . htmlspecialchars($decisionTag, ENT_QUOTES, 'UTF-8') . 'fallback</p>' . $result[1];
        }
        return $result;
    }

    private function requireAccess(): void
    {
        $this->contexts->require();
        if (!$this->user->isAdmin() || !$this->acl->checkScope('MassEmail','read') || !$this->acl->checkScope('EmailTemplate','edit') ||
            !$this->acl->checkScope('Contact','read') || !$this->acl->checkField('EmailTemplate','body','edit')) throw new Forbidden('Conditional email editing is not allowed.');
        if ($this->config->get('emailTemplateHtmlizerDisabled')) throw new BadRequest('Conditional email rendering is disabled in application settings.');
    }
}
