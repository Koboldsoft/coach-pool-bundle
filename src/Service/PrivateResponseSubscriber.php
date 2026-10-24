<?php
namespace Koboldsoft\CoachPoolBundle\Service;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

class PrivateResponseSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['kernel.response' => ['onResponse', -2048]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if ($event->getRequest()->attributes->get('_coach_pool_private')) {
            $response = $event->getResponse();
            $response->setMaxAge(0);
            $response->setSharedMaxAge(0);
            $response->setPrivate();
            $response->headers->addCacheControlDirective('no-store');
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
    }
}
