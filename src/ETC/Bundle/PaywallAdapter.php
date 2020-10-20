<?php

declare(strict_types=1);

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
        $articlePaperCode = $filters['name'];

        return $this->getActiveSubscriptionFromKayak(
            $subscriber->getEmail(),
            $articlePaperCode,
        ) ??
            ($this->getActiveSubscriptionFromUsersApi(
                $subscriber->getEmail(),
                $articlePaperCode,
            ) ??
                $this->createInactiveSubscription($articlePaperCode));
    }

    private function getActiveSubscriptionFromKayak(
        string $email,
        string $paperCode
    ): ?SubscriptionInterface {
        $allSubscriptions = $this->getAllSubscriptionsFromKayak($email);

        $activeSubscriptions = array_filter($allSubscriptions, function (
            $subscription
        ) {
            return $subscription->isActive();
        });

        $validSubscription = array_reduce($activeSubscriptions, function (
            $carry,
            $subscription
        ) use ($paperCode) {
            return $carry ??
                ($this->isValidSubscriptionForArticlePaperCode(
                    $subscription,
                    $paperCode,
                )
                    ? $subscription
                    : null);
        });

        return $validSubscription;
    }

    private function isValidSubscriptionForArticlePaperCode(
        SubscriptionInterface $subscription,
        string $articlePaperCode
    ): bool {
        // D-ETC and GBG subscriptions should have access to both paper codes.
        // All other codes should only have access to itself.
        $validSubscriptionCodes =
            $articlePaperCode == 'D-ETC' || $articlePaperCode == 'GBG'
                ? ['GBG', 'D-ETC']
                : [$articlePaperCode];

        return in_array($subscription->getCode(), $validSubscriptionCodes);
    }

    private function getAllSubscriptionsFromKayak(string $email): array
    {
        $customerNumber = $this->getCustomerNumberFromUsersApi($email);

        if (empty($customerNumber)) {
            return [];
        }

        $response = $this->client->post(
            $this->config['serverUrl'] . '/v1/subscriptions',
            [
                'headers' => [
                    'secret-token' => $this->config['credentials']['secret'],
                ],
                'json' => [
                    'customer_number' => $customerNumber,
                ],
                'http_errors' => false,
            ],
        );

        if ($response->getStatusCode() != 200) {
            return [];
        }

        $json = json_decode((string) $response->getBody(), true);

        $subscriptions = [];
        foreach ($json["data"] as $kayakObject) {
            $subscriptions[] = $this->createSubscriptionFromKayakData(
                $kayakObject,
            );
        }

        return $subscriptions;
    }

    private function getCustomerNumberFromUsersApi(string $email): ?string
    {
        $response = $this->client->get(
            $this->config['usersApiUrl'] . "/users/$email",
            ['http_errors' => false],
        );

        if ($response->getStatusCode() != 200) {
            return null;
        }

        $json = json_decode((string) $response->getBody(), true);
        $userObject = $json["data"];
        return $userObject["customer_number"];
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
                $kayakObject['paper_name'],
            ),
        ]);
        return $subscription;
    }

    private function getActiveSubscriptionFromUsersApi(
        string $email,
        string $paperCode
    ): ?SubscriptionInterface {
        $allSubscriptions = $this->getAllSubscriptionsFromUsersApi(
            $email,
            $paperCode,
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

    private function getAllSubscriptionsFromUsersApi(
        string $email,
        string $paperCode
    ): array {
        $response = $this->client->get(
            $this->config['usersApiUrl'] . "/subscriptions/$email",
            ['http_errors' => false],
        );

        if ($response->getStatusCode() != 200) {
            return [];
        }

        $json = json_decode((string) $response->getBody(), true);

        $subscriptions = [];
        foreach ($json["data"] as $subscriptionObject) {
            $subscriptions[] = $this->createSubscriptionFromUsersApiData(
                $subscriptionObject,
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
            strval($subscriptionObject['created_at']),
        );
        $isActive = $createdAt > $cutoffDateTime;

        $subscription = $this->subscriptionFactory->create();
        $subscription->setId('N/A');
        $subscription->setCode($subscriptionObject['paper_code']);
        $subscription->setActive($isActive);
        $subscription->setDetails([
            "paperName" => $this->getPaperName(
                $subscriptionObject['paper_code'],
                $subscriptionObject['paper_code'],
            ),
        ]);
        return $subscription;
    }

    private function createInactiveSubscription(
        string $code
    ): SubscriptionInterface {
        $subscription = $this->subscriptionFactory->create();
        $subscription->setId("-1");
        $subscription->setCode($code);
        $subscription->setActive(false);
        return $subscription;
    }

    private function getPaperName(
        string $paperShortcode,
        string $defaultPaperName
    ): string {
        $paperMap = [
            'D-ETC' => 'Dagens&nbsp;ETC',
            'GBG' => 'ETC&nbsp;Göteborg',
            'H-ETC' => 'Nyhetsmagasinet&nbsp;ETC',
        ];

        return isset($paperMap[$paperShortcode])
            ? $paperMap[$paperShortcode]
            : $defaultPaperName;
    }
}
