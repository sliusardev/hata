<?php

namespace Hata\Enums;

enum MaintenanceStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * The process died before it could record a result.
     */
    case Interrupted = 'interrupted';

    public function label(): string
    {
        return __('hata::maintenance.statuses.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Running => 'blue',
            self::Succeeded => 'green',
            self::Failed => 'red',
            self::Interrupted => 'amber',
        };
    }
}
