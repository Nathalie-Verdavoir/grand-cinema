<?php

namespace App\Controller;

use App\Service\StripeCheckoutService;
use Psr\Log\LoggerInterface;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException as StripeUnexpectedValueException;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StripeWebhookController
{
    public function __construct(
        private readonly string $webhookSecret,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/stripe/webhook', name: 'app_stripe_webhook', methods: ['POST'])]
    public function __invoke(Request $request, StripeCheckoutService $stripeCheckoutService): Response
    {
        $signature = $request->headers->get('Stripe-Signature');
        if (!is_string($signature) || '' === $signature) {
            return new Response('Missing Stripe signature.', Response::HTTP_BAD_REQUEST);
        }

        try {
            $event = Webhook::constructEvent($request->getContent(), $signature, $this->webhookSecret);
        } catch (StripeUnexpectedValueException|SignatureVerificationException $exception) {
            $this->logger->warning('Rejected an invalid Stripe webhook.', ['exception' => $exception]);

            return new Response('Invalid Stripe webhook.', Response::HTTP_BAD_REQUEST);
        }

        if (
            in_array($event->type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)
            && $event->data->object instanceof Session
        ) {
            $stripeCheckoutService->handleWebhookSession($event->data->object);
        } elseif (
            'checkout.session.async_payment_failed' === $event->type
            && $event->data->object instanceof Session
        ) {
            $stripeCheckoutService->handleWebhookPaymentFailure($event->data->object);
        }

        return new Response('', Response::HTTP_OK);
    }
}
