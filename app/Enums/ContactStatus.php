<?php

namespace App\Enums;

enum ContactStatus: string
{
    case NotContacted = 'not_contacted';
    case NoResponse = 'no_response';
    case Responded = 'responded';
}
