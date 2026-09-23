<?php

declare(strict_types=1);

namespace App\Processing;

enum ProcessingStatus: string
{
    case ValidationFailed = 'VALIDATION_FAILED';
    case Publishing = 'PUBLISHING';
    case WaitingOneC = 'WAITING_1C';
    case ReceivedRaw = 'RECEIVED_RAW';
    case Failed = 'FAILED';
}
