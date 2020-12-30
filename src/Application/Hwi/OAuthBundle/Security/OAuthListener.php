<?php

namespace App\Application\Hwi\OAuthBundle\Security;

use HWI\Bundle\OAuthBundle\OAuth\ResourceOwnerInterface;
use HWI\Bundle\OAuthBundle\Security\Core\Authentication\Token\OAuthToken;
use HWI\Bundle\OAuthBundle\Security\Http\ResourceOwnerMapInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Firewall\AbstractAuthenticationListener;

class OAuthListener extends AbstractAuthenticationListener
{
    /**
     * @var ResourceOwnerMapInterface
     */
    private $resourceOwnerMap;

    /**
     * @var array
     */
    private $checkPaths;

    /**
     * @param ResourceOwnerMapInterface $resourceOwnerMap
     */
    public function setResourceOwnerMap(ResourceOwnerMapInterface $resourceOwnerMap)
    {
        $this->resourceOwnerMap = $resourceOwnerMap;
    }

    /**
     * @param array $checkPaths
     */
    public function setCheckPaths(array $checkPaths)
    {
        $this->checkPaths = $checkPaths;
    }

    /**
     * {@inheritdoc}
     */
    public function requiresAuthentication(Request $request)
    {
        // Check if the route matches one of the check paths
        foreach ($this->checkPaths as $checkPath) {
            if ($this->httpUtils->checkRequestPath($request, $checkPath)) {
                return true;
            }
        }

        return false;
    }

    protected function attemptAuthentication(Request $request)
    {        
        /* @var ResourceOwnerInterface $resourceOwner */
        list($resourceOwner, $checkPath) = $this->resourceOwnerMap->getResourceOwnerByRequest($request);
        if (!$resourceOwner) {
            throw new AuthenticationException('No resource owner match the request.');
        }
        if (!$resourceOwner->handles($request)) {
            throw new AuthenticationException('No oauth code in the request.');
        }
        // If resource owner supports only one url authentication, call redirect
        if ($request->query->has('authenticated') && $resourceOwner->getOption('auth_with_one_url')) {
            $request->attributes->set('service', $resourceOwner->getName());
            return new RedirectResponse(sprintf('%s?code=%s&authenticated=true', $this->httpUtils->generateUri($request, 'hwi_oauth_connect_service'), $request->query->get('code')));
        }
        $resourceOwner->isCsrfTokenValid($request->get('state'));

        $redirectUrl = $this->httpUtils->createRequest($request, $checkPath)->getUri();

        $redirectUrl = transformOauthUrlToUSeRootDomain($redirectUrl);

        $accessToken = $resourceOwner->getAccessToken($request, $redirectUrl);
        $token = new OAuthToken($accessToken);
        $token->setResourceOwnerName($resourceOwner->getName());
        return $this->authenticationManager->authenticate($token);
    }
}