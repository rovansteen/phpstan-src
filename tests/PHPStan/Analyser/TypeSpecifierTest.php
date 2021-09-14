<?php declare(strict_types = 1);

namespace PHPStan\Analyser;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Equal;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\VarLikeIdentifier;
use PHPStan\Type\ArrayType;
use PHPStan\Type\ClassStringType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Generic\GenericClassStringType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;

class TypeSpecifierTest extends \PHPStan\Testing\PHPStanTestCase
{

	private const FALSEY_TYPE_DESCRIPTION = '0|0.0|\'\'|\'0\'|array()|false|null';
	private const TRUTHY_TYPE_DESCRIPTION = 'mixed~' . self::FALSEY_TYPE_DESCRIPTION;
	private const SURE_NOT_FALSEY = '~' . self::FALSEY_TYPE_DESCRIPTION;
	private const SURE_NOT_TRUTHY = '~' . self::TRUTHY_TYPE_DESCRIPTION;

	/** @var \PhpParser\PrettyPrinter\Standard() */
	private $printer;

	/** @var \PHPStan\Analyser\TypeSpecifier */
	private $typeSpecifier;

	/** @var Scope */
	private $scope;

	protected function setUp(): void
	{
		$broker = $this->createBroker();
		$this->printer = new \PhpParser\PrettyPrinter\Standard();
		$this->typeSpecifier = self::getContainer()->getService('typeSpecifier');
		$this->scope = $this->createScopeFactory($broker, $this->typeSpecifier)->create(ScopeContext::create(''));
		$this->scope = $this->scope->enterClass($broker->getClass('DateTime'));
		$this->scope = $this->scope->assignVariable('bar', new ObjectType('Bar'));
		$this->scope = $this->scope->assignVariable('stringOrNull', new UnionType([new StringType(), new NullType()]));
		$this->scope = $this->scope->assignVariable('string', new StringType());
		$this->scope = $this->scope->assignVariable('barOrNull', new UnionType([new ObjectType('Bar'), new NullType()]));
		$this->scope = $this->scope->assignVariable('barOrFalse', new UnionType([new ObjectType('Bar'), new ConstantBooleanType(false)]));
		$this->scope = $this->scope->assignVariable('stringOrFalse', new UnionType([new StringType(), new ConstantBooleanType(false)]));
		$this->scope = $this->scope->assignVariable('array', new ArrayType(new MixedType(), new MixedType()));
		$this->scope = $this->scope->assignVariable('foo', new MixedType());
		$this->scope = $this->scope->assignVariable('classString', new ClassStringType());
		$this->scope = $this->scope->assignVariable('genericClassString', new GenericClassStringType(new ObjectType('Bar')));
	}

	/**
	 * @dataProvider dataCondition
	 * @param Expr  $expr
	 * @param mixed[] $expectedPositiveResult
	 * @param mixed[] $expectedNegatedResult
	 */
	public function testCondition(Expr $expr, array $expectedPositiveResult, array $expectedNegatedResult): void
	{
		$specifiedTypes = $this->typeSpecifier->specifyTypesInCondition($this->scope, $expr, TypeSpecifierContext::createTruthy());
		$actualResult = $this->toReadableResult($specifiedTypes);
		$this->assertSame($expectedPositiveResult, $actualResult, sprintf('if (%s)', $this->printer->prettyPrintExpr($expr)));

		$specifiedTypes = $this->typeSpecifier->specifyTypesInCondition($this->scope, $expr, TypeSpecifierContext::createFalsey());
		$actualResult = $this->toReadableResult($specifiedTypes);
		$this->assertSame($expectedNegatedResult, $actualResult, sprintf('if not (%s)', $this->printer->prettyPrintExpr($expr)));
	}

	public function dataCondition(): array
	{
		return [
			[
				$this->createFunctionCall('is_int'),
				['$foo' => 'int'],
				['$foo' => '~int'],
			],
			[
				$this->createFunctionCall('is_numeric'),
				['$foo' => 'float|int|(string&numeric)'],
				['$foo' => '~float|int'],
			],
			[
				$this->createFunctionCall('is_scalar'),
				['$foo' => 'bool|float|int|string'],
				['$foo' => '~bool|float|int|string'],
			],
			[
				new Expr\BinaryOp\BooleanAnd(
					$this->createFunctionCall('is_int'),
					$this->createFunctionCall('random')
				),
				['$foo' => 'int'],
				[],
			],
			[
				new Expr\BinaryOp\BooleanOr(
					$this->createFunctionCall('is_int'),
					$this->createFunctionCall('random')
				),
				[],
				['$foo' => '~int'],
			],
			[
				new Expr\BinaryOp\LogicalAnd(
					$this->createFunctionCall('is_int'),
					$this->createFunctionCall('random')
				),
				['$foo' => 'int'],
				[],
			],
			[
				new Expr\BinaryOp\LogicalOr(
					$this->createFunctionCall('is_int'),
					$this->createFunctionCall('random')
				),
				[],
				['$foo' => '~int'],
			],
			[
				new Expr\BooleanNot($this->createFunctionCall('is_int')),
				['$foo' => '~int'],
				['$foo' => 'int'],
			],

			[
				new Expr\BinaryOp\BooleanAnd(
					new Expr\BooleanNot($this->createFunctionCall('is_int')),
					$this->createFunctionCall('random')
				),
				['$foo' => '~int'],
				[],
			],
			[
				new Expr\BinaryOp\BooleanOr(
					new Expr\BooleanNot($this->createFunctionCall('is_int')),
					$this->createFunctionCall('random')
				),
				[],
				['$foo' => 'int'],
			],
			[
				new Expr\BooleanNot(new Expr\BooleanNot($this->createFunctionCall('is_int'))),
				['$foo' => 'int'],
				['$foo' => '~int'],
			],
			[
				$this->createInstanceOf('Foo'),
				['$foo' => 'Foo'],
				['$foo' => '~Foo'],
			],
			[
				new Expr\BooleanNot($this->createInstanceOf('Foo')),
				['$foo' => '~Foo'],
				['$foo' => 'Foo'],
			],
			[
				new Expr\Instanceof_(
					new Variable('foo'),
					new Variable('className')
				),
				['$foo' => 'object'],
				[],
			],
			[
				new Equal(
					new FuncCall(new Name('get_class'), [
						new Arg(new Variable('foo')),
					]),
					new String_('Foo')
				),
				['$foo' => 'Foo'],
				['$foo' => '~Foo'],
			],
			[
				new Equal(
					new String_('Foo'),
					new FuncCall(new Name('get_class'), [
						new Arg(new Variable('foo')),
					])
				),
				['$foo' => 'Foo'],
				['$foo' => '~Foo'],
			],
			[
				new BooleanNot(
					new Expr\Instanceof_(
						new Variable('foo'),
						new Variable('className')
					)
				),
				[],
				['$foo' => 'object'],
			],
			[
				new Variable('foo'),
				['$foo' => self::SURE_NOT_FALSEY],
				['$foo' => self::SURE_NOT_TRUTHY],
			],
			[
				new Expr\BinaryOp\BooleanAnd(
					new Variable('foo'),
					$this->createFunctionCall('random')
				),
				['$foo' => self::SURE_NOT_FALSEY],
				[],
			],
			[
				new Expr\BinaryOp\BooleanOr(
					new Variable('foo'),
					$this->createFunctionCall('random')
				),
				[],
				['$foo' => self::SURE_NOT_TRUTHY],
			],
			[
				new Expr\BooleanNot(new Variable('bar')),
				['$bar' => self::SURE_NOT_TRUTHY],
				['$bar' => self::SURE_NOT_FALSEY],
			],

			[
				new PropertyFetch(new Variable('this'), 'foo'),
				['$this->foo' => self::SURE_NOT_FALSEY],
				['$this->foo' => self::SURE_NOT_TRUTHY],
			],
			[
				new Expr\BinaryOp\BooleanAnd(
					new PropertyFetch(new Variable('this'), 'foo'),
					$this->createFunctionCall('random')
				),
				['$this->foo' => self::SURE_NOT_FALSEY],
				[],
			],
			[
				new Expr\BinaryOp\BooleanOr(
					new PropertyFetch(new Variable('this'), 'foo'),
					$this->createFunctionCall('random')
				),
				[],
				['$this->foo' => self::SURE_NOT_TRUTHY],
			],
			[
				new Expr\BooleanNot(new PropertyFetch(new Variable('this'), 'foo')),
				['$this->foo' => self::SURE_NOT_TRUTHY],
				['$this->foo' => self::SURE_NOT_FALSEY],
			],

			[
				new Expr\BinaryOp\BooleanOr(
					$this->createFunctionCall('is_int'),
					$this->createFunctionCall('is_string')
				),
				['$foo' => 'int|string'],
				['$foo' => '~int|string'],
			],
			[
				new Expr\BinaryOp\BooleanOr(
					$this->createFunctionCall('is_int'),
					new Expr\BinaryOp\BooleanOr(
						$this->createFunctionCall('is_string'),
						$this->createFunctionCall('is_bool')
					)
				),
				['$foo' => 'bool|int|string'],
				['$foo' => '~bool|int|string'],
			],
			[
				new Expr\BinaryOp\BooleanOr(
					$this->createFunctionCall('is_int', 'foo'),
					$this->createFunctionCall('is_string', 'bar')
				),
				[],
				['$foo' => '~int', '$bar' => '~string'],
			],
			[
				new Expr\BinaryOp\BooleanAnd(
					new Expr\BinaryOp\BooleanOr(
						$this->createFunctionCall('is_int', 'foo'),
						$this->createFunctionCall('is_string', 'foo')
					),
					$this->createFunctionCall('random')
				),
				['$foo' => 'int|string'],
				[],
			],
			[
				new Expr\BinaryOp\BooleanOr(
					new Expr\BinaryOp\BooleanAnd(
						$this->createFunctionCall('is_int', 'foo'),
						$this->createFunctionCall('is_string', 'foo')
					),
					$this->createFunctionCall('random')
				),
				[],
				['$foo' => '~*NEVER*'],
			],
			[
				new Expr\BinaryOp\BooleanOr(
					new Expr\BinaryOp\BooleanAnd(
						$this->createFunctionCall('is_int', 'foo'),
						$this->createFunctionCall('is_string', 'bar')
					),
					$this->createFunctionCall('random')
				),
				[],
				[],
			],
			[
				new Expr\BinaryOp\BooleanOr(
					new Expr\BinaryOp\BooleanAnd(
						new Expr\BooleanNot($this->createFunctionCall('is_int', 'foo')),
						new Expr\BooleanNot($this->createFunctionCall('is_string', 'foo'))
					),
					$this->createFunctionCall('random')
				),
				[],
				['$foo' => 'int|string'],
			],
			[
				new Expr\BinaryOp\BooleanAnd(
					new Expr\BinaryOp\BooleanOr(
						new Expr\BooleanNot($this->createFunctionCall('is_int', 'foo')),
						new Expr\BooleanNot($this->createFunctionCall('is_string', 'foo'))
					),
					$this->createFunctionCall('random')
				),
				['$foo' => '~*NEVER*'],
				[],
			],

			[
				new Identical(
					new Variable('foo'),
					new Expr\ConstFetch(new Name('true'))
				),
				['$foo' => 'true & ~' . self::FALSEY_TYPE_DESCRIPTION],
				['$foo' => '~true'],
			],
			[
				new Identical(
					new Variable('foo'),
					new Expr\ConstFetch(new Name('false'))
				),
				['$foo' => 'false & ~' . self::TRUTHY_TYPE_DESCRIPTION],
				['$foo' => '~false'],
			],
			[
				new Identical(
					$this->createFunctionCall('is_int'),
					new Expr\ConstFetch(new Name('true'))
				),
				['is_int($foo)' => 'true', '$foo' => 'int'],
				['is_int($foo)' => '~true', '$foo' => '~int'],
			],
			[
				new Identical(
					$this->createFunctionCall('is_int'),
					new Expr\ConstFetch(new Name('false'))
				),
				['is_int($foo)' => 'false', '$foo' => '~int'],
				['$foo' => 'int', 'is_int($foo)' => '~false'],
			],
			[
				new Equal(
					$this->createFunctionCall('is_int'),
					new Expr\ConstFetch(new Name('true'))
				),
				['$foo' => 'int'],
				['$foo' => '~int'],
			],
			[
				new Equal(
					$this->createFunctionCall('is_int'),
					new Expr\ConstFetch(new Name('false'))
				),
				['$foo' => '~int'],
				['$foo' => 'int'],
			],
			[
				new Equal(
					new Variable('foo'),
					new Expr\ConstFetch(new Name('false'))
				),
				['$foo' => self::SURE_NOT_TRUTHY],
				['$foo' => self::SURE_NOT_FALSEY],
			],
			[
				new Equal(
					new Variable('foo'),
					new Expr\ConstFetch(new Name('null'))
				),
				['$foo' => self::SURE_NOT_TRUTHY],
				['$foo' => self::SURE_NOT_FALSEY],
			],
			[
				new Expr\BinaryOp\Identical(
					new Variable('foo'),
					new Variable('bar')
				),
				['$foo' => 'Bar', '$bar' => 'Bar'],
				[],
			],
			[
				new FuncCall(new Name('is_a'), [
					new Arg(new Variable('foo')),
					new Arg(new String_('Foo')),
				]),
				['$foo' => 'Foo'],
				['$foo' => '~Foo'],
			],
			[
				new FuncCall(new Name('is_a'), [
					new Arg(new Variable('foo')),
					new Arg(new Variable('className')),
				]),
				['$foo' => 'object'],
				[],
			],
			[
				new FuncCall(new Name('is_a'), [
					new Arg(new Variable('foo')),
					new Arg(new Expr\ClassConstFetch(
						new Name('static'),
						'class'
					)),
				]),
				['$foo' => 'static(DateTime)'],
				['$foo' => '~static(DateTime)'],
			],
			[
				new FuncCall(new Name('is_a'), [
					new Arg(new Variable('foo')),
					new Arg(new Variable('classString')),
				]),
				['$foo' => 'object'],
				[],
			],
			[
				new FuncCall(new Name('is_a'), [
					new Arg(new Variable('foo')),
					new Arg(new Variable('genericClassString')),
				]),
				['$foo' => 'Bar'],
				['$foo' => '~Bar'],
			],
			[
				new FuncCall(new Name('is_a'), [
					new Arg(new Variable('foo')),
					new Arg(new String_('Foo')),
					new Arg(new Expr\ConstFetch(new Name('true'))),
				]),
				['$foo' => 'class-string<Foo>|Foo'],
				['$foo' => '~Foo'],
			],
			[
				new FuncCall(new Name('is_a'), [
					new Arg(new Variable('foo')),
					new Arg(new Variable('className')),
					new Arg(new Expr\ConstFetch(new Name('true'))),
				]),
				['$foo' => 'class-string<object>|object'],
				[],
			],
			[
				new FuncCall(new Name('is_a'), [
					new Arg(new Variable('foo')),
					new Arg(new String_('Foo')),
					new Arg(new Variable('unknown')),
				]),
				['$foo' => 'class-string<Foo>|Foo'],
				['$foo' => '~Foo'],
			],
			[
				new FuncCall(new Name('is_a'), [
					new Arg(new Variable('foo')),
					new Arg(new Variable('className')),
					new Arg(new Variable('unknown')),
				]),
				['$foo' => 'class-string<object>|object'],
				[],
			],
			[
				new Expr\Assign(
					new Variable('foo'),
					new Variable('stringOrNull')
				),
				['$foo' => self::SURE_NOT_FALSEY],
				['$foo' => self::SURE_NOT_TRUTHY],
			],
			[
				new Expr\Assign(
					new Variable('foo'),
					new Variable('stringOrFalse')
				),
				['$foo' => self::SURE_NOT_FALSEY],
				['$foo' => self::SURE_NOT_TRUTHY],
			],
			[
				new Expr\Assign(
					new Variable('foo'),
					new Variable('bar')
				),
				['$foo' => self::SURE_NOT_FALSEY],
				['$foo' => self::SURE_NOT_TRUTHY],
			],
			[
				new Expr\Isset_([
					new Variable('stringOrNull'),
					new Variable('barOrNull'),
				]),
				[
					'$stringOrNull' => '~null',
					'$barOrNull' => '~null',
				],
				[
					'isset($stringOrNull, $barOrNull)' => self::SURE_NOT_TRUTHY,
				],
			],
			[
				new Expr\BooleanNot(new Expr\Empty_(new Variable('stringOrNull'))),
				[
					'$stringOrNull' => '~0|0.0|\'\'|\'0\'|array()|false|null',
				],
				[],
			],
			[
				new Expr\BinaryOp\Identical(
					new Variable('foo'),
					new LNumber(123)
				),
				[
					'$foo' => '123',
					123 => '123',
				],
				['$foo' => '~123'],
			],
			[
				new Expr\Empty_(new Variable('array')),
				[],
				[
					'$array' => '~0|0.0|\'\'|\'0\'|array()|false|null',
				],
			],
			[
				new BooleanNot(new Expr\Empty_(new Variable('array'))),
				[
					'$array' => '~0|0.0|\'\'|\'0\'|array()|false|null',
				],
				[],
			],
			[
				new FuncCall(new Name('count'), [
					new Arg(new Variable('array')),
				]),
				[
					'$array' => 'nonEmpty',
				],
				[
					'$array' => '~nonEmpty',
				],
			],
			[
				new BooleanNot(new FuncCall(new Name('count'), [
					new Arg(new Variable('array')),
				])),
				[
					'$array' => '~nonEmpty',
				],
				[
					'$array' => 'nonEmpty',
				],
			],
			[
				new FuncCall(new Name('sizeof'), [
					new Arg(new Variable('array')),
				]),
				[
					'$array' => 'nonEmpty',
				],
				[
					'$array' => '~nonEmpty',
				],
			],
			[
				new BooleanNot(new FuncCall(new Name('sizeof'), [
					new Arg(new Variable('array')),
				])),
				[
					'$array' => '~nonEmpty',
				],
				[
					'$array' => 'nonEmpty',
				],
			],
			[
				new Variable('foo'),
				[
					'$foo' => self::SURE_NOT_FALSEY,
				],
				[
					'$foo' => self::SURE_NOT_TRUTHY,
				],
			],
			[
				new Variable('array'),
				[
					'$array' => self::SURE_NOT_FALSEY,
				],
				[
					'$array' => self::SURE_NOT_TRUTHY,
				],
			],
			[
				new Equal(
					new Expr\Instanceof_(
						new Variable('foo'),
						new Variable('className')
					),
					new LNumber(1)
				),
				['$foo' => 'object'],
				[],
			],
			[
				new Equal(
					new Expr\Instanceof_(
						new Variable('foo'),
						new Variable('className')
					),
					new LNumber(0)
				),
				[],
				[
					'$foo' => 'object',
				],
			],
			[
				new Expr\Isset_(
					[
						new PropertyFetch(new Variable('foo'), new Identifier('bar')),
					]
				),
				[
					'$foo' => 'object&hasProperty(bar) & ~null',
					'$foo->bar' => '~null',
				],
				[
					'isset($foo->bar)' => self::SURE_NOT_TRUTHY,
				],
			],
			[
				new Expr\Isset_(
					[
						new Expr\StaticPropertyFetch(new Name('Foo'), new VarLikeIdentifier('bar')),
					]
				),
				[
					'Foo::$bar' => '~null',
				],
				[
					'isset(Foo::$bar)' => self::SURE_NOT_TRUTHY,
				],
			],
			[
				new Identical(
					new Variable('barOrNull'),
					new Expr\ConstFetch(new Name('null'))
				),
				[
					'$barOrNull' => 'null',
				],
				[
					'$barOrNull' => '~null',
				],
			],
			[
				new Identical(
					new Expr\Assign(
						new Variable('notNullBar'),
						new Variable('barOrNull')
					),
					new Expr\ConstFetch(new Name('null'))
				),
				[
					'$notNullBar' => 'null',
				],
				[
					'$notNullBar' => '~null',
				],
			],
			[
				new NotIdentical(
					new Variable('barOrNull'),
					new Expr\ConstFetch(new Name('null'))
				),
				[
					'$barOrNull' => '~null',
				],
				[
					'$barOrNull' => 'null',
				],
			],
			[
				new Expr\BinaryOp\Smaller(
					new Variable('n'),
					new LNumber(3)
				),
				[
					'$n' => 'mixed~int<3, max>|true',
				],
				[
					'$n' => 'mixed~int<min, 2>|false|null',
				],
			],
			[
				new Expr\BinaryOp\Smaller(
					new Variable('n'),
					new LNumber(PHP_INT_MIN)
				),
				[
					'$n' => 'mixed~int<' . PHP_INT_MIN . ', max>|true',
				],
				[
					'$n' => 'mixed~false|null',
				],
			],
			[
				new Expr\BinaryOp\Greater(
					new Variable('n'),
					new LNumber(PHP_INT_MAX)
				),
				[
					'$n' => 'mixed~bool|int<min, ' . PHP_INT_MAX . '>|null',
				],
				[
					'$n' => 'mixed',
				],
			],
			[
				new Expr\BinaryOp\SmallerOrEqual(
					new Variable('n'),
					new LNumber(PHP_INT_MIN)
				),
				[
					'$n' => 'mixed~int<' . (PHP_INT_MIN + 1) . ', max>',
				],
				[
					'$n' => 'mixed~bool|int<min, ' . PHP_INT_MIN . '>|null',
				],
			],
			[
				new Expr\BinaryOp\GreaterOrEqual(
					new Variable('n'),
					new LNumber(PHP_INT_MAX)
				),
				[
					'$n' => 'mixed~int<min, ' . (PHP_INT_MAX - 1) . '>|false|null',
				],
				[
					'$n' => 'mixed~int<' . PHP_INT_MAX . ', max>|true',
				],
			],
			[
				new Expr\BinaryOp\BooleanAnd(
					new Expr\BinaryOp\GreaterOrEqual(
						new Variable('n'),
						new LNumber(3)
					),
					new Expr\BinaryOp\SmallerOrEqual(
						new Variable('n'),
						new LNumber(5)
					)
				),
				[
					'$n' => 'mixed~int<min, 2>|int<6, max>|false|null',
				],
				[
					'$n' => 'mixed~int<3, 5>|true',
				],
			],
			[
				new Expr\BinaryOp\BooleanAnd(
					new Expr\Assign(
						new Variable('foo'),
						new LNumber(1)
					),
					new Expr\BinaryOp\SmallerOrEqual(
						new Variable('n'),
						new LNumber(5)
					)
				),
				[
					'$n' => 'mixed~int<6, max>',
					'$foo' => self::SURE_NOT_FALSEY,
				],
				[],
			],
			[
				new NotIdentical(
					new Expr\Assign(
						new Variable('notNullBar'),
						new Variable('barOrNull')
					),
					new Expr\ConstFetch(new Name('null'))
				),
				[
					'$notNullBar' => '~null',
				],
				[
					'$notNullBar' => 'null',
				],
			],
			[
				new Identical(
					new Variable('barOrFalse'),
					new Expr\ConstFetch(new Name('false'))
				),
				[
					'$barOrFalse' => 'false & ' . self::SURE_NOT_TRUTHY,
				],
				[
					'$barOrFalse' => '~false',
				],
			],
			[
				new Identical(
					new Expr\Assign(
						new Variable('notFalseBar'),
						new Variable('barOrFalse')
					),
					new Expr\ConstFetch(new Name('false'))
				),
				[
					'$notFalseBar' => 'false & ' . self::SURE_NOT_TRUTHY,
				],
				[
					'$notFalseBar' => '~false',
				],
			],
			[
				new NotIdentical(
					new Variable('barOrFalse'),
					new Expr\ConstFetch(new Name('false'))
				),
				[
					'$barOrFalse' => '~false',
				],
				[
					'$barOrFalse' => 'false & ' . self::SURE_NOT_TRUTHY,
				],
			],
			[
				new NotIdentical(
					new Expr\Assign(
						new Variable('notFalseBar'),
						new Variable('barOrFalse')
					),
					new Expr\ConstFetch(new Name('false'))
				),
				[
					'$notFalseBar' => '~false',
				],
				[
					'$notFalseBar' => 'false & ' . self::SURE_NOT_TRUTHY,
				],
			],
			[
				new Expr\Instanceof_(
					new Expr\Assign(
						new Variable('notFalseBar'),
						new Variable('barOrFalse')
					),
					new Name('Bar')
				),
				[
					'$notFalseBar' => 'Bar',
				],
				[
					'$notFalseBar' => '~Bar',
				],
			],
			[
				new Expr\BinaryOp\BooleanOr(
					new FuncCall(new Name('array_key_exists'), [
						new Arg(new String_('foo')),
						new Arg(new Variable('array')),
					]),
					new FuncCall(new Name('array_key_exists'), [
						new Arg(new String_('bar')),
						new Arg(new Variable('array')),
					])
				),
				[
					'$array' => 'array',
				],
				[
					'$array' => '~hasOffset(\'bar\')|hasOffset(\'foo\')',
				],
			],
			[
				new BooleanNot(new Expr\BinaryOp\BooleanOr(
					new FuncCall(new Name('array_key_exists'), [
						new Arg(new String_('foo')),
						new Arg(new Variable('array')),
					]),
					new FuncCall(new Name('array_key_exists'), [
						new Arg(new String_('bar')),
						new Arg(new Variable('array')),
					])
				)),
				[
					'$array' => '~hasOffset(\'bar\')|hasOffset(\'foo\')',
				],
				[
					'$array' => 'array',
				],
			],
			[
				new FuncCall(new Name('array_key_exists'), [
					new Arg(new String_('foo')),
					new Arg(new Variable('array')),
				]),
				[
					'$array' => 'array&hasOffset(\'foo\')',
				],
				[
					'$array' => '~hasOffset(\'foo\')',
				],
			],
			[
				new FuncCall(new Name('is_subclass_of'), [
					new Arg(new Variable('string')),
					new Arg(new Variable('stringOrNull')),
				]),
				[
					'$string' => 'class-string|object',
				],
				[],
			],
			[
				new FuncCall(new Name('is_subclass_of'), [
					new Arg(new Variable('string')),
					new Arg(new Variable('stringOrNull')),
					new Arg(new Expr\ConstFetch(new Name('false'))),
				]),
				[
					'$string' => 'object',
				],
				[],
			],
		];
	}

	/**
	 * @param \PHPStan\Analyser\SpecifiedTypes $specifiedTypes
	 * @return mixed[]
	 */
	private function toReadableResult(SpecifiedTypes $specifiedTypes): array
	{
		$typesDescription = [];

		foreach ($specifiedTypes->getSureTypes() as $exprString => [$exprNode, $exprType]) {
			$typesDescription[$exprString][] = $exprType->describe(VerbosityLevel::precise());
		}

		foreach ($specifiedTypes->getSureNotTypes() as $exprString => [$exprNode, $exprType]) {
			$typesDescription[$exprString][] = '~' . $exprType->describe(VerbosityLevel::precise());
		}

		$descriptions = [];
		foreach ($typesDescription as $exprString => $exprTypes) {
			$descriptions[$exprString] = implode(' & ', $exprTypes);
		}

		return $descriptions;
	}

	private function createInstanceOf(string $className, string $variableName = 'foo'): Expr\Instanceof_
	{
		return new Expr\Instanceof_(new Variable($variableName), new Name($className));
	}

	private function createFunctionCall(string $functionName, string $variableName = 'foo'): FuncCall
	{
		return new FuncCall(new Name($functionName), [new Arg(new Variable($variableName))]);
	}

}
