<?php

namespace Hata\Support\Admin\Forms;

use Hata\Support\Admin\Registry\Registry;
use Hata\Support\Admin\Registry\TargetedRegistry;

/**
 * The cards a site or module adds to the forms, aimed with `Admin::formCards('content.posts')` (or `content.*`,
 * `users`, `profile`, `register`).
 *
 * @extends TargetedRegistry<FormCardRegistry>
 */
final class FormCards extends TargetedRegistry
{
    public function name(): string
    {
        return 'form card';
    }

    /**
     * The cards this form shows and saves, in order: those the actor (null: a guest) may see.
     *
     * @return list<FormCard>
     */
    public function for(FormContext $context): array
    {
        return array_values(array_filter(
            $this->resolve($context->target)->all(),
            fn (FormCard $card): bool => $card->allows($context->actor),
        ));
    }

    protected function newRegistry(): Registry
    {
        return new FormCardRegistry;
    }
}
