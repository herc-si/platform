<?php

declare(strict_types=1);

/*
 * This file is part of SolidWorx Platform project.
 *
 * (c) Pierre du Plessis <open-source@solidworx.co>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace SolidWorx\Platform\Tests\Bundle\Saas\Feature;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureType;
use SolidWorx\Platform\PlatformBundle\Feature\SubscribableInterface;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\PlanFeature;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Exception\UndefinedFeatureException;
use SolidWorx\Platform\SaasBundle\Feature\FeatureConfigRegistry;
use SolidWorx\Platform\SaasBundle\Feature\PlanFeatureManager;
use SolidWorx\Platform\SaasBundle\Repository\PlanFeatureRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Uid\Ulid;

#[CoversClass(PlanFeatureManager::class)]
final class PlanFeatureManagerTest extends TestCase
{
    private FeatureConfigRegistry $configRegistry;

    private Plan $plan;

    #[Override]
    protected function setUp(): void
    {
        $this->configRegistry = new FeatureConfigRegistry([
            'max_users' => [
                'type' => 'integer',
                'default' => 10,
                'description' => 'Maximum number of users',
            ],
            'api_access' => [
                'type' => 'boolean',
                'default' => false,
                'description' => 'API access enabled',
            ],
            'storage_gb' => [
                'type' => 'integer',
                'default' => 5,
                'description' => 'Storage limit in GB',
            ],
        ]);

        $this->plan = $this->createPlan();
    }

    public function testGetFeatureReturnsDefaultFromConfig(): void
    {
        $manager = $this->createManager();

        $feature = $manager->getFeature($this->plan, 'max_users');

        self::assertSame('max_users', $feature->key);
        self::assertSame(FeatureType::INTEGER, $feature->type);
        self::assertSame(10, $feature->value);
    }

    public function testGetFeatureReturnsOverrideFromDatabase(): void
    {
        $planFeature = new PlanFeature();
        $planFeature->setFeatureKey('max_users');
        $planFeature->setType(FeatureType::INTEGER);
        $planFeature->setValue(50);

        $manager = $this->createManager([$planFeature]);

        $feature = $manager->getFeature($this->plan, 'max_users');

        self::assertSame(50, $feature->value);
    }

    public function testGetFeatureThrowsForUndefinedFeature(): void
    {
        $manager = $this->createManager();

        $this->expectException(UndefinedFeatureException::class);

        $manager->getFeature($this->plan, 'undefined_feature');
    }

    public function testHasFeatureReturnsTrue(): void
    {
        $planFeature = new PlanFeature();
        $planFeature->setFeatureKey('api_access');
        $planFeature->setType(FeatureType::BOOLEAN);
        $planFeature->setValue(true);

        $manager = $this->createManager([$planFeature]);

        self::assertTrue($manager->hasFeature($this->plan, 'api_access'));
    }

    public function testHasFeatureReturnsFalseForDisabled(): void
    {
        $manager = $this->createManager();

        // Default is false
        self::assertFalse($manager->hasFeature($this->plan, 'api_access'));
    }

    public function testHasFeatureReturnsFalseForUndefined(): void
    {
        $manager = $this->createManager();

        self::assertFalse($manager->hasFeature($this->plan, 'undefined_feature'));
    }

    public function testCanUseWithinLimit(): void
    {
        $manager = $this->createManager();

        // Default max_users is 10
        self::assertTrue($manager->canUse($this->plan, 'max_users', 5));
        self::assertTrue($manager->canUse($this->plan, 'max_users', 9));
        self::assertFalse($manager->canUse($this->plan, 'max_users', 10));
        self::assertFalse($manager->canUse($this->plan, 'max_users', 15));
    }

    public function testCanUseWithUnlimited(): void
    {
        $planFeature = new PlanFeature();
        $planFeature->setFeatureKey('max_users');
        $planFeature->setType(FeatureType::INTEGER);
        $planFeature->setValue(-1); // Unlimited

        $manager = $this->createManager([$planFeature]);

        self::assertTrue($manager->canUse($this->plan, 'max_users', 1000000));
    }

    public function testGetAllFeatures(): void
    {
        $manager = $this->createManager();

        $features = $manager->getAllFeatures($this->plan);

        self::assertCount(3, $features);
        self::assertArrayHasKey('max_users', $features);
        self::assertArrayHasKey('api_access', $features);
        self::assertArrayHasKey('storage_gb', $features);
    }

    public function testGetFeatureForSubscriber(): void
    {
        $subscriber = $this->createMock(SubscribableInterface::class);
        $subscription = $this->createSubscription($this->plan);

        $manager = $this->createManager([], $subscription);

        $feature = $manager->getFeatureForSubscriber($subscriber, 'max_users');

        self::assertSame(10, $feature->value);
    }

    public function testGetFeatureForSubscriberWithoutSubscriptionReturnsDefault(): void
    {
        $subscriber = $this->createMock(SubscribableInterface::class);

        $manager = $this->createManager([], null);

        $feature = $manager->getFeatureForSubscriber($subscriber, 'max_users');

        self::assertSame(10, $feature->value);
    }

    public function testGetAvailableFeatures(): void
    {
        $manager = $this->createManager();

        $features = $manager->getAvailableFeatures();

        self::assertCount(3, $features);
        self::assertArrayHasKey('max_users', $features);
        self::assertArrayHasKey('api_access', $features);
        self::assertArrayHasKey('storage_gb', $features);
    }

    public function testGetConfigDefaultReturnsRegistryDefault(): void
    {
        $manager = $this->createManager();

        $value = $manager->getConfigDefault('max_users');

        self::assertSame('max_users', $value->key);
        self::assertSame(10, $value->value);
    }

    /**
     * A right read once is cached: changing it must not leave the old value.
     */
    public function testAChangedRightIsReadBackChanged(): void
    {
        $manager = $this->createManager();

        self::assertSame(10, $manager->getFeature($this->plan, 'max_users')->asInt());

        $manager->setFeature($this->plan, 'max_users', 25);
        self::assertSame(25, $manager->getFeature($this->plan, 'max_users')->asInt());

        $manager->removeFeature($this->plan, 'max_users');
        self::assertSame(10, $manager->getFeature($this->plan, 'max_users')->asInt());
    }

    public function testGetConfigDefaultThrowsForUnknownKey(): void
    {
        $manager = $this->createManager();

        $this->expectException(UndefinedFeatureException::class);

        $manager->getConfigDefault('does_not_exist');
    }

    /**
     * @param array<PlanFeature> $planFeatures
     */
    private function createManager(array $planFeatures = [], ?Subscription $subscription = null): PlanFeatureManager
    {
        $planFeatureRepository = new class($planFeatures) implements PlanFeatureRepositoryInterface {
            /**
             * @param array<PlanFeature> $planFeatures
             */
            public function __construct(
                private array $planFeatures,
            ) {
            }

            public function save(PlanFeature $planFeature): void
            {
                if (! in_array($planFeature, $this->planFeatures, true)) {
                    $this->planFeatures[] = $planFeature;
                }
            }

            public function remove(PlanFeature $planFeature): void
            {
                $this->planFeatures = array_values(array_filter($this->planFeatures, static fn (PlanFeature $f): bool => $f !== $planFeature));
            }

            public function findByPlan(Plan $plan): array
            {
                return $this->planFeatures;
            }

            public function findOneByPlanAndKey(Plan $plan, string $featureKey): ?PlanFeature
            {
                foreach ($this->planFeatures as $feature) {
                    if ($feature->getFeatureKey() === $featureKey) {
                        return $feature;
                    }
                }

                return null;
            }

            public function findByPlans(array $plans): array
            {
                return array_values($this->planFeatures);
            }

            /**
             * @return array<PlanFeature>
             */
            public function findByFeatureKey(string $featureKey): array
            {
                return array_filter($this->planFeatures, fn (PlanFeature $f): bool => $f->getFeatureKey() === $featureKey);
            }
        };

        $subscriptionProvider = new class($subscription) implements SubscriptionProviderInterface {
            public function __construct(
                private readonly ?Subscription $subscription,
            ) {
            }

            public function getSubscriptionFor(SubscribableInterface $subscriber): ?Subscription
            {
                return $this->subscription;
            }
        };

        $cache = new ArrayAdapter();

        return new PlanFeatureManager(
            $this->configRegistry,
            $planFeatureRepository,
            $subscriptionProvider,
            $cache,
        );
    }

    private function createPlan(): Plan
    {
        $plan = new Plan();
        $plan->setName('Test Plan');
        $plan->setPlanId('test-plan');
        $plan->setPrice(1000);

        $reflection = new ReflectionClass($plan);
        $property = $reflection->getProperty('id');
        $property->setValue($plan, new Ulid());

        return $plan;
    }

    private function createSubscription(Plan $plan): Subscription
    {
        $subscription = new Subscription();
        $subscription->setPlan($plan);

        return $subscription;
    }
}
