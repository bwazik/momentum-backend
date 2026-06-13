<?php

namespace App\Modules\Tracking\Enums;

enum SlaTimerStatus: int
{
    case Running = 1;
    case Warning = 2;
    case Breached = 3;
    case Completed = 4;
    case Paused = 5;

    public function isTerminal(): bool
    {
        return in_array($this, [self::Breached, self::Completed], true);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Running, self::Warning], true);
    }
}
