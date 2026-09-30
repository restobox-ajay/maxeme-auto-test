<?php

declare(strict_types=1);

namespace App\Maxeme\Doctrine;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL REPLACE(subject, search, replace), SQL's own (SQLite, MySQL and PostgreSQL all have it).
 * Lets the sidebar search compare phone numbers without their punctuation.
 */
final class ReplaceFunction extends FunctionNode
{
    private Node $subject;
    private Node $search;
    private Node $replace;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->subject = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->search = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->replace = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            'REPLACE(%s, %s, %s)',
            $this->subject->dispatch($sqlWalker),
            $this->search->dispatch($sqlWalker),
            $this->replace->dispatch($sqlWalker),
        );
    }
}
