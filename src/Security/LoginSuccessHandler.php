<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

final class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): ?Response
    {
        if ($request->hasSession()) {
            $request->getSession()->remove('_security.main.target_path');
        }

        $route = in_array('ROLE_ADMIN', $token->getRoleNames(), true)
            ? 'admin'
            : 'app_accueil';

        return new RedirectResponse($this->urlGenerator->generate($route));
    }
}
