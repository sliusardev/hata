<?php

namespace Hata\Support\Admin\Forms;

use Closure;
use Hata\Contracts\AdminUser;
use Hata\Support\Admin\Registry\Entry;
use Hata\Support\Admin\Registry\HasChanges;
use Illuminate\Support\Facades\Gate;

/**
 * One card a site or module adds to a form, drawn after Hata's own. On the entry form `main` is below the form's
 * fields and `side` at the end of its sidebar, after Hata's cards. The user form, the profile page and registration
 * have one column: they draw every `main` card after their fields, then every `side` card.
 *
 * `$extension` is the class doing the work (a `FormExtension`). `$permission` is null for anyone (a guest too, on
 * registration), an ability checked through the Gate for the actor (never for a guest), or a
 * `Closure(?AdminUser, FormContext): bool`.
 */
final readonly class FormCard implements Entry
{
    use HasChanges;

    /**
     * @param  class-string<FormExtension>  $extension
     * @param  string|Closure(?AdminUser, FormContext): bool|null  $permission
     */
    public function __construct(
        public string $key,
        public string $extension,
        public string|Closure|null $permission = null,
        public int $order = 100,
        public string $area = 'main',
    ) {}

    /**
     * Whether the actor (null: a guest) sees the card and may save it on the form the context names (passed to a
     * permission closure as its last argument).
     */
    public function allows(?AdminUser $actor, FormContext $context): bool
    {
        if ($this->permission === null) {
            return true;
        }

        if ($this->permission instanceof Closure) {
            return (bool) ($this->permission)($actor, $context);
        }

        return $actor !== null && Gate::forUser($actor)->allows($this->permission);
    }
}
