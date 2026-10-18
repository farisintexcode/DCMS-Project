<?php

namespace App\Enums;

enum BatchStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
