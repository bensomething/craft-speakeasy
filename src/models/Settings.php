<?php

namespace bensomething\sesame\models;

use craft\base\Model;

/**
 * @property int $maxAttempts
 * @property int $attemptWindowSeconds
 * @property string $template
 * @property bool $bypassForCpUsers
 */
class Settings extends Model
{
    /**
     * Failed unlock attempts allowed per visitor IP + element before a lockout.
     * Set to 0 to disable rate limiting.
     */
    public int $maxAttempts = 5;

    /**
     * How long (seconds) a lockout lasts / the failed-attempt count is kept.
     */
    public int $attemptWindowSeconds = 300;

    /**
     * Site template to render for the unlock screen. Leave blank to use the
     * bundled default (`sesame/_unlock`). The template receives an `element`
     * variable and should post to the `sesame/unlock` action.
     */
    public string $template = '';

    /**
     * Skip the gate for signed-in users who can view the element in the CP
     * (admins included) — keeps live preview usable.
     */
    public bool $bypassForCpUsers = true;

    public function rules(): array
    {
        return [
            [['maxAttempts', 'attemptWindowSeconds'], 'integer', 'min' => 0],
            [['template'], 'string'],
            [['bypassForCpUsers'], 'boolean'],
        ];
    }
}
