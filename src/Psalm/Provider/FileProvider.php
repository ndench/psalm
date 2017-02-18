<?php
namespace Psalm\Provider;

class FileProvider
{
    /**
     * @param  string  $file_path
     *
     * @return string
     */
    public function getContents($file_path)
    {
        return (string)file_get_contents($file_path);
    }

    /**
     * @param  string $file_path
     *
     * @return int
     */
    public function getModifiedTime($file_path)
    {
        return (int)filemtime($file_path);
    }

    /**
     * @param  string $file_path
     *
     * @return bool
     */
    public function fileExists($file_path)
    {
        return file_exists($file_path);
    }

    /**
     * Returns the node at a specified position
     * @param array<PhpParser\Node> $stmts
     * @param \LanguageServer\Protocol\Position $position
     * @return PhpParser\Node|null
     */
    public static function getNodeAtPosition(array $stmts, \LanguageServer\Protocol\Position $position)
    {
        $traverser = new PhpParser\NodeTraverser;
        $finder = new \LanguageServer\NodeVisitor\NodeAtPositionFinder($position);
        $traverser->addVisitor($finder);
        $traverser->traverse($stmts);
        return $finder->node;
    }
}
