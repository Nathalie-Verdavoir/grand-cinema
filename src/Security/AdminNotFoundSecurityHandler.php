<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Twig\Environment;

class AdminNotFoundSecurityHandler implements AuthenticationEntryPointInterface, AccessDeniedHandlerInterface
{
    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if ($this->isAdminPath($request)) {
            return $this->notFoundResponse();
        }

        return new RedirectResponse($this->urlGenerator->generate('login'));
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        if ($this->isAdminPath($request)) {
            return $this->notFoundResponse();
        }

        return null;
    }

    private function notFoundResponse(): Response
    {
        return new Response(
            $this->twig->render('bundles/TwigBundle/Exception/error404.html.twig'),
            Response::HTTP_NOT_FOUND
        );
    }

    private function isAdminPath(Request $request): bool
    {
        $path = $request->getPathInfo();

        return '/admin' === $path || str_starts_with($path, '/admin/');
    }
}
