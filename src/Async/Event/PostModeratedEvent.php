<?php

namespace App\Async\Event;

use Zenaton\Interfaces\EventInterface;

class PostModeratedEvent implements EventInterface
{
    public $state;

    public function __construct($state)
    {
        $this->state = $state;
    }
}
