<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Disabled = 'disabled';
    case Locked = 'locked';
}
