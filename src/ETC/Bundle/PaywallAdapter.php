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
    public function __construct(
        array $config,
        SubscriptionFactoryInterface $subscriptionFactory,
        ClientInterface $client
    ) {
        $this->config = $config;
        $this->subscriptionFactory = $subscriptionFactory;
        $this->client = $client;
    }

    public function getSubscriptions(
        SubscriberInterface $subscriber,
        array $filters = []
    ): array {
        return $this->getAllSubscriptionsFromKayak($subscriber->getEmail());
    }

    public function getSubscription(
        SubscriberInterface $subscriber,
        array $filters = []
    ): ?SubscriptionInterface {
        $paperCode = $filters['name'];

        $userSubscription = $this->getActiveSubscriptionFromKayak(
            $subscriber->getEmail(),
            $paperCode
        );

        if (isset($userSubscription)) {
            return $userSubscription;
        }

        // Eventhough there are no active user subsctiptions in kayak, a user might
        // very recently have signed up. Registering users to kayak is a manual process
        // done by customer support. That means that we need to check if the user has
        // recently registered for a subscription and if they have; we let them read the
        // content.
        $tempUserSubscription = $this->getActiveSubscriptionFromUsersApi(
            $subscriber->getEmail(),
            $paperCode
        );

        if (isset($tempUserSubscription)) {
            return $tempUserSubscription;
        }

        return $this->createInactiveSubscription($paperCode);
    }

    private function getActiveSubscriptionFromKayak(
        $email,
        $paperCode
    ): ?SubscriptionInterface {
        $allSubscriptions = $this->getAllSubscriptionsFromKayak($email);

        foreach ($allSubscriptions as $subscription) {
            if (
                $subscription->getCode() == $paperCode &&
                $subscription->isActive()
            ) {
                return $subscription;
                break;
            }
        }

        return null;
    }

    private function getAllSubscriptionsFromKayak($email)
    {
        $customerNumber = $this->getCustomerNumberFromUsersApi($email);

        if (empty($customerNumber)) {
            return [];
        }

        $response = $this->client->request(
            'POST',
            $this->config['serverUrl'] . '/v1/subscriptions',
            [
                'headers' => [
                    'secret-token' => $this->config['credentials']['secret'],
                ],
                'json' => [
                    'customer_number' => $customerNumber,
                ],
            ]
        );

        $json = json_decode((string) $response->getBody(), true);

        $subscriptions = [];
        foreach ($json["data"] as $kayakObject) {
            $subscriptions[] = $this->createSubscriptionFromKayakData(
                $kayakObject
            );
        }

        return $subscriptions;
    }

    private function getCustomerNumberFromUsersApi($email): ?string
    {
        $response = $this->client->request(
            'GET',
            $this->config['usersApiUrl'] . "/users/$email"
        );

        if ($response->getStatusCode() == 200) {
            $json = json_decode((string) $response->getBody(), true);
            $userObject = $json["data"];
            return $userObject["customer_number"];
        }

        return null;
    }

    private function createSubscriptionFromKayakData(
        $kayakObject
    ): SubscriptionInterface {
        $subscription = $this->subscriptionFactory->create();
        $subscription->setId($kayakObject['subscription_number']);
        $subscription->setCode($kayakObject['paper_shortcode']);
        $subscription->setActive($kayakObject['subscription_is_active']);
        $subscription->setDetails([
            "paperName" => $this->getPaperName(
                $kayakObject['paper_shortcode'],
                $kayakObject['paper_name']
            ),
        ]);
        return $subscription;
    }

    private function getActiveSubscriptionFromUsersApi(
        $email,
        $paperCode
    ): ?SubscriptionInterface {
        $allSubscriptions = $this->getAllSubscriptionsFromUsersApi(
            $email,
            $paperCode
        );

        foreach ($allSubscriptions as $subscription) {
            if (
                $subscription->getCode() == $paperCode &&
                $subscription->isActive()
            ) {
                return $subscription;
            }
        }

        return null;
    }

    private function getAllSubscriptionsFromUsersApi($email, $paperCode)
    {
        $response = $this->client->request(
            'GET',
            $this->config['usersApiUrl'] . "/subscriptions/$email"
        );
        $json = json_decode((string) $response->getBody(), true);

        $subscriptions = [];
        foreach ($json["data"] as $subscriptionObject) {
            $subscriptions[] = $this->createSubscriptionFromUsersApiData(
                $subscriptionObject
            );
        }

        return $subscriptions;
    }

    private function createSubscriptionFromUsersApiData(
        $subscriptionObject
    ): SubscriptionInterface {
        $cutoffDateTime = (new DateTime())->modify('-12 hours');
        $createdAt = date_create_from_format(
            'U',
            strval($subscriptionObject['created_at'])
        );
        $isActive = $createdAt > $cutoffDateTime;

        $subscription = $this->subscriptionFactory->create();
        $subscription->setId('N/A');
        $subscription->setCode($subscriptionObject['paper_code']);
        $subscription->setActive($isActive);
        $subscription->setDetails([
            "paperName" => $this->getPaperName(
                $subscriptionObject['paper_code'],
                $subscriptionObject['paper_code']
            ),
        ]);
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

    private function getPaperName($paperShortcode, $defaultPaperName): string
    {
        $paperMap = [
            'D-ETC' => 'Dagens&nbsp;ETC',
            'H-ETC' => 'Nyhetsmagasinet&nbsp;ETC',
        ];

        return isset($paperMap[$paperShortcode])
            ? $paperMap[$paperShortcode]
            : $defaultPaperName;
    }
}
