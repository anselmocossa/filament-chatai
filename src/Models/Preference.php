<?php

namespace Filament\ChatAi\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property ?string $voice
 */
class Preference extends Model
{
    protected $table = 'chatai_preferences';

    protected $guarded = [];

    public static function for(Authenticatable $user): ?self
    {
        /** @var Model $user */
        return static::query()->where('user_type', $user->getMorphClass())->where('user_id', $user->getKey())->first();
    }

    public static function voiceFor(Authenticatable $user): ?string
    {
        return static::for($user)?->voice;
    }

    public static function setVoice(Authenticatable $user, ?string $voice): void
    {
        /** @var Model $user */
        static::query()->updateOrCreate(
            ['user_type' => $user->getMorphClass(), 'user_id' => $user->getKey()],
            ['voice' => $voice],
        );
    }
}
