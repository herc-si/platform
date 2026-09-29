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

namespace SolidWorx\Platform\Tests\Bundle\PlatformBundle\Security\TwoFactor;

use Doctrine\ORM\Mapping\Column;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticator;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpFactory;
use SolidWorx\Platform\PlatformBundle\Security\TwoFactor\Traits\UserTwoFactor;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversTrait(UserTwoFactor::class)]
final class UserTwoFactorTest extends TestCase
{
    public function testTheTotpPeriodIsTheThirtySecondsEveryAuthenticatorAppUses(): void
    {
        $user = new class() {
            use UserTwoFactor;

            public string $email = 'user@example.com';
        };
        $user->setTotpSecret('SECRET');

        self::assertSame(30, $user->getTotpAuthenticationConfiguration()?->getPeriod());
    }

    public function testTheColumnHoldsTheSecretTheAuthenticatorGenerates(): void
    {
        $authenticator = new TotpAuthenticator(new TotpFactory(null, null, []), new EventDispatcher(), 10);
        $column = new ReflectionProperty(UserTwoFactor::class, 'totpSecret')->getAttributes(Column::class)[0]->newInstance();

        self::assertGreaterThanOrEqual(\strlen($authenticator->generateSecret()), $column->length);
    }
}
