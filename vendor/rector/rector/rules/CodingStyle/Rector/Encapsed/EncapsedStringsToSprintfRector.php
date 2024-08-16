<?php

declare (strict_types=1);
namespace Rector\CodingStyle\Rector\Encapsed;

use RectorPrefix202407\Nette\Utils\Strings;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Encapsed;
use PhpParser\Node\Scalar\EncapsedStringPart;
use PhpParser\Node\Scalar\String_;
use PHPStan\Type\Type;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
/**
 * @see \Rector\Tests\CodingStyle\Rector\Encapsed\EncapsedStringsToSprintfRector\EncapsedStringsToSprintfRectorTest
 */
final class EncapsedStringsToSprintfRector extends AbstractRector implements ConfigurableRectorInterface
{
    /**
     * @api
     * @var string
     */
    public const ALWAYS = 'always';
    /**
     * @var array<string, array<class-string<Type>>>
     */
    private const FORMAT_SPECIFIERS = ['%s' => ['PHPStan\\Type\\StringType'], '%d' => ['PHPStan\\Type\\Constant\\ConstantIntegerType', 'PHPStan\\Type\\IntegerRangeType', 'PHPStan\\Type\\IntegerType']];
    /**
     * @var bool
     */
    private $always = \false;
    /**
     * @var string
     */
    private $sprintfFormat = '';
    /**
     * @var Expr[]
     */
    private $argumentVariables = [];
    public function configure(array $configuration) : void
    {
        $this->always = $configuration[self::ALWAYS] ?? \false;
    }
    public function getRuleDefinition() : RuleDefinition
    {
        return new RuleDefinition('Convert enscaped {$string} to more readable sprintf or concat, if no mask is used', [new ConfiguredCodeSample(<<<'CODE_SAMPLE'
echo "Unsupported format {$format} - use another";

echo "Try {$allowed}";
CODE_SAMPLE
, <<<'CODE_SAMPLE'
echo sprintf('Unsupported format %s - use another', $format);

echo 'Try ' . $allowed;
CODE_SAMPLE
, [self::ALWAYS => \false]), new ConfiguredCodeSample(<<<'CODE_SAMPLE'
echo "Unsupported format {$format} - use another";

echo "Try {$allowed}";
CODE_SAMPLE
, <<<'CODE_SAMPLE'
echo sprintf('Unsupported format %s - use another', $format);

echo sprintf('Try %s', $allowed);
CODE_SAMPLE
, [self::ALWAYS => \true])]);
    }
    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes() : array
    {
        return [Encapsed::class];
    }
    /**
     * @param Encapsed $node
     */
    public function refactor(Node $node) : ?Node
    {
        if ($this->shouldSkip($node)) {
            return null;
        }
        $this->sprintfFormat = '';
        $this->argumentVariables = [];
        foreach ($node->parts as $part) {
            if ($part instanceof EncapsedStringPart) {
                $this->collectEncapsedStringPart($part);
            } else {
                $this->collectExpr($part);
            }
        }
        return $this->createSprintfFuncCallOrConcat($this->sprintfFormat, $this->argumentVariables);
    }
    private function shouldSkip(Encapsed $encapsed) : bool
    {
        return $encapsed->hasAttribute(AttributeKey::DOC_LABEL);
    }
    private function collectEncapsedStringPart(EncapsedStringPart $encapsedStringPart) : void
    {
        $stringValue = $encapsedStringPart->value;
        if ($stringValue === "\n") {
            $this->argumentVariables[] = new ConstFetch(new Name('PHP_EOL'));
            $this->sprintfFormat .= '%s';
            return;
        }
        $this->sprintfFormat .= Strings::replace($stringValue, '#%#', '%%');
    }
    private function collectExpr(Expr $expr) : void
    {
        $type = $this->nodeTypeResolver->getType($expr);
        $found = \false;
        foreach (self::FORMAT_SPECIFIERS as $key => $types) {
            if (\in_array(\get_class($type), $types, \true)) {
                $this->sprintfFormat .= $key;
                $found = \true;
                break;
            }
        }
        if (!$found) {
            $this->sprintfFormat .= '%s';
        }
        // remove: ${wrap} → $wrap
        if ($expr instanceof Variable) {
            $expr->setAttribute(AttributeKey::ORIGINAL_NODE, null);
        }
        $this->argumentVariables[] = $expr;
    }
    /**
     * @param Expr[] $argumentVariables
     * @return \PhpParser\Node\Expr\BinaryOp\Concat|\PhpParser\Node\Expr\FuncCall|\PhpParser\Node\Expr|null
     */
    private function createSprintfFuncCallOrConcat(string $mask, array $argumentVariables)
    {
        $bareMask = \str_repeat('%s', \count($argumentVariables));
        if ($mask === $bareMask) {
            if (\count($argumentVariables) === 1) {
                return $argumentVariables[0];
            }
            return $this->nodeFactory->createConcat($argumentVariables);
        }
        if (!$this->always) {
            $singleValueConcat = $this->createSingleValueEdgeConcat($argumentVariables, $mask);
            if ($singleValueConcat instanceof Concat) {
                return $singleValueConcat;
            }
        }
        // checks for windows or linux line ending. \n is contained in both.
        if (\strpos($mask, "\n") !== \false) {
            return null;
        }
        $arguments = [new Arg(new String_($mask))];
        foreach ($argumentVariables as $argumentVariable) {
            $arguments[] = new Arg($argumentVariable);
        }
        return new FuncCall(new Name('sprintf'), $arguments);
    }
    /**
     * @param Expr[] $argumentVariables
     */
    private function createSingleValueEdgeConcat(array $argumentVariables, string $mask) : ?Concat
    {
        if (\count($argumentVariables) !== 1) {
            return null;
        }
        if (\substr_count($mask, '%s') !== 1 && \substr_count($mask, '%d') !== 1) {
            return null;
        }
        $cleanMask = Strings::replace($mask, '#\\%\\%#', '%');
        if (\substr_compare($mask, '%s', -\strlen('%s')) === 0 || \substr_compare($mask, '%d', -\strlen('%d')) === 0) {
            $bareString = new String_(\substr($cleanMask, 0, -2));
            return new Concat($bareString, $argumentVariables[0]);
        }
        if (\strncmp($mask, '%s', \strlen('%s')) === 0 || \strncmp($mask, '%d', \strlen('%d')) === 0) {
            $bareString = new String_(\substr($cleanMask, 2));
            return new Concat($argumentVariables[0], $bareString);
        }
        return null;
    }
}
