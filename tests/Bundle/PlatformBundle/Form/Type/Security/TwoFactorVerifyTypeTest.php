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

namespace SolidWorx\Platform\Tests\Bundle\PlatformBundle\Form\Type\Security;

use Override;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use SolidWorx\Platform\PlatformBundle\Form\Type\Security\TwoFactorVerifyType;
use Symfony\Component\Form\Extension\Validator\Type\FormTypeValidatorExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Mapping\ClassMetadata;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(TwoFactorVerifyType::class)]
#[AllowMockObjectsWithoutExpectations]
final class TwoFactorVerifyTypeTest extends TypeTestCase
{
    public function testACodeStartingWithZeroKeepsItsLeadingZero(): void
    {
        $form = $this->factory->create(TwoFactorVerifyType::class, null, [
            'secret' => 'SECRET',
        ]);
        $form->submit([
            'code' => '012345',
            'secret' => 'SECRET',
        ]);

        self::assertTrue($form->isSynchronized());
        self::assertSame('012345', $form->get('code')->getData());
    }

    /**
     * @return list<FormTypeValidatorExtension>
     */
    #[Override]
    protected function getTypeExtensions(): array
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList());
        $validator->method('getMetadataFor')->willReturn(new ClassMetadata(self::class));

        return [new FormTypeValidatorExtension($validator)];
    }
}
