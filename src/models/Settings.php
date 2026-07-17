<?php

namespace bensomething\sesame\models;

use craft\base\Model;
class Settings extends Model
{
    public int $maxAttempts = 5;
    public int $attemptWindowSeconds = 300;
    public string $template = '';
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
