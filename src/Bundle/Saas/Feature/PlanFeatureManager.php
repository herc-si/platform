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

namespace SolidWorx\Platform\SaasBundle\Feature;

use InvalidArgumentException;
use Override;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureType;
use SolidWorx\Platform\PlatformBundle\Feature\FeatureValue;
use SolidWorx\Platform\PlatformBundle\Feature\SubscribableInterface;
use SolidWorx\Platform\SaasBundle\Entity\Plan;
use SolidWorx\Platform\SaasBundle\Entity\PlanFeature;
use SolidWorx\Platform\SaasBundle\Entity\Subscription;
use SolidWorx\Platform\SaasBundle\Exception\UndefinedFeatureException;
use SolidWorx\Platform\SaasBundle\Repository\PlanFeatureRepositoryInterface;
use SolidWorx\Platform\SaasBundle\Subscription\SubscriptionProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Service\ResetInterface;
use function array_keys;
use function get_debug_type;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function sprintf;

readonly class PlanFeatureManager implements ResetInterface
{
    public function __construct(
        private FeatureConfigRegistry $configRegistry,
        private PlanFeatureRepositoryInterface $planFeatureRepository,
        private SubscriptionProviderInterface $subscriptionProvider,
        private CacheInterface $cache,
    ) {
    }

    /**
     * Get a feature value for a plan, with config defaults as fallback.
     *
     * @throws UndefinedFeatureException If the feature is not defined in config
     */
    public function getFeature(Plan $plan, string $featureKey): FeatureValue
    {
        try {
            return $this->cache->get($this->cacheKey($plan, $featureKey), function () use ($plan, $featureKey): FeatureValue {
                if (! $this->configRegistry->has($featureKey)) {
                    throw new UndefinedFeatureException($featureKey);
                }

                $planFeature = $this->planFeatureRepository->findOneByPlanAndKey($plan, $featureKey);

                if ($planFeature instanceof PlanFeature) {
                    return $planFeature->toFeatureValue();
                }

                return $this->configRegistry->get($featureKey)->toFeatureValue();
            });
        } catch (\Psr\Cache\InvalidArgumentException $invalidArgumentException) {
            throw new UndefinedFeatureException($featureKey, $invalidArgumentException->getCode(), previous: $invalidArgumentException);
        }
    }

    /**
     * Check if a plan has a specific feature enabled.
     */
    public function hasFeature(Plan $plan, string $featureKey): bool
    {
        try {
            return $this->getFeature($plan, $featureKey)->isEnabled();
        } catch (UndefinedFeatureException) {
            return false;
        }
    }

    /**
     * Check if a plan allows usage of a feature at the current usage level.
     */
    public function canUse(Plan $plan, string $featureKey, int $currentUsage = 0): bool
    {
        try {
            return $this->getFeature($plan, $featureKey)->allows($currentUsage);
        } catch (UndefinedFeatureException) {
            return false;
        }
    }

    /**
     * Get all features for a plan with their resolved values.
     *
     * @return array<string, FeatureValue>
     */
    public function getAllFeatures(Plan $plan): array
    {
        $features = [];

        foreach ($this->configRegistry->keys() as $key) {
            $features[$key] = $this->getFeature($plan, $key);
        }

        return $features;
    }

    /**
     * Get a feature value for a subscriber (uses their current subscription's plan).
     *
     * @throws UndefinedFeatureException If the feature is not defined
     */
    public function getFeatureForSubscriber(SubscribableInterface $subscriber, string $featureKey): FeatureValue
    {
        $subscription = $this->subscriptionProvider->getSubscriptionFor($subscriber);

        if (! $subscription instanceof Subscription) {
            return $this->configRegistry->get($featureKey)->toFeatureValue();
        }

        return $this->getFeature($subscription->getPlan(), $featureKey);
    }

    /**
     * Check if a subscriber has a specific feature enabled.
     */
    public function hasFeatureForSubscriber(SubscribableInterface $subscriber, string $featureKey): bool
    {
        try {
            return $this->getFeatureForSubscriber($subscriber, $featureKey)->isEnabled();
        } catch (UndefinedFeatureException) {
            return false;
        }
    }

    /**
     * Check if a subscriber can use a feature at the current usage level.
     */
    public function canUseForSubscriber(SubscribableInterface $subscriber, string $featureKey, int $currentUsage = 0): bool
    {
        try {
            return $this->getFeatureForSubscriber($subscriber, $featureKey)->allows($currentUsage);
        } catch (UndefinedFeatureException) {
            return false;
        }
    }

    /**
     * Set a feature override for a plan.
     *
     * @param int|bool|string|array<mixed> $value
     */
    public function setFeature(Plan $plan, string $featureKey, int|bool|string|array $value): void
    {
        $config = $this->configRegistry->get($featureKey);

        $this->validateValueType($config->type, $value);

        $planFeature = $this->planFeatureRepository->findOneByPlanAndKey($plan, $featureKey);

        if (! $planFeature instanceof PlanFeature) {
            $planFeature = new PlanFeature();
            $planFeature->setPlan($plan);
            $planFeature->setFeatureKey($featureKey);
            $planFeature->setType($config->type);
        }

        $planFeature->setValue($value);
        $planFeature->setDescription($config->description);

        $this->planFeatureRepository->save($planFeature);
        $this->invalidateCache($plan);
    }

    /**
     * Remove a feature override for a plan (revert to config default).
     */
    public function removeFeature(Plan $plan, string $featureKey): void
    {
        $planFeature = $this->planFeatureRepository->findOneByPlanAndKey($plan, $featureKey);

        if ($planFeature instanceof PlanFeature) {
            $this->planFeatureRepository->remove($planFeature);
            $this->invalidateCache($plan);
        }
    }

    /**
     * Check if a feature is available on any plan (for upgrade prompts).
     */
    public function isFeatureAvailableOnAnyPlan(string $featureKey): bool
    {
        if (! $this->configRegistry->has($featureKey)) {
            return false;
        }

        $config = $this->configRegistry->get($featureKey);

        if ($config->toFeatureValue()->isEnabled()) {
            return true;
        }

        $planFeatures = $this->planFeatureRepository->findByFeatureKey($featureKey);
        return array_any($planFeatures, fn ($planFeature) => $planFeature->toFeatureValue()->isEnabled());
    }

    /**
     * Find all plans that have a specific feature enabled.
     *
     * @return array<Plan>
     */
    public function findPlansWithFeature(string $featureKey, ?Plan $excludePlan = null): array
    {
        $plans = [];
        $planFeatures = $this->planFeatureRepository->findByFeatureKey($featureKey);

        foreach ($planFeatures as $planFeature) {
            $plan = $planFeature->getPlan();

            if ($excludePlan instanceof Plan && $plan->getId()->equals($excludePlan->getId())) {
                continue;
            }

            if ($planFeature->toFeatureValue()->isEnabled()) {
                $plans[] = $plan;
            }
        }

        return $plans;
    }

    /**
     * Get a feature's configured default value, without consulting any plan-specific override.
     *
     * Used when no subscriber/plan is in context (e.g. CLI commands, message handlers).
     *
     * @throws UndefinedFeatureException If the feature is not defined in config.
     */
    public function getConfigDefault(string $featureKey): FeatureValue
    {
        return $this->configRegistry->get($featureKey)->toFeatureValue();
    }

    /**
     * Get all available feature configurations.
     *
     * @return array<string, FeatureConfig>
     */
    public function getAvailableFeatures(): array
    {
        return $this->configRegistry->all();
    }

    /**
     * Clear the in-memory cache.
     */
    #[Override]
    public function reset(): void
    {
        $this->cache->clear();
    }

    /**
     * Forgets every right of the plan. A cache has no wildcard, so each key of
     * the catalogue is dropped by name; deleting "feature_<plan>_*" removed a
     * key that never existed, and a changed right kept its old value.
     */
    private function invalidateCache(Plan $plan): void
    {
        foreach (array_keys($this->configRegistry->all()) as $featureKey) {
            try {
                $this->cache->delete($this->cacheKey($plan, $featureKey));
            } catch (\Psr\Cache\InvalidArgumentException) {
            }
        }
    }

    private function cacheKey(Plan $plan, string $featureKey): string
    {
        return sprintf('feature_%s_%s', $plan->getId()->toBase58(), $featureKey);
    }

    private function validateValueType(FeatureType $type, mixed $value): void
    {
        $valid = match ($type) {
            FeatureType::BOOLEAN => is_bool($value),
            FeatureType::INTEGER => is_int($value),
            FeatureType::STRING => is_string($value),
            FeatureType::ARRAY => is_array($value),
        };

        if (! $valid) {
            throw new InvalidArgumentException(sprintf(
                'Feature value must be of type %s, %s given.',
                $type->value,
                get_debug_type($value)
            ));
        }
    }
}
