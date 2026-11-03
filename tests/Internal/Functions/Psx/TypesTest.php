<?php

declare(strict_types=1);

namespace Polidog\Rosanna\Tests\Internal\Functions\Psx;

use PhpParser\Node;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Polidog\Rosanna\Internal\Functions\Psx\allowsNullType;
use function Polidog\Rosanna\Internal\Functions\Psx\isNullType;
use function Polidog\Rosanna\Internal\Functions\Psx\serviceType;

/** CP-133 */
final class TypesTest extends TestCase
{
    /** @return iterable<string, array{string, ?string, bool}> 型、service の名前、null を許すか */
    public static function types(): iterable
    {
        yield 'no type' => ['', null, false];
        yield 'class' => ['Foo', 'Foo', false];
        yield 'qualified class' => ['Sub\\Foo', 'Sub\\Foo', false];
        yield 'fully qualified class' => ['\\Other\\Foo', 'Other\\Foo', false];
        yield 'nullable class' => ['?Foo', 'Foo', true];
        yield 'class or null' => ['Foo|null', 'Foo', true];
        yield 'null or class' => ['null|Foo', 'Foo', true];
        yield 'upper case null' => ['Foo|NULL', 'Foo', true];
        yield 'two classes and null' => ['Foo|Bar|null', null, true];
        yield 'two classes' => ['Foo|Bar', null, false];
        yield 'intersection' => ['Foo&Bar', null, false];
        yield 'intersection or null' => ['(Foo&Bar)|null', null, true];
        yield 'class or scalar or null' => ['Foo|int|null', null, true];
        yield 'scalar' => ['int', null, false];
        yield 'array' => ['array', null, false];
        yield 'mixed' => ['mixed', null, false];
        yield 'nullable scalar' => ['?int', null, true];
        yield 'scalar or null' => ['int|null', null, true];
        yield 'null' => ['null', null, true];
    }

    #[DataProvider('types')]
    public function testServiceType(string $type, ?string $service, bool $allowsNull): void
    {
        self::assertSame($service, serviceType(self::parameterType($type))?->toString());
    }

    #[DataProvider('types')]
    public function testAllowsNullType(string $type, ?string $service, bool $allowsNull): void
    {
        self::assertSame($allowsNull, allowsNullType(self::parameterType($type)));
    }

    public function testIsNullType(): void
    {
        self::assertTrue(isNullType(new Node\Identifier('null')));
        self::assertTrue(isNullType(new Node\Identifier('Null')));
        self::assertFalse(isNullType(new Node\Identifier('int')));
        self::assertFalse(isNullType(new Node\Name('Foo')));
        self::assertFalse(isNullType(self::parameterType('?Foo')));
        self::assertFalse(isNullType(null));
    }

    private static function parameterType(string $type): Node\Identifier|Node\Name|Node\ComplexType|null
    {
        $statements = new ParserFactory()->createForNewestSupportedVersion()->parse("<?php fn ($type \$x) => 1;");
        $statement = $statements[0] ?? null;
        self::assertInstanceOf(Node\Stmt\Expression::class, $statement);
        self::assertInstanceOf(Node\Expr\ArrowFunction::class, $statement->expr);

        return $statement->expr->params[0]->type;
    }
}
