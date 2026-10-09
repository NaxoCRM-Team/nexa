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
            if (!$field['is_enabled'] || !in_array($field['data_type'],['text','single_select','boolean','number','currency'],true)) continue;
            $fields[] = ['name'=>'custom:' . $field['field_key'],'label'=>$field['label'] . ' (custom)',
                'type'=>in_array($field['data_type'],['number','currency'],true) ? 'decimal' : (in_array($field['data_type'],['single_select','boolean'],true) ? 'enum' : 'varchar'),
                'options'=>$field['data_type'] === 'boolean' ? [['value'=>'true','label'=>'True'],['value'=>'false','label'=>'False']] : array_map(static fn (string $value): array => ['value'=>$value,'label'=>$value],$field['options']),
                'customKey'=>$field['field_key']];
        }
        return ['fields'=>$fields];
    }

    public function compose(stdClass $input): array
    {
        if (isset($input->conditions)) return $this->composeGroup($input);
        $catalog = $this->catalog();
        $field = $input->field ?? null; $operator = $input->operator ?? null;
        $definition = null;
        foreach ($catalog['fields'] as $item) if ($item['name'] === $field) {$definition = $item; break;}
        if (!$definition) throw new BadRequest('Choose an available Contact property.');
        $numeric = in_array($definition['type'],['int','decimal'],true);
        $operators = $numeric ? ['equals','not_equals','greater_than','at_least','less_than','at_most','has_value','no_value'] : ['equals','not_equals','has_value','no_value'];
        if (!in_array($operator,$operators,true)) throw new BadRequest('Choose a supported condition.');
        $value = $input->value ?? ''; $yes = $input->matchingText ?? ''; $no = $input->fallbackText ?? '';
        if (!is_string($value) || mb_strlen($value) > 255 || preg_match('/[\x00-\x1f{}]/u',$value)) throw new BadRequest('Enter a comparison value of up to 255 characters without template markers.');
        if (!is_string($yes) || trim($yes) === '' || mb_strlen($yes) > 10000) throw new BadRequest('Enter matching content of up to 10,000 characters.');
        if (!is_string($no) || trim($no) === '' || mb_strlen($no) > 10000) throw new BadRequest('Enter fallback content of up to 10,000 characters.');
        if (str_contains($yes,'{{') || str_contains($no,'{{')) throw new BadRequest('Use Contact personalization tokens rather than nested conditions in this content.');
        if ($numeric) {
            $needsValue = !in_array($operator,['has_value','no_value'],true);
            $decimal = $definition['type'] === 'decimal';
            if ($needsValue && $decimal) TemplateHelpers\DecimalComparison::canonical($value);
            elseif ($needsValue && (!preg_match('/^(0|[1-9][0-9]{0,9})$/D',$value) || (float) $value > 2147483647)) throw new BadRequest('Enter a whole-number lead score between 0 and 2147483647.');
            $helper = $decimal ? 'nexaEmailDecimal' : 'nexaEmailNumeric';
            $operand = $decimal ? '(nexaEmailCustomProperty id "' . $definition['customKey'] . '")' : $field . '_RAW';
            $body = '{{#' . $helper . ' ' . $operand . ' "' . $operator . '" "' . ($needsValue ? $value : '') . '"}}' . $this->paragraph($yes) . '{{else}}' . $this->paragraph($no) . '{{/' . $helper . '}}';
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
        $body = '{{#' . $helper . ' ' . $operand . $comparison . '}}' . $this->paragraph($yes) . '{{else}}' . $this->paragraph($no) . '{{/' . $helper . '}}';
        $this->validate('', $body);
        return ['bodyHtml'=>$body];
    }

    private function composeGroup(stdClass $input): array
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
            $body = $this->compose($rule)['bodyHtml'];
            $openEnd = strpos($body, '}}') + 2;
            $closeStart = strrpos($body, '{{/');
            $envelopes[] = [substr($body, 0, $openEnd), substr($body, $closeStart)];
        }
        $yes = $this->paragraph($input->matchingText);
        $no = $this->paragraph($input->fallbackText);
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
        if (str_contains($subject . $body,'nexaEmailCustomProperty')) {
            preg_match_all('/\(nexaEmailCustomProperty id "([a-zA-Z0-9_]+)"\)/',$subject . $body,$references);
            if (count($references[0]) !== substr_count($subject . $body,'nexaEmailCustomProperty')) throw new BadRequest('Use generated custom Contact property conditions.');
            $available = [];
            foreach ($this->customization->definitions('Contact')['fields'] as $field) {
                if ($field['is_enabled'] && in_array($field['data_type'],['text','single_select','boolean','number','currency'],true)) $available[] = $field['field_key'];
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

    private function requireAccess(): void
    {
        $this->contexts->require();
        if (!$this->user->isAdmin() || !$this->acl->checkScope('MassEmail','read') || !$this->acl->checkScope('EmailTemplate','edit') ||
            !$this->acl->checkScope('Contact','read') || !$this->acl->checkField('EmailTemplate','body','edit')) throw new Forbidden('Conditional email editing is not allowed.');
        if ($this->config->get('emailTemplateHtmlizerDisabled')) throw new BadRequest('Conditional email rendering is disabled in application settings.');
    }
}
