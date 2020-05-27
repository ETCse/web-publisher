<?php

namespace ETC\Bundle;

use \Datetime;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use SWP\Component\Paywall\Factory\SubscriptionFactoryInterface;
use SWP\Component\Paywall\Model\SubscriberInterface;
use SWP\Component\Paywall\Model\SubscriptionInterface;
use SWP\Component\Paywall\Adapter\PaywallAdapterInterface;

final class PaywallAdapter implements PaywallAdapterInterface
{
    public function __construct(array $config, SubscriptionFactoryInterface $subscriptionFactory, ClientInterface $client)
    {
        $this->config = $config;
        $this->subscriptionFactory = $subscriptionFactory;
        $this->client = $client;
    }

    public function getSubscriptions(SubscriberInterface $subscriber, array $filters = []): array
    {
        return $this->getAllSubscriptionsFromKayak($subscriber->getEmail());
    }

    public function getSubscription(SubscriberInterface $subscriber, array $filters = []): ?SubscriptionInterface
    {
        $allSubscriptions = $this->getAllSubscriptionsFromKayak($subscriber->getEmail());

        $userSubscription;
        foreach ($allSubscriptions as $subscription) {
            if ($subscription->getCode() == $filters['name'] && $subscription->isActive()) {
                $userSubscription = $subscription;
            break;
            }
        }

        return isset($userSubscription) ? $userSubscription : $this->createInactiveSubscription($filters['name']);
    }

    private function getAllSubscriptionsFromKayak($emailAddress) 
    {
        $response = $this->client->request('POST', $this->config['serverUrl'] . '/v1/subscriptions', [
            'headers' => [
                'secret-token' => $this->config['credentials']['secret']
            ],
            'json' => [
                'email_address' => $emailAddress
            ]
        ]);

        $json = json_decode((string) $response->getBody(), true);
        
        $subscriptions = [];
        foreach ($json["data"] as $kayakObject) {
            $subscriptions[] = $this->createSubscriptionFromKayakData($kayakObject);
        }

        return $subscriptions;
    }

    private function createSubscriptionFromKayakData($kayakObject): SubscriptionInterface
    {
        $subscription = $this->subscriptionFactory->create();
        $subscription->setId($kayakObject['subscription_number']);
        $subscription->setCode($kayakObject['paper_shortcode']);
        $subscription->setActive($kayakObject['subscription_is_active']);
        $subscription->setDetails(["paperName" => $this->getPaperName($kayakObject)]);
        return $subscription;
    }

    private function createInactiveSubscription($code): SubscriptionInterface
    {
        $subscription = $this->subscriptionFactory->create();
        $subscription->setId("-1");
        $subscription->setCode($code);
        $subscription->setActive(false);
        return $subscription;
    }

    private function getPaperName($kayakObject): String
    {
        $paperShortcode = $kayakObject['paper_shortcode'];
        $paperMap = array(
            'D-ETC' => 'Dagens&nbsp;ETC',
            'H-ETC' => 'Nyhetsmagasinet&nbsp;ETC'
        );

        return $paperMap[$paperShortcode];
    }

}