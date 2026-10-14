<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail\TemplateHelpers;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Htmlizer\Helper;
use Espo\Core\Htmlizer\Helper\{Data,Result};
use Espo\Custom\Tools\Customization\CustomizationService;

/** Resolve through the existing tenant-scoped customization and record ACL boundary. */
final class CustomPropertyValue implements Helper
{
    public function __construct(private CustomizationService $customization, private \Espo\Core\ORM\EntityManager $em, private \Espo\Core\Tenant\TenantContextStore $contexts) {}

    public function render(Data $data): Result
    {
        $args = $data->getArgumentList();
        if (count($args) !== 2 || !is_string($args[1])) throw new BadRequest('Choose an available custom Contact property.');
        [$contactId,$key] = $args;
        $definition = null;
        foreach ($this->customization->definitions('Contact')['fields'] as $field) {
            if ($field['field_key'] === $key && $field['is_enabled'] && in_array($field['data_type'],['text','single_select','boolean','number','currency','date','datetime'],true)) {
                $definition = $field;
                break;
            }
        }
        if (!$definition) throw new BadRequest('A conditional Contact property is archived, disabled or unavailable.');
        // Save-time syntax validation uses an unsaved Contact, without looking up a recipient.
        if ($contactId === null || $contactId === '') return Result::create('');
        if (!is_string($contactId)) throw new BadRequest('The conditional Contact is invalid.');
        $values = $this->customization->values('Contact',$contactId)['values'];
        $value = $values[$key] ?? null;
        if (in_array($definition['data_type'],['number','currency'],true)) {
            $context = $this->contexts->require();
            $query = $this->em->getPDO()->prepare("SELECT value_number FROM nexa_custom_field_value WHERE tenant_id=? AND service_id=? AND field_definition_id=? AND entity_type='Contact' AND entity_id=?");
            $query->execute([$context->tenantId,$context->serviceId,$definition['id'],$contactId]);
            $row = $query->fetch(\PDO::FETCH_ASSOC);
            $decimal = $row ? $row['value_number'] : $definition['defaultValue'];
            return Result::create($decimal === null ? '' : (string) $decimal);
        }
        if ($definition['data_type'] === 'boolean') {
            if ($value !== null && !is_bool($value)) throw new BadRequest('The boolean Contact property value is invalid.');
            return Result::create($value === null ? '' : ($value ? 'true' : 'false'));
        }
        return Result::create($value ?? '');
    }
}
