<?php

namespace Tests\Fixtures\Forms;

use Hata\Support\Admin\Forms\FormContext;
use Hata\Support\Admin\Forms\FormExtension;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Test-only form card, as a module would write one: a subtitle kept in the module's own table.
 */
class SubtitleExtension extends FormExtension
{
    /**
     * Each save() call: the record's key, the values and the transaction level it ran at.
     *
     * @var list<array{mixed, array<string, mixed>, int}>
     */
    public static array $saves = [];

    public static bool $fail = false;

    /**
     * Writes the subtitle twice, so the module's own unique index refuses it.
     */
    public static bool $duplicate = false;

    /**
     * Messages save() throws as a ValidationException (keys as the card gives them), or null.
     *
     * @var array<string, string>|null
     */
    public static ?array $invalid = null;

    public static function register(): void
    {
        view()->addNamespace('form-fixtures', dirname(__DIR__, 3).'/workbench/resources/views/forms');
    }

    public function fill(?Model $record, FormContext $context): array
    {
        return ['text' => $record === null ? '' : (string) DB::table('entry_subtitles')->where('entry_id', $record->getKey())->value('subtitle')];
    }

    public function rules(FormContext $context): array
    {
        return ['text' => ['required', 'string', 'max:50']];
    }

    public function view(): string
    {
        return 'form-fixtures::subtitle';
    }

    public function save(Model $record, array $values, FormContext $context): void
    {
        self::$saves[] = [$record->getKey(), $values, DB::transactionLevel()];

        DB::table('entry_subtitles')->updateOrInsert(['entry_id' => $record->getKey()], ['subtitle' => $values['text']]);

        if (self::$duplicate) {
            DB::table('entry_subtitles')->insert(['entry_id' => $record->getKey(), 'subtitle' => $values['text']]);
        }

        if (self::$invalid !== null) {
            throw ValidationException::withMessages(self::$invalid);
        }

        if (self::$fail) {
            throw new RuntimeException('The subtitle could not be saved.');
        }
    }
}
