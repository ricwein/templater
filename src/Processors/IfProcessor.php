<?php

namespace ricwein\Templater\Processors;

use ricwein\Templater\Engine\Statement;
use ricwein\Templater\Exceptions\RenderingException;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Tokenizer\Result\BlockToken;
use ricwein\Tokenizer\Result\Token;
use ricwein\Tokenizer\Result\TokenStream;

class IfProcessor extends Processor
{
    protected function startKeyword(): string
    {
        return 'if';
    }

    protected function endKeyword(): ?string
    {
        return 'endif';
    }

    protected function forkKeywords(): ?array
    {
        return ['elseif', 'else'];
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function process(Statement $statement, TokenStream $stream): string
    {
        $branches = [];
        $current = [
            'type' => 'if',
            'condition' => $statement->remainingTokens(),
            'blocks' => []
        ];
        $isClosed = false;

        // search endif statement and save branch-tokens for later processing
        while ($token = $stream->next()) {

            if ($token instanceof Token) {

                $current['blocks'][] = $token;

            } elseif ($token instanceof BlockToken) {

                $blockStatement = new Statement($token, $statement->context);
                switch (true) {

                    case !$token->block()->is('{%', '%}'):
                    default:
                        $current['blocks'][] = $token;
                        break;

                    // branch elseif-fork
                    case $blockStatement->beginsWith(['elseif']):
                        $branches[] = $current;
                        $current = [
                            'type' => 'elseif',
                            'condition' => $blockStatement->remainingTokens(),
                            'blocks' => [],
                        ];
                        break;

                    // branch else-fork
                    case $blockStatement->beginsWith(['else']):
                        $branches[] = $current;
                        $current = [
                            'type' => 'else',
                            'blocks' => [],
                        ];
                        break;

                    // endif
                    case $this->isQualifiedEnd($blockStatement):
                        $branches[] = $current;
                        $isClosed = true;
                        break 2;
                }
            }
        }

        if (!$isClosed) {
            throw new RuntimeException("Unexpected end of template. Missing '{$this->endKeyword()}' tag.", 500);
        }

        // process actual if-statements
        return $this->evaluateBranches($statement, $branches);
    }

    /**
     * @param Statement $statement
     * @param array $branches
     * @return string
     * @throws RuntimeException
     * @throws RenderingException
     */
    private function evaluateBranches(Statement $statement, array $branches): string
    {
        foreach ($branches as $branch) {
            switch ($branch['type']) {

                case 'if':
                case 'elseif':
                    $conditionString = implode(' ', array_map(fn(Token $conditionToken): string => $conditionToken->token(), $branch['condition']));

                    if ($statement->context->resolver()->resolve($conditionString)) {
                        $localStream = new TokenStream($branch['blocks']);
                        $resolved = $this->templater->resolveStream($localStream, $statement->context);
                        return implode('', $resolved);
                    }
                    break;

                case 'else':
                    $localStream = new TokenStream($branch['blocks']);
                    $resolved = $this->templater->resolveStream($localStream, $statement->context);
                    return implode('', $resolved);
            }
        }

        return '';
    }
}
