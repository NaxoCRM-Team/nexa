<?php

namespace Espo\Custom\Core\Utils\Metadata;

use Espo\Core\Utils\Metadata\AdditionalBuilder;
use stdClass;

/**
 * Replaces the stock system-user rebuild action after EspoCRM has merged metadata.
 * Rebuild action lists are force-appended by the framework, so declaring a custom
 * list alone would execute both implementations and reinsert the global user.
 */
final class RebuildActionBuilder implements AdditionalBuilder
{
    private const CORE_ACTION = 'Espo\\Core\\Rebuild\\Actions\\AddSystemUser';
    private const TENANT_SAFE_ACTION = 'Espo\\Custom\\Rebuild\\Actions\\AddSystemUser';

    public function build(stdClass $data): void
    {
        $list = $data->app->rebuild->actionClassNameList ?? [];

        if (!is_array($list)) {
            return;
        }

        $list = array_values(array_filter(
            $list,
            static fn (mixed $className): bool => $className !== self::CORE_ACTION
        ));

        $list = array_values(array_unique($list));

        if (!in_array(self::TENANT_SAFE_ACTION, $list, true)) {
            array_unshift($list, self::TENANT_SAFE_ACTION);
        }

        $data->app->rebuild->actionClassNameList = $list;
    }
}
