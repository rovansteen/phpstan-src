<?php declare(strict_types = 1);

namespace PHPStan\Rules\Comparison;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\MatchExpressionNode;
use PHPStan\Parser\TryCatchTypeVisitor;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\NeverType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;
use UnhandledMatchError;
use function array_map;
use function count;
use function sprintf;

/**
 * @implements Rule<MatchExpressionNode>
 */
class MatchExpressionRule implements Rule
{

	public function __construct(
		private ConstantConditionRuleHelper $constantConditionRuleHelper,
		private bool $checkAlwaysTrueStrictComparison,
		private bool $disableUnreachable,
		private bool $reportAlwaysTrueInLastCondition,
		private bool $treatPhpDocTypesAsCertain,
	)
	{
	}

	public function getNodeType(): string
	{
		return MatchExpressionNode::class;
	}

	public function processNode(Node $node, Scope $scope): array
	{
		$matchCondition = $node->getCondition();
		$matchConditionType = $scope->getType($matchCondition);
		$nextArmIsDead = false;
		$errors = [];
		$armsCount = count($node->getArms());
		$hasDefault = false;
		foreach ($node->getArms() as $i => $arm) {
			if ($nextArmIsDead) {
				if (!$this->disableUnreachable) {
					$errors[] = RuleErrorBuilder::message('Match arm is unreachable because previous comparison is always true.')->line($arm->getLine())->build();
				}
				continue;
			}
			$armConditions = $arm->getConditions();
			if (count($armConditions) === 0) {
				$hasDefault = true;
			}
			foreach ($armConditions as $armCondition) {
				$armConditionScope = $armCondition->getScope();
				$armConditionExpr = new Node\Expr\BinaryOp\Identical(
					$matchCondition,
					$armCondition->getCondition(),
				);
				$armConditionResult = ($this->treatPhpDocTypesAsCertain ? $armConditionScope->getType($armConditionExpr) : $armConditionScope->getNativeType($armConditionExpr));
				if (!$armConditionResult instanceof ConstantBooleanType) {
					continue;
				}

				if ($armConditionResult->getValue()) {
					$nextArmIsDead = true;
				}

				if ($matchConditionType instanceof ConstantBooleanType) {
					$armConditionStandaloneResult = $this->constantConditionRuleHelper->getBooleanType($armConditionScope, $armCondition->getCondition());
					if (!$armConditionStandaloneResult instanceof ConstantBooleanType) {
						continue;
					}
				}

				$armLine = $armCondition->getLine();
				if (!$armConditionResult->getValue()) {
					$errors[] = RuleErrorBuilder::message(sprintf(
						'Match arm comparison between %s and %s is always false.',
						$armConditionScope->getType($matchCondition)->describe(VerbosityLevel::value()),
						$armConditionScope->getType($armCondition->getCondition())->describe(VerbosityLevel::value()),
					))->line($armLine)->build();
				} else {
					if ($this->checkAlwaysTrueStrictComparison) {
						if ($i === $armsCount - 1 && !$this->reportAlwaysTrueInLastCondition) {
							continue;
						}
						$errorBuilder = RuleErrorBuilder::message(sprintf(
							'Match arm comparison between %s and %s is always true.',
							$armConditionScope->getType($matchCondition)->describe(VerbosityLevel::value()),
							$armConditionScope->getType($armCondition->getCondition())->describe(VerbosityLevel::value()),
						))->line($armLine);
						if ($i !== $armsCount - 1 && !$this->reportAlwaysTrueInLastCondition) {
							$errorBuilder->tip('Remove remaining cases below this one and this error will disappear too.');
						}
						$errors[] = $errorBuilder->build();
					}
				}
			}
		}

		if (!$hasDefault && !$nextArmIsDead) {
			$remainingType = $node->getEndScope()->getType($matchCondition);
			$cases = $remainingType->getEnumCases();
			$casesCount = count($cases);
			if ($casesCount > 1) {
				$remainingType = new UnionType($cases);
			}
			if ($casesCount === 1) {
				$remainingType = $cases[0];
			}
			if (
				!$remainingType instanceof NeverType
				&& !$this->isUnhandledMatchErrorCaught($node)
				&& !$this->hasUnhandledMatchErrorThrowsTag($scope)
			) {
				$errors[] = RuleErrorBuilder::message(sprintf(
					'Match expression does not handle remaining %s: %s',
					$remainingType instanceof UnionType ? 'values' : 'value',
					$remainingType->describe(VerbosityLevel::value()),
				))->build();
			}
		}

		return $errors;
	}

	private function isUnhandledMatchErrorCaught(Node $node): bool
	{
		$tryCatchTypes = $node->getAttribute(TryCatchTypeVisitor::ATTRIBUTE_NAME);
		if ($tryCatchTypes === null) {
			return false;
		}

		$tryCatchType = TypeCombinator::union(...array_map(static fn (string $class) => new ObjectType($class), $tryCatchTypes));

		return $tryCatchType->isSuperTypeOf(new ObjectType(UnhandledMatchError::class))->yes();
	}

	private function hasUnhandledMatchErrorThrowsTag(Scope $scope): bool
	{
		$function = $scope->getFunction();
		if ($function === null) {
			return false;
		}

		$throwsType = $function->getThrowType();
		if ($throwsType === null) {
			return false;
		}

		return $throwsType->isSuperTypeOf(new ObjectType(UnhandledMatchError::class))->yes();
	}

}
