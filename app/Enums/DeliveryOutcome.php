<?php

namespace App\Enums;

enum DeliveryOutcome: string
{
    case Applied = 'applied';
    case Duplicate = 'duplicate';
    case OutOfOrder = 'out_of_order';
    case UnknownGeneration = 'unknown_generation';
    case Ping = 'ping';
    case Rejected = 'rejected';
    case Error = 'error';
}
