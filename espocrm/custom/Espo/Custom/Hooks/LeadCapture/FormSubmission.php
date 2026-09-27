<?php

declare(strict_types=1);

namespace Espo\Custom\Hooks\LeadCapture;

use Espo\Custom\Tools\Form\PublicFormRuntimeService;
use Espo\Entities\LeadCapture;

final class FormSubmission
{
    public static int $order = 20;

    public function __construct(private PublicFormRuntimeService $runtime) {}

    /** @param array<string, mixed> $options @param array<string, mixed> $hookData */
    public function afterLeadCapture(LeadCapture $form, array $options, array $hookData): void
    {
        $this->runtime->recordSubmission($form, $hookData);
    }
}
