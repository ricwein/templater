<?php


namespace ricwein\Templater\Engine;


use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Resolver\Symbol;

class CoreOperators
{
    /**
     * @return callable[]
     */
    public function get(): array
    {
        return [
            '===' => [$this, 'equal'],
            '==' => [$this, 'equal'],
            '!==' => [$this, 'notEqual'],
            '!=' => [$this, 'notEqual'],
            '<=>' => [$this, 'compare'],
            '>=' => [$this, 'greaterOrEqual'],
            '<=' => [$this, 'lesserOrEqual'],
            '>' => [$this, 'greater'],
            '<' => [$this, 'lesser'],

            '??' => [$this, 'nullCoalescing'],

            ' b-or ' => [$this, 'binaryOr'],
            ' b-and ' => [$this, 'binaryAnd'],

            ' in ' => [$this, 'in'],
            ' not in ' => [$this, 'notIn'],
            ' starts with ' => [$this, 'startsWith'],
            ' ends with ' => [$this, 'endsWith'],

            ' and ' => [$this, 'and'],
            '&&' => [$this, 'and'],
            ' or ' => [$this, 'or'],
            '||' => [$this, 'or'],
            ' xor ' => [$this, 'xor'],

            '~' => [$this, 'concat'],

            '+' => [$this, 'plus'],
            '-' => [$this, 'minus'],
            '*' => [$this, 'multiply'],
            '/' => [$this, 'divide'],

        ];
    }

    /**
     * @param string $operator
     * @param $lhs
     * @param $rhs
     * @return RuntimeException
     */
    private static function datatypeException(string $operator, $lhs, $rhs): RuntimeException
    {
        return new RuntimeException(
            sprintf(
                "Invalid datatypes for '%s' operator. Parameters are lhs: %s, lhs: %s",
                $operator,
                is_object($lhs) ? sprintf('class(%s)', get_class($lhs)) : gettype($lhs),
                is_object($rhs) ? sprintf('class(%s)', get_class($rhs)) : gettype($rhs),
            ),
            500
        );
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function equal(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new Symbol($lhs->value() === $rhs->value(), false, Symbol::TYPE_BOOL);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function notEqual(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new Symbol($lhs->value() !== $rhs->value(), false, Symbol::TYPE_BOOL);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function compare(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new Symbol($lhs->value() <=> $rhs->value(), false, Symbol::TYPE_INT);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function greaterOrEqual(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new Symbol($lhs->value() >= $rhs->value(), false, Symbol::TYPE_BOOL);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function lesserOrEqual(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new Symbol($lhs->value() <= $rhs->value(), false, Symbol::TYPE_BOOL);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function greater(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new Symbol($lhs->value() > $rhs->value(), false, Symbol::TYPE_BOOL);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function lesser(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new Symbol($lhs->value() < $rhs->value(), false, Symbol::TYPE_BOOL);
    }

    /**
     * @inheritDoc
     */
    public function nullCoalescing(Symbol $lhs, Symbol $rhs): Symbol
    {
        return ($lhs->value() !== null) ? (clone $lhs) : (clone $rhs);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function binaryOr(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new Symbol($lhs->value() | $rhs->value(), false, Symbol::TYPE_INT);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function binaryAnd(Symbol $lhs, Symbol $rhs): Symbol
    {
        return new Symbol($lhs->value() & $rhs->value(), false, Symbol::TYPE_INT);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function in(Symbol $lhs, Symbol $rhs): Symbol
    {
        switch (true) {

            case $rhs->is(Symbol::TYPE_ARRAY):
                return new Symbol(in_array($lhs->value(), $rhs->value(), true), false, Symbol::TYPE_BOOL);

            case $lhs->is(Symbol::TYPE_STRING) && $rhs->is(Symbol::TYPE_STRING):
                return new Symbol(strpos($lhs->value(), $rhs->value()) !== false, false, Symbol::TYPE_BOOL);

            default:
                throw static::datatypeException(__METHOD__, $lhs->value(), $rhs->value());

        }
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function notIn(Symbol $lhs, Symbol $rhs): Symbol
    {
        switch (true) {

            case $rhs->is(Symbol::TYPE_ARRAY):
                return new Symbol(!in_array($lhs->value(), $rhs->value(), true), false, Symbol::TYPE_BOOL);

            case $lhs->is(Symbol::TYPE_STRING) && $rhs->is(Symbol::TYPE_STRING):
                return new Symbol(strpos($lhs->value(), $rhs->value()) === false, false, Symbol::TYPE_BOOL);

            default:
                throw static::datatypeException(__METHOD__, $lhs->value(), $rhs->value());
        }
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function startsWith(Symbol $lhs, Symbol $rhs): Symbol
    {
        switch (true) {

            case $rhs->is(Symbol::TYPE_ARRAY):
                $rhsArray = (array)$rhs->value();
                return new Symbol($lhs->value() === $rhsArray[array_key_first($rhsArray)], false, Symbol::TYPE_BOOL);

            case $lhs->is(Symbol::TYPE_STRING) && $rhs->is(Symbol::TYPE_STRING):
                return new Symbol(strpos($lhs->value(), $rhs->value()) === 0, false, Symbol::TYPE_BOOL);

            default:
                throw static::datatypeException(__METHOD__, $lhs->value(), $rhs->value());
        }
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function endsWith(Symbol $lhs, Symbol $rhs): Symbol
    {
        switch (true) {

            case $rhs->is(Symbol::TYPE_ARRAY):
                $rhsArray = (array)$rhs->value();
                return new Symbol($lhs->value() === $rhsArray[array_key_last($rhsArray)], false, Symbol::TYPE_BOOL);

            case $lhs->is(Symbol::TYPE_STRING) && $rhs->is(Symbol::TYPE_STRING):
                return new Symbol(strpos($lhs->value(), $rhs->value()) === (strlen($lhs->value()) - strlen($rhs->value())), false, Symbol::TYPE_BOOL);

            default:
                throw static::datatypeException(__METHOD__, $lhs->value(), $rhs->value());
        }
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function and(Symbol $lhs, Symbol $rhs): Symbol
    {
        if (!$lhs->is(Symbol::TYPE_BOOL) || !$rhs->is(Symbol::TYPE_BOOL)) {
            throw static::datatypeException(__METHOD__, $lhs->value(), $rhs->value());
        }

        return new Symbol($lhs->value() && $rhs->value(), false, Symbol::TYPE_BOOL);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function or(Symbol $lhs, Symbol $rhs): Symbol
    {
        if (!$lhs->is(Symbol::TYPE_BOOL) || !$rhs->is(Symbol::TYPE_BOOL)) {
            throw static::datatypeException(__METHOD__, $lhs->value(), $rhs->value());
        }

        return new Symbol($lhs->value() || $rhs->value(), false, Symbol::TYPE_BOOL);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function xor(Symbol $lhs, Symbol $rhs): Symbol
    {
        if (!$lhs->is(Symbol::TYPE_BOOL) || !$rhs->is(Symbol::TYPE_BOOL)) {
            throw static::datatypeException(__METHOD__, $lhs->value(), $rhs->value());
        }

        return new Symbol($lhs->value() xor $rhs->value(), false, Symbol::TYPE_BOOL);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function concat(Symbol $lhs, Symbol $rhs): Symbol
    {
        $value = $lhs->value() . $rhs->value();
        return new Symbol($value, $lhs->interruptKeyPath() || $rhs->interruptKeyPath(), Symbol::TYPE_STRING);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function plus(Symbol $lhs, Symbol $rhs): Symbol
    {
        if (!$lhs->is(Symbol::ANY_NUMERIC) || !$rhs->is(Symbol::ANY_NUMERIC)) {
            throw static::datatypeException(__METHOD__, $lhs->value(), $rhs->value());
        }

        return new Symbol($lhs->value() + $rhs->value(), false);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function minus(Symbol $lhs, Symbol $rhs): Symbol
    {
        if (!$lhs->is(Symbol::ANY_NUMERIC) || !$rhs->is(Symbol::ANY_NUMERIC)) {
            throw static::datatypeException(__METHOD__, $lhs->value(), $rhs->value());
        }

        return new Symbol($lhs->value() - $rhs->value(), false);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function multiply(Symbol $lhs, Symbol $rhs): Symbol
    {
        if (!$lhs->is(Symbol::ANY_NUMERIC) || !$rhs->is(Symbol::ANY_NUMERIC)) {
            throw static::datatypeException(__METHOD__, $lhs->value(), $rhs->value());
        }

        return new Symbol($lhs->value() * $rhs->value(), false);
    }

    /**
     * @inheritDoc
     * @throws RuntimeException
     */
    public function divide(Symbol $lhs, Symbol $rhs): Symbol
    {
        if (!$lhs->is(Symbol::ANY_NUMERIC) || !$rhs->is(Symbol::ANY_NUMERIC)) {
            throw static::datatypeException(__METHOD__, $lhs->value(), $rhs->value());
        }

        return new Symbol($lhs->value() / $rhs->value(), false);
    }
}