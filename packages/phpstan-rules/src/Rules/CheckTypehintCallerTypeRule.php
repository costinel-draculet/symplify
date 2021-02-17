<?php

declare(strict_types=1);

namespace Symplify\PHPStanRules\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\ThisType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;
use Symplify\PHPStanRules\NodeFinder\ClassMethodNodeFinder;
use Symplify\PHPStanRules\NodeFinder\MethodCallNodeFinder;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @see \Symplify\PHPStanRules\Tests\Rules\CheckTypehintCallerTypeRule\CheckTypehintCallerTypeRuleTest
 */
final class CheckTypehintCallerTypeRule extends AbstractSymplifyRule
{
    /**
     * @var string
     */
    public const ERROR_MESSAGE = 'Parameter %d should use "%s" type as the only type passed to this method';

    /**
     * @var MethodCallNodeFinder
     */
    private $methodCallNodeFinder;

    /**
     * @var ClassMethodNodeFinder
     */
    private $classMethodNodeFinder;

    public function __construct(
        MethodCallNodeFinder $methodCallNodeFinder,
        ClassMethodNodeFinder $classMethodNodeFinder
    ) {
        $this->methodCallNodeFinder = $methodCallNodeFinder;
        $this->classMethodNodeFinder = $classMethodNodeFinder;
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [MethodCall::class];
    }

    /**
     * @param MethodCall $node
     * @return RuleError[]
     */
    public function process(Node $node, Scope $scope): array
    {
        $type = $scope->getType($node->var);
        if (! $type instanceof ThisType) {
            return [];
        }

        $args = $node->args;
        if ($args === []) {
            return [];
        }

        return $this->validateArgVsParamTypes($args, $node, $scope);
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(self::ERROR_MESSAGE, [
            new CodeSample(
                <<<'CODE_SAMPLE'
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;

class SomeClass
{
    public function run(MethodCall $node)
    {
        $this->isCheck($node);
    }

    private function isCheck(Node $node)
    {
    }
}
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;

class SomeClass
{
    public function run(MethodCall $node)
    {
        $this->isCheck($node);
    }

    private function isCheck(MethodCall $node)
    {
    }
}
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @param Arg[] $args
     * @return RuleError[]
     */
    private function validateArgVsParamTypes(array $args, MethodCall $methodCall, Scope $scope): array
    {
        $methodCallUses = $this->methodCallNodeFinder->findUsages($methodCall);
        if (count($methodCallUses) > 1) {
            return [];
        }

        $classMethod = $this->classMethodNodeFinder->findByMethodCall($methodCall);
        if (! $classMethod instanceof ClassMethod) {
            return [];
        }

        /** @var Param[] $params */
        $params = $classMethod->getParams();

        $errorMessages = [];

        foreach ($args as $position => $arg) {
            $param = $params[$position] ?? [];
            if (! $param instanceof Param) {
                continue;
            }

            $argType = $scope->getType($arg->value);
            if ($argType instanceof MixedType) {
                continue;
            }

            $paramErrorMessage = $this->validateParam($param, $position, $argType);
            if ($paramErrorMessage === null) {
                continue;
            }

            // @todo test double failed type
            $errorMessages[] = $paramErrorMessage;
        }

        return $errorMessages;
    }

    private function validateParam(Param $param, int $position, Type $argType): ?RuleError
    {
        $type = $param->type;
        // @todo some static type mapper from php-parser to PHPStan?
        if (! $type instanceof FullyQualified) {
            return null;
        }

        // not solveable yet, work with PHP 8 code only
        if ($argType instanceof UnionType) {
            return null;
        }

        if ($argType instanceof IntersectionType) {
            return null;
        }

        $objectType = new ObjectType($type->toString());
        if ($objectType->equals($argType)) {
            return null;
        }

        // handle weird type substration cases
        $paramTypeAsString = $objectType->describe(VerbosityLevel::typeOnly());
        $argTypeAsString = $argType->describe(VerbosityLevel::typeOnly());

        if ($paramTypeAsString === $argTypeAsString) {
            return null;
        }

        $errorMessage = sprintf(self::ERROR_MESSAGE, $position + 1, $argTypeAsString);

        $ruleErrorBuilder = RuleErrorBuilder::message($errorMessage)
            ->line($param->getLine());

        return $ruleErrorBuilder->build();
    }
}
