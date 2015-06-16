<?php

require_once("./PHP-Parser/lib/bootstrap.php");

use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\PrettyPrinter\Standard;

class ProtovatePretty extends Standard
{
	public $wrapAssignLeft  = "XX\001LEFT";
	public $wrapAssignRight = "YY\001RIGHT";
	public $wrapAssignSplit = "ZZ\001ZZ";
	public $extraLine		= "X\001Y";

	function findAssignmentBlocks(&$pNodes)
	{
		$start = -1;
		$count = 0;
		$max   = count($pNodes);

		$SplitPattern = "/" . $this->wrapAssignLeft  . "(.*)" .
							  $this->wrapAssignSplit . "(.*)" .
							  $this->wrapAssignSplit . "(.*)" .
							  $this->wrapAssignRight . "/";

		for ($x=0; $x<$max; $x++)
		{
			if (preg_match("/" . $this->wrapAssignLeft . "/", $pNodes[$x]))
			{
				if ($start == -1) $start = $x;
				$count++;
			} else {
				if ($start != -1)
				{
					$highest = 0;
					for ($a=0; $a<$count; $a++)
					{
						preg_match($SplitPattern, $pNodes[$a + $start], $m);
						if (strlen($m[1]) > $highest) $highest = strlen($m[1]);
					}

					for ($a=0; $a<$count; $a++)
					{
						preg_match($SplitPattern, $pNodes[$a + $start], $m);
						$left  = $m[1];
						$mid   = $m[2];
						$right = $m[3];

						while (strlen($left) < $highest)
							$left .= " ";

						$pNodes[$a + $start] = preg_replace($SplitPattern, $left . $mid . $right, $pNodes[$a + $start]);
					}
				}

				$start = -1;
				$count = 0;
			}

			// if (preg_match("/}\$/", $pNodes[$x]))
				// $pNodes[$x] .= "\n";
		}

	}

	public function prettyPrint(array $stmts)
	{
        $this->preprocessNodes($stmts);
        $buffer = str_replace("\n" . $this->noIndentToken, "\n", $this->pStmts($stmts, false));

        $lines = preg_split("/\n/", $buffer);
        $this->findAssignmentBlocks($lines);
        return implode("\n", $lines);
    }

    /**
     * Pretty prints an array of nodes (statements) and indents them optionally.
     *
     * @param PHPParser\Node[] $nodes  Array of nodes
     * @param bool             $indent Whether to indent the printed nodes
     *
     * @return string Pretty printed statements
     */
    protected function pStmts(array $nodes, $indent = true) {
        $pNodes = array();
        foreach ($nodes as $node)
        {
            $pNodes[] = $this->pComments($node->getAttribute('comments', array()))
                      . $this->p($node)
                      . ($node instanceof Expr ? ';' : '');
        }

    	$newLines = array();
    	for ($x=0; $x<count($pNodes); $x++)
    	{
    		$pNodes[$x] = str_replace($this->extraLine, "\n", $pNodes[$x]);
    		$newLines[] = $pNodes[$x];

    		if (preg_match("/ function /", $pNodes[$x]))
        		$newLines[] = "";
    	}

        if ($indent)
        {
            return '    ' . preg_replace(
                '~\n(?!$|' . $this->noIndentToken . ')~',
                "\n" . '    ',
                implode("\n", $newLines)
            );

        } else {

            return implode("\n\n", $pNodes);

        }
    }

	protected function pInfixOp($type, PHPParser\Node $leftNode, $operatorString, PHPParser\Node $rightNode)
	{
        list($precedence, $associativity) = $this->precedenceMap[$type];

        if (preg_match("/Assign/", $type))
        {
			$result =  $this->wrapAssignLeft
				. $this->pPrec($leftNode, $precedence, $associativity, -1) . $this->wrapAssignSplit
				. $operatorString . $this->wrapAssignSplit
				. $this->pPrec($rightNode, $precedence, $associativity, 1) . $this->wrapAssignRight;

			if (! preg_match("/\n/", $result))
				return $result;
        }

        return $this->pPrec($leftNode, $precedence, $associativity, -1)
             . $operatorString
             . $this->pPrec($rightNode, $precedence, $associativity, 1);
    }

    public function pExpr_Array($node)
    {
        return 'array(' . $this->pCommaSeparated($node->items) . ')';
    }

    protected function pClassCommon(Stmt\Class_ $node, $afterClassToken)
    {
        return $this->pModifiers($node->type)
        . 'class' . $afterClassToken
        . (null !== $node->extends ? ' extends ' . $this->p($node->extends) : '')
        . (!empty($node->implements) ? ' implements ' . $this->pCommaSeparated($node->implements) : '')
        . "\n" . '{' . "\n" . $this->pStmts($node->stmts) . "\n" . '}';
    }

    public function pStmt_ClassMethod(Stmt\ClassMethod $node)
    {
        return $this->pModifiers($node->type)
             . 'function ' . ($node->byRef ? '&' : '') . $node->name
             . '(' . $this->pCommaSeparated($node->params) . ')'
             . (null !== $node->returnType ? ' : ' . $this->pType($node->returnType) : '')
             . (null !== $node->stmts
                ? "\n" . '{' . "\n" . $this->pStmts($node->stmts) . "\n" . '}'
                : ';');
    }

    public function pStmt_Function(Stmt\Function_ $node)
    {
        return 'function ' . ($node->byRef ? '&' : '') . $node->name
             . '(' . $this->pCommaSeparated($node->params) . ')'
             . (null !== $node->returnType ? ' : ' . $this->pType($node->returnType) : '')
             . "\n" . '{' . "\n" . $this->pStmts($node->stmts) . "\n" . '}';
    }

	public function pStmt_Declare(Stmt\Declare_ $node)
	{
        return 'declare (' . $this->pCommaSeparated($node->declares) . ")\n{"
             . "\n" . $this->pStmts($node->stmts) . "\n" . '}';
    }

    public function pStmt_If(Stmt\If_ $node)
    {
        return $this->extraLine . 'if (' . $this->p($node->cond) . ")\n{"
             . "\n" . $this->pStmts($node->stmts) . "\n" . '}'
             . $this->pImplode($node->elseifs)
             . (null !== $node->else ? $this->p($node->else) : $this->extraLine);
    }

    public function pStmt_Else(Stmt\Else_ $node)
    {
        return ' else {' . "\n" . $this->pStmts($node->stmts) . "\n" . '}';
    }

    public function pStmt_ElseIf(Stmt\ElseIf_ $node) {
        return ' elseif (' . $this->p($node->cond) . ")\n{"
             . "\n" . $this->pStmts($node->stmts) . "\n" . '}';
    }

    public function pStmt_Case(Stmt\Case_ $node)
    {
        return "\n" . (null !== $node->cond ? 'case ' . $this->p($node->cond) : 'default') . ':' . "\n"
             . $this->pStmts($node->stmts);
    }

    public function pStmt_For(Stmt\For_ $node) {
        return $this->extraLine . 'for ('
             . $this->pCommaSeparated($node->init) . ';' . (!empty($node->cond) ? ' ' : '')
             . $this->pCommaSeparated($node->cond) . ';' . (!empty($node->loop) ? ' ' : '')
             . $this->pCommaSeparated($node->loop)
             . ")\n{" . "\n" . $this->pStmts($node->stmts) . "\n" . '}' . $this->extraLine;
    }

    public function pStmt_Foreach(Stmt\Foreach_ $node) {
        return $this->extraLine . 'foreach (' . $this->p($node->expr) . ' as '
             . (null !== $node->keyVar ? $this->p($node->keyVar) . ' => ' : '')
             . ($node->byRef ? '&' : '') . $this->p($node->valueVar) . ")\n{"
             . "\n" . $this->pStmts($node->stmts) . "\n" . '}' . $this->extraLine;
    }

    public function pStmt_While(Stmt\While_ $node) {
        return $this->extraLine . 'while (' . $this->p($node->cond) . ")\n{"
             . "\n" . $this->pStmts($node->stmts) . "\n" . '}' . $this->extraLine;
    }

    public function pStmt_Do(Stmt\Do_ $node) {
        return $this->extraLine . 'do {' . "\n" . $this->pStmts($node->stmts) . "\n"
             . '} while (' . $this->p($node->cond) . ');' . $this->extraLine;
    }

    public function pStmt_Switch(Stmt\Switch_ $node) {
        return $this->extraLine . 'switch (' . $this->p($node->cond) . ")\n{"
             . "\n" . $this->pStmts($node->cases) . "\n" . '}' . $this->extraLine;
    }

    public function pStmt_InlineHTML(Stmt\InlineHTML_ $node)
    {
        return '?>' . "\n" . $this->pNoIndent("\n" . $node->value) . "\n" . '<?php ';
    }

};

function ReformatPHP($code)
{

	try
	{
		if (!preg_match("/\<\?php/", $code))
			$code = "<?php " . $code;

        $parser = new PhpParser\Parser(new PhpParser\Lexer\Emulative);
		$statements = $parser->parse($code);

		$prettyPrinter = new ProtovatePretty;
		$code = '<?php ' . "\n\n" . $prettyPrinter->prettyPrint($statements);

	} catch (PHPParser_Error $e)
	{
		print "Error: " . $e->getMessage() . "\n";
	}

	return $code;

}
