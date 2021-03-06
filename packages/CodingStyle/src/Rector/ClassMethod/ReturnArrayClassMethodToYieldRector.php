<?php

declare(strict_types=1);

namespace Rector\CodingStyle\Rector\ClassMethod;

use Iterator;
use PhpParser\Comment;
use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use Rector\Exception\ShouldNotHappenException;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PhpParser\NodeTransformer;
use Rector\Rector\AbstractRector;
use Rector\RectorDefinition\ConfiguredCodeSample;
use Rector\RectorDefinition\RectorDefinition;

/**
 * @see https://medium.com/tech-tajawal/use-memory-gently-with-yield-in-php-7e62e2480b8d
 * @see https://3v4l.org/5PJid
 * @see \Rector\CodingStyle\Tests\Rector\ClassMethod\ReturnArrayClassMethodToYieldRector\ReturnArrayClassMethodToYieldRectorTest
 */
final class ReturnArrayClassMethodToYieldRector extends AbstractRector
{
    /**
     * @var string[][]
     */
    private $methodsByType = [];

    /**
     * @var NodeTransformer
     */
    private $nodeTransformer;

    /**
     * @var Doc|null
     */
    private $returnDocComment;

    /**
     * @var Comment[]
     */
    private $returnComments = [];

    /**
     * @param string[][] $methodsByType
     */
    public function __construct(NodeTransformer $nodeTransformer, array $methodsByType = [])
    {
        $this->nodeTransformer = $nodeTransformer;
        $this->methodsByType = $methodsByType;
    }

    public function getDefinition(): RectorDefinition
    {
        return new RectorDefinition('Turns array return to yield return in specific type and method', [
            new ConfiguredCodeSample(
                <<<'PHP'
class SomeEventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return ['event' => 'callback'];
    }
}
PHP
                ,
                <<<'PHP'
class SomeEventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        yield 'event' => 'callback';
    }
}
PHP
                ,
                [
                    'EventSubscriberInterface' => ['getSubscribedEvents'],
                ]
            ),
        ]);
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
     */
    public function refactor(Node $node): ?Node
    {
        foreach ($this->methodsByType as $type => $methods) {
            if (! $this->isObjectType($node, $type)) {
                continue;
            }

            foreach ($methods as $methodName) {
                if (! $this->isName($node, $methodName)) {
                    continue;
                }

                $arrayNode = $this->collectReturnArrayNodesFromClassMethod($node);
                if ($arrayNode === null) {
                    continue;
                }

                $this->transformArrayToYieldsOnMethodNode($node, $arrayNode);

                $this->completeComments($node);
            }
        }

        return $node;
    }

    private function collectReturnArrayNodesFromClassMethod(ClassMethod $classMethod): ?Array_
    {
        if ($classMethod->stmts === null) {
            return null;
        }

        foreach ($classMethod->stmts as $statement) {
            if ($statement instanceof Return_) {
                if (! $statement->expr instanceof Array_) {
                    continue;
                }

                $this->collectComments($statement);

                return $statement->expr;
            }
        }

        return null;
    }

    private function transformArrayToYieldsOnMethodNode(ClassMethod $classMethod, Array_ $arrayNode): void
    {
        $yieldNodes = $this->nodeTransformer->transformArrayToYields($arrayNode);

        // remove whole return node
        $parentNode = $arrayNode->getAttribute(AttributeKey::PARENT_NODE);
        if ($parentNode === null) {
            throw new ShouldNotHappenException();
        }

        $this->removeNode($parentNode);

        // remove doc block
        $this->docBlockManipulator->removeTagFromNode($classMethod, 'return');

        // change return typehint
        $classMethod->returnType = new FullyQualified(Iterator::class);

        $classMethod->stmts = array_merge((array) $classMethod->stmts, $yieldNodes);
    }

    private function completeComments(Node $node): void
    {
        if ($this->returnDocComment) {
            $node->setDocComment($this->returnDocComment);
        } elseif ($this->returnComments) {
            $node->setAttribute('comments', $this->returnComments);
        }
    }

    private function collectComments(Node $node): void
    {
        $this->returnDocComment = $node->getDocComment();
        $this->returnComments = $node->getComments();
    }
}
