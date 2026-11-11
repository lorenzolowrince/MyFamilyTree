<?php

namespace App\Enums;

enum LivingStatus: string
{
    case Living = 'living';
    case Deceased = 'deceased';
    case Unknown = 'unknown';
}
