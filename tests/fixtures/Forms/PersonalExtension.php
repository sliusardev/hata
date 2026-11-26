<?php

namespace Tests\Fixtures\Forms;

use Hata\Support\Admin\Forms\FormContext;
use Hata\Support\Admin\Forms\FormExtension;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Test-only form card for the people forms (users, profile, registration): a birthday and a gender kept in columns
 * of the host's users table. Required on registration, optional elsewhere.
 */
class PersonalExtension extends FormExtension
{
    /**
     * Each save() call: the user's key, the values and the transaction level it ran at.
     *
     * @var list<array{mixed, array<string, mixed>, int}>
     */
    public static array $saves = [];

    public static bool $fail = false;

    public static function register(): void
    {
        view()->addNamespace('form-fixtures', dirname(__DIR__, 3).'/workbench/resources/views/forms');
    }

    public function fill(?Model $record, FormContext $context): array
    {
        return [
            'birthday' => $record?->getAttribute('birthday') ? substr((string) $record->getAttribute('birthday'), 0, 10) : '',
            'gender' => (string) $record?->getAttribute('gender'),
        ];
    }

    public function rules(FormContext $context): array
    {
        $required = $context->target === 'register' ? 'required' : 'nullable';

        return [
            'birthday' => [$required, 'date', 'before:today'],
            'gender' => [$required, 'string', 'max:20'],
        ];
    }

    public function view(): string
    {
        return 'form-fixtures::personal';
    }

    public function save(Model $record, array $values, FormContext $context): void
    {
        self::$saves[] = [$record->getKey(), $values, DB::transactionLevel()];

        DB::table('users')->where('id', $record->getKey())->update([
            'birthday' => $values['birthday'] ?: null,
            'gender' => $values['gender'] ?: null,
        ]);

        if (self::$fail) {
            throw new RuntimeException('The personal data could not be saved.');
        }
    }
}
