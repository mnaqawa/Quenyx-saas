<?php

namespace App\Events;

use App\Models\ObserveAlertEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AlertResolved
{
    use Dispatchable, SerializesModels;

    public function __construct(public ObserveAlertEvent $alertEvent) {}
}
