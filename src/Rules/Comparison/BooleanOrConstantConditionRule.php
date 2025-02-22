<?php declare(strict_types = 1);

namespace PHPStan\Rules\Comparison;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\BooleanOrNode;
use PHPStan\Parser\LastConditionVisitor;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantBooleanType;
use function count;
use function sprintf;

/**
 * @implements Rule<BooleanOrNode>
 */
class BooleanOrConstantConditionRule implements Rule
{

	public function __construct(
		private ConstantConditionRuleHelper $helper,
		private bool $treatPhpDocTypesAsCertain,
		private bool $bleedingEdge,
		private bool $reportAlwaysTrueInLastCondition,
	)
	{
	}

	public function getNodeType(): string
	{
		return BooleanOrNode::class;
	}

	public function processNode(
		Node $node,
		Scope $scope,
	): array
	{
		$originalNode = $node->getOriginalNode();
		$nodeText = $this->bleedingEdge ? $originalNode->getOperatorSigil() : '||';
		$messages = [];
		$leftType = $this->helper->getBooleanType($scope, $originalNode->left);
		$tipText = 'Because the type is coming from a PHPDoc, you can turn off this check by setting <fg=cyan>treatPhpDocTypesAsCertain: false</> in your <fg=cyan>%configurationFile%</>.';
		if ($leftType instanceof ConstantBooleanType) {
			$addTipLeft = function (RuleErrorBuilder $ruleErrorBuilder) use ($scope, $originalNode, $tipText): RuleErrorBuilder {
				if (!$this->treatPhpDocTypesAsCertain) {
					return $ruleErrorBuilder;
				}

				$booleanNativeType = $this->helper->getNativeBooleanType($scope, $originalNode->left);
				if ($booleanNativeType instanceof ConstantBooleanType) {
					return $ruleErrorBuilder;
				}

				return $ruleErrorBuilder->tip($tipText);
			};

			$isLast = $node->getAttribute(LastConditionVisitor::ATTRIBUTE_NAME);
			if (!$leftType->getValue() || $isLast !== true || $this->reportAlwaysTrueInLastCondition) {
				$errorBuilder = $addTipLeft(RuleErrorBuilder::message(sprintf(
					'Left side of %s is always %s.',
					$nodeText,
					$leftType->getValue() ? 'true' : 'false',
				)))->line($originalNode->left->getLine());
				if ($leftType->getValue() && $isLast === false && !$this->reportAlwaysTrueInLastCondition) {
					$errorBuilder->tip('Remove remaining cases below this one and this error will disappear too.');
				}
				$messages[] = $errorBuilder->build();
			}
		}

		$rightScope = $node->getRightScope();
		$rightType = $this->helper->getBooleanType(
			$rightScope,
			$originalNode->right,
		);
		if ($rightType instanceof ConstantBooleanType && !$scope->isInFirstLevelStatement()) {
			$addTipRight = function (RuleErrorBuilder $ruleErrorBuilder) use ($rightScope, $originalNode, $tipText): RuleErrorBuilder {
				if (!$this->treatPhpDocTypesAsCertain) {
					return $ruleErrorBuilder;
				}

				$booleanNativeType = $this->helper->getNativeBooleanType(
					$rightScope,
					$originalNode->right,
				);
				if ($booleanNativeType instanceof ConstantBooleanType) {
					return $ruleErrorBuilder;
				}

				return $ruleErrorBuilder->tip($tipText);
			};

			$isLast = $node->getAttribute(LastConditionVisitor::ATTRIBUTE_NAME);
			if (!$rightType->getValue() || $isLast !== true || $this->reportAlwaysTrueInLastCondition) {
				$errorBuilder = $addTipRight(RuleErrorBuilder::message(sprintf(
					'Right side of %s is always %s.',
					$nodeText,
					$rightType->getValue() ? 'true' : 'false',
				)))->line($originalNode->right->getLine());
				if ($rightType->getValue() && $isLast === false && !$this->reportAlwaysTrueInLastCondition) {
					$errorBuilder->tip('Remove remaining cases below this one and this error will disappear too.');
				}
				$messages[] = $errorBuilder->build();
			}
		}

		if (count($messages) === 0 && !$scope->isInFirstLevelStatement()) {
			$nodeType = $this->treatPhpDocTypesAsCertain ? $scope->getType($originalNode) : $scope->getNativeType($originalNode);
			if ($nodeType instanceof ConstantBooleanType) {
				$addTip = function (RuleErrorBuilder $ruleErrorBuilder) use ($scope, $originalNode, $tipText): RuleErrorBuilder {
					if (!$this->treatPhpDocTypesAsCertain) {
						return $ruleErrorBuilder;
					}

					$booleanNativeType = $scope->getNativeType($originalNode);
					if ($booleanNativeType instanceof ConstantBooleanType) {
						return $ruleErrorBuilder;
					}

					return $ruleErrorBuilder->tip($tipText);
				};

				$isLast = $node->getAttribute(LastConditionVisitor::ATTRIBUTE_NAME);
				if (!$nodeType->getValue() || $isLast !== true || $this->reportAlwaysTrueInLastCondition) {
					$errorBuilder = $addTip(RuleErrorBuilder::message(sprintf(
						'Result of %s is always %s.',
						$nodeText,
						$nodeType->getValue() ? 'true' : 'false',
					)));
					if ($nodeType->getValue() && $isLast === false && !$this->reportAlwaysTrueInLastCondition) {
						$errorBuilder->tip('Remove remaining cases below this one and this error will disappear too.');
					}

					$messages[] = $errorBuilder->build();
				}
			}
		}

		return $messages;
	}

}
