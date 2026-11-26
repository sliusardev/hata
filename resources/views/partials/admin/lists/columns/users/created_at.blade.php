{{ $record->created_at?->tz(config('hata.admin.timezone'))->isoFormat('LL') }}
