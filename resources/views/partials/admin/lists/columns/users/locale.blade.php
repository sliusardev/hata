{{ config('hata.admin.interface_locales')[$record->locale ?? app(\Hata\Support\Localization\ContentLocales::class)->interfaceDefault()] ?? '—' }}
