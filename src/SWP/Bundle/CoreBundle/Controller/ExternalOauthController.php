<?php

declare(strict_types=1);

namespace SWP\Bundle\CoreBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;

class ExternalOauthController extends Controller
{
    /**
     * @Route("/connect/oauth", name="connect_oauth_start")
     */
    public function connectAction(Request $request)
    {
        $clientRegistry = $this->get('knpu.oauth2.registry');

        return $clientRegistry
            ->getClient('external_oauth')
            ->redirect([
                'openid', 'email', 'profile',
            ]);
    }

    /**
     * This is where the user is redirected after being succesfully authenticated by the OAuth server.
     *
     * @Route("/connect/oauth/check", name="connect_oauth_check")
     */
    public function connectCheckAction(Request $request)
    {
        $clientRegistry = $this->get('knpu.oauth2.registry');
        $client = $clientRegistry->getClient('external_oauth');

        $accessToken = $client->getAccessToken();
        $oauthUser = $client->fetchUserFromToken($accessToken);

        return new Response('Access token acquired! '.$accessToken);
    }
}
