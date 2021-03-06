<?php

declare(strict_types=1);

namespace Rector\Doctrine\Tests\Rector\Property\AddUuidAnnotationsToIdPropertyRector;

use Iterator;
use Rector\Doctrine\Rector\Property\AddUuidAnnotationsToIdPropertyRector;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

final class AddUuidAnnotationsToIdPropertyRectorTest extends AbstractRectorTestCase
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
        yield [__DIR__ . '/Fixture/fixture.php.inc'];
        yield [__DIR__ . '/Fixture/column.php.inc'];
    }

    protected function getRectorClass(): string
    {
        return AddUuidAnnotationsToIdPropertyRector::class;
    }
}
