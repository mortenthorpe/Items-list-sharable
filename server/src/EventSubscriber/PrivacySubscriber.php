<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CORS, and a guarantee that nothing identifying leaves with the response.
 *
 * Cookies are stripped rather than merely unused: a stray Set-Cookie from
 * any bundle would become a durable client identifier, which is exactly
 * what this API promises not to have. Credentials are never allowed, so a
 * browser will not attach cookies from another origin either.
 */
final class PrivacySubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly string $allowedOrigin = '*')
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 250],
            KernelEvents::RESPONSE => ['onResponse', -250],
        ];
    }

    /** Answer the preflight without touching the controllers. */
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $event->getRequest()->getMethod() !== 'OPTIONS') {
            return;
        }

        $response = new Response('', Response::HTTP_NO_CONTENT);
        $this->applyCors($response);
        $event->setResponse($response);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        $this->applyCors($response);

        $response->headers->remove('Set-Cookie');
        $response->headers->clearCookie('PHPSESSID');
        $response->headers->remove('Set-Cookie');

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
    }

    private function applyCors(Response $response): void
    {
        $response->headers->set('Access-Control-Allow-Origin', $this->allowedOrigin);
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept');
        $response->headers->set('Access-Control-Max-Age', '86400');
        // Never: it would invite browsers to send cookies we refuse to use.
        $response->headers->remove('Access-Control-Allow-Credentials');

        if ($this->allowedOrigin !== '*') {
            $response->headers->set('Vary', 'Origin');
        }
    }
}
