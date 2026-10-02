<?php

namespace App\Enums;

enum ReturnReason: string
{
    case ChangedMind = 'changed_mind';
    case RefusedDelivery = 'refused_delivery';
    case FailedDelivery = 'failed_delivery';
    case DefectiveItem = 'defective_item';
    case WrongItem = 'wrong_item';
    case Other = 'other';
}
