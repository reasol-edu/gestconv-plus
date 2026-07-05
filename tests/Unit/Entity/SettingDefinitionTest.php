<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\SettingDefinition;
use App\Entity\SettingType;
use PHPUnit\Framework\TestCase;

class SettingDefinitionTest extends TestCase
{
    // ── Integer — range validation ────────────────────────────────────────────

    public function testIntegerWithinRangeIsValid(): void
    {
        $def = $this->makeIntDef(min: 5, max: 100);

        self::assertTrue($def->isValueValid('20'));
        self::assertTrue($def->isValueValid('5'));
        self::assertTrue($def->isValueValid('100'));
    }

    public function testIntegerBelowMinIsInvalid(): void
    {
        $def = $this->makeIntDef(min: 5, max: 100);

        self::assertFalse($def->isValueValid('4'));
        self::assertFalse($def->isValueValid('0'));
        self::assertFalse($def->isValueValid('-1'));
    }

    public function testIntegerAboveMaxIsInvalid(): void
    {
        $def = $this->makeIntDef(min: 5, max: 100);

        self::assertFalse($def->isValueValid('101'));
        self::assertFalse($def->isValueValid('999'));
    }

    public function testIntegerWithNoLimitsIsValid(): void
    {
        $def = $this->makeIntDef(min: null, max: null);

        self::assertTrue($def->isValueValid('0'));
        self::assertTrue($def->isValueValid('-100'));
        self::assertTrue($def->isValueValid('99999'));
    }

    public function testIntegerOnlyMinEnforcesLowerBound(): void
    {
        $def = $this->makeIntDef(min: 1, max: null);

        self::assertTrue($def->isValueValid('1'));
        self::assertTrue($def->isValueValid('100'));
        self::assertFalse($def->isValueValid('0'));
    }

    public function testIntegerOnlyMaxEnforcesUpperBound(): void
    {
        $def = $this->makeIntDef(min: null, max: 50);

        self::assertTrue($def->isValueValid('50'));
        self::assertTrue($def->isValueValid('0'));
        self::assertFalse($def->isValueValid('51'));
    }

    public function testIntegerNonNumericIsInvalid(): void
    {
        $def = $this->makeIntDef(min: null, max: null);

        self::assertFalse($def->isValueValid('abc'));
        self::assertFalse($def->isValueValid(''));
        self::assertFalse($def->isValueValid(' '));
    }

    public function testIntegerFloatIsInvalid(): void
    {
        $def = $this->makeIntDef(min: null, max: null);

        self::assertFalse($def->isValueValid('3.14'));
        self::assertFalse($def->isValueValid('1.0'));
    }

    // ── String — length validation ────────────────────────────────────────────

    public function testStringLengthInRangeIsValid(): void
    {
        $def = $this->makeStringDef(min: 2, max: 10);

        self::assertTrue($def->isValueValid('hi'));
        self::assertTrue($def->isValueValid('abcde'));
        self::assertTrue($def->isValueValid('1234567890'));
    }

    public function testEmptyStringIsAlwaysValid(): void
    {
        $def = $this->makeStringDef(min: 2, max: 10);

        self::assertTrue($def->isValueValid(''));
    }

    public function testStringTooLongIsInvalid(): void
    {
        $def = $this->makeStringDef(min: null, max: 5);

        self::assertFalse($def->isValueValid('toolong'));
        self::assertFalse($def->isValueValid('123456'));
    }

    public function testStringTooShortIsInvalid(): void
    {
        $def = $this->makeStringDef(min: 5, max: null);

        self::assertFalse($def->isValueValid('hi'));
        self::assertFalse($def->isValueValid('abc'));
    }

    public function testStringWithNoLimitsIsValid(): void
    {
        $def = $this->makeStringDef(min: null, max: null);

        self::assertTrue($def->isValueValid('any length whatsoever'));
        self::assertTrue($def->isValueValid(''));
    }

    // ── Boolean ───────────────────────────────────────────────────────────────

    public function testBooleanTrueIsValid(): void
    {
        $def = $this->makeBoolDef();

        self::assertTrue($def->isValueValid('true'));
    }

    public function testBooleanFalseIsValid(): void
    {
        $def = $this->makeBoolDef();

        self::assertTrue($def->isValueValid('false'));
    }

    public function testBooleanInvalidValueIsInvalid(): void
    {
        $def = $this->makeBoolDef();

        self::assertFalse($def->isValueValid('yes'));
        self::assertFalse($def->isValueValid('1'));
        self::assertFalse($def->isValueValid(''));
    }

    // ── Choice ────────────────────────────────────────────────────────────────

    public function testChoiceValueInListIsValid(): void
    {
        $def = $this->makeChoiceDef('report_teacher,group_tutor,both');

        self::assertTrue($def->isValueValid('report_teacher'));
        self::assertTrue($def->isValueValid('group_tutor'));
        self::assertTrue($def->isValueValid('both'));
    }

    public function testChoiceValueNotInListIsInvalid(): void
    {
        $def = $this->makeChoiceDef('report_teacher,group_tutor,both');

        self::assertFalse($def->isValueValid('unknown'));
        self::assertFalse($def->isValueValid(''));
    }

    public function testChoicesArraySplitsTrimsAndFiltersEmpty(): void
    {
        $def = $this->makeChoiceDef(' report_teacher , group_tutor ,, both ');

        self::assertSame(['report_teacher', 'group_tutor', 'both'], $def->getChoicesArray());
    }

    public function testChoicesArrayIsEmptyWhenChoicesIsNull(): void
    {
        $def = $this->makeChoiceDef(null);

        self::assertSame([], $def->getChoicesArray());
    }

    public function testChoiceCastedDefaultValueIsRawString(): void
    {
        $def = $this->makeChoiceDef('report_teacher,group_tutor,both');

        self::assertSame('both', $def->getCastedDefaultValue());
    }

    // ── min/max getters and setters ───────────────────────────────────────────

    public function testMinMaxDefaultToNull(): void
    {
        $def = (new SettingDefinition())->setKey('k')->setType(SettingType::Integer)->setDefaultValue('0');

        self::assertNull($def->getMinValue());
        self::assertNull($def->getMaxValue());
    }

    public function testSetMinMaxStoresValues(): void
    {
        $def = (new SettingDefinition())
            ->setKey('k')->setType(SettingType::Integer)->setDefaultValue('0')
            ->setMinValue(5)->setMaxValue(100);

        self::assertSame(5, $def->getMinValue());
        self::assertSame(100, $def->getMaxValue());
    }

    // ── category / categoryOrder / position ───────────────────────────────────

    public function testCategoryCategoryOrderAndPositionDefaultToEmpty(): void
    {
        $def = (new SettingDefinition())->setKey('k')->setType(SettingType::Integer)->setDefaultValue('0');

        self::assertSame('', $def->getCategory());
        self::assertSame(0, $def->getCategoryOrder());
        self::assertSame(0, $def->getPosition());
    }

    public function testSetCategoryCategoryOrderAndPositionStoresValues(): void
    {
        $def = (new SettingDefinition())
            ->setKey('k')->setType(SettingType::Integer)->setDefaultValue('0')
            ->setCategory('settings.category.board')->setCategoryOrder(30)->setPosition(10);

        self::assertSame('settings.category.board', $def->getCategory());
        self::assertSame(30, $def->getCategoryOrder());
        self::assertSame(10, $def->getPosition());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeIntDef(?int $min, ?int $max): SettingDefinition
    {
        return (new SettingDefinition())
            ->setKey('page.size')
            ->setType(SettingType::Integer)
            ->setDefaultValue('20')
            ->setMinValue($min)
            ->setMaxValue($max);
    }

    private function makeStringDef(?int $min, ?int $max): SettingDefinition
    {
        return (new SettingDefinition())
            ->setKey('some.string')
            ->setType(SettingType::String)
            ->setDefaultValue('')
            ->setMinValue($min)
            ->setMaxValue($max);
    }

    private function makeBoolDef(): SettingDefinition
    {
        return (new SettingDefinition())
            ->setKey('email.notifications')
            ->setType(SettingType::Boolean)
            ->setDefaultValue('true');
    }

    private function makeChoiceDef(?string $choices): SettingDefinition
    {
        return (new SettingDefinition())
            ->setKey('notifications.report_notifier')
            ->setType(SettingType::Choice)
            ->setDefaultValue('both')
            ->setChoices($choices);
    }
}
