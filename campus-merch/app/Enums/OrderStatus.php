<?php

namespace App\Enums;

enum OrderStatus: string
{
    case DRAFT = 'draft';
    case BOOKED = 'booked';
    case DESIGN_PENDING = 'design_pending';
    case READY = 'ready';
    case COMPLETED = 'completed';
    case REJECTED = 'rejected';
}
