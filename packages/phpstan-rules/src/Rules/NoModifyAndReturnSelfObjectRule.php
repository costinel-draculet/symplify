<?php

declare(strict_types=1);

namespace Symplify\PHPStanRules\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use Symplify\PHPStanRules\NodeFinder\ReturnNodeFinder;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @see \Symplify\PHPStanRules\Tests\Rules\NoModifyAndReturnSelfObjectRule\NoModifyAndReturnSelfObjectRuleTest
 */
final class NoModifyAndReturnSelfObjectRule extends AbstractSymplifyRule
{
    /**
     * @var string
     */
    public const ERROR_MESSAGE = 'Use void instead of modify and return self object';

    /**
     * @var ReturnNodeFinder
     */
    private $returnNodeFinder;

    public function __construct(ReturnNodeFinder $returnNodeFinder)
    {
        $this->returnNodeFinder = $returnNodeFinder;
    }

    /**
     * @return string[]
     */
    public function getNodeTypes(): array
    {
        return [ClassMethod::class];
    }

    /**
     * @param ClassMethod $node
     * @return string[]
     */
    public function process(Node $node, Scope $scope): array
    {
        if ($node->params === []) {
            return [];
        }

        $returns = $this->returnNodeFinder->findReturnsWithValues($node);

        if ($returns === []) {
            return [];
        }

        foreach ($returns as $return) {
            if (! $return->expr instanceof Expr) {
                continue;
            }
        }

        return [self::ERROR_MESSAGE];
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(self::ERROR_MESSAGE, [
            new CodeSample(
                <<<'CODE_SAMPLE'
final class SomeClass
{
    public function modify(ComposerJson $composerJson): ComposerJson
    {
        $composerJson->addRequiredPackage($this->packageName, $this->version->getVersion());
        return $composerJson;
    }
}
CODE_SAMPLE
                ,
                <<<'CODE_SAMPLE'
final class SomeClass
{
    public function modify(ComposerJson $composerJson): void
    {
        $composerJson->addRequiredPackage($this->packageName, $this->version->getVersion());
    }
}
CODE_SAMPLE
            ),
        ]);
    }
}
