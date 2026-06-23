<?php

namespace App\Modules\Task\Enums;

enum TaskStatus: int
{
    case Draft = 1;
    case Active = 2;
    case Suspended = 3;
    case Completed = 4;
    case Cancelled = 5;

    /**
     * @return array<TaskStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Cancelled],
            self::Active => [self::Suspended, self::Completed, self::Cancelled],
            self::Suspended => [self::Active, self::Cancelled],
            self::Completed => [],
            self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function apiValue(): string
    {
        return strtolower($this->name);
    }
}
