<?php

namespace App\Support;

final class RateLimits
{
    public const AUTH_LOGIN = 'auth-login';

    public const AUTH_LOGIN_ATTEMPTS = 5;

    public const AUTH_LOGIN_DECAY_MINUTES = 1;

    public const MUTATE = 'mutate';

    public const MUTATE_ATTEMPTS = 30;

    public const MUTATE_DECAY_MINUTES = 1;

    public const LIST = 'list';

    public const LIST_ATTEMPTS = 60;

    public const LIST_DECAY_MINUTES = 1;

    public const PASSWORD_RESET = 'password-reset';

    public const PASSWORD_RESET_ATTEMPTS = 3;

    public const PASSWORD_RESET_DECAY_MINUTES = 1;

    public static function attempts(string $name): int
    {
        return self::constantValue(self::constantName($name).'_ATTEMPTS');
    }

    public static function decayMinutes(string $name): int
    {
        return self::constantValue(self::constantName($name).'_DECAY_MINUTES');
    }

    private static function constantName(string $name): string
    {
        return strtoupper((string) str($name)->replace('-', '_'));
    }

    private static function constantValue(string $key): int
    {
        return constant(self::class.'::'.$key);
    }
}
