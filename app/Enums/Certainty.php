<?php

namespace App\Enums;

enum Certainty: string
{
    case Confirmed = 'confirmed';
    case Probable = 'probable';
    case Possible = 'possible';
    case Disputed = 'disputed';
}
