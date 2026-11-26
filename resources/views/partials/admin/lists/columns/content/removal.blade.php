{{ __('hata::content.list.removed_on', ['date' => $record->deleted_at->copy()->addDays((int) config('hata.content.trash_days'))->tz(config('hata.admin.timezone'))->isoFormat('LL')]) }}
