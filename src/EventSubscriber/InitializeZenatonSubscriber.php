<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\KernelEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Zenaton\Client;

class InitializeZenatonSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'initializeZenaton',
        ];
    }

    public function initializeZenaton(KernelEvent $event): void
    {
        if ($event->isMasterRequest()) {
            Client::init(getenv('ZENATON_APP_ID'), getenv('ZENATON_API_TOKEN'), getenv('ZENATON_APP_ENV'));
        }
    }
}
