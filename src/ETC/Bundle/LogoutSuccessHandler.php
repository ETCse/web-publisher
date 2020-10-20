<?php

declare(strict_types=1);

namespace ETC\Bundle;

use Symfony\Component\Security\Http\Logout\LogoutSuccessHandlerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

final class LogoutSuccessHandler implements LogoutSuccessHandlerInterface
{
    public function onLogoutSuccess(Request $request): Response {
        // By default, Symfony redicrects back to `/` after 
        // a successful login. We instead want to redirect back to the
        // page the user was on when she initiated the logout action.
        $referer = $request->headers->get('referer');
        $redirect = new RedirectResponse($referer);
        return $redirect;
    }
}
