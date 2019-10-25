<?php

declare(strict_types=1);

namespace Rector\Php71\Tests\Rector\ClassConst\PublicConstantVisibilityRector;

use Iterator;
use Rector\Php71\Rector\ClassConst\PublicConstantVisibilityRector;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

final class PublicConstantVisibilityRectorTest extends AbstractRectorTestCase
{
    /**
     * @dataProvider provideDataForTest()
     */
    public function test(string $file): void
    {
        $this->doTestFile($file);
    }

    public function provideDataForTest(): Iterator
    {
        yield [__DIR__ . '/Fixture/SomeClass.php.inc'];
    }

    protected function getRectorClass(): string
    {
        return PublicConstantVisibilityRector::class;
    }
}