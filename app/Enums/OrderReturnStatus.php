<?php

namespace App\Enums;

enum OrderReturnStatus: string
{
    case WaitingForReturn = 'waiting_for_return';
    case Received = 'received';
}
