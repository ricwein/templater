<?php


namespace ricwein\Templater\Resolver\Symbol;


abstract class Symbol
{
    public const TYPE_NULL = 'NULL';

    public const TYPE_STRING = 'string';
    public const TYPE_FLOAT = 'float';
    public const TYPE_INT = 'int';
    public const TYPE_BOOL = 'bool';

    public const TYPE_OBJECT = 'object';
    public const TYPE_ARRAY = 'array';

    public const ANY_SCALAR = [self::TYPE_STRING, self::TYPE_FLOAT, self::TYPE_INT, self::TYPE_BOOL];
    public const ANY_DEFINABLE = [self::TYPE_FLOAT, self::TYPE_INT, self::TYPE_BOOL, self::TYPE_ARRAY];
    public const ANY_ACCESSIBLE = [self::TYPE_OBJECT, self::TYPE_ARRAY];
    public const ANY_KEYPATH_PART = [self::TYPE_STRING, self::TYPE_INT, self::TYPE_FLOAT];
    public const ANY_NUMERIC = [self::TYPE_INT, self::TYPE_FLOAT];

    abstract public function value(bool $trimmed = false);

    abstract public function interruptKeyPath(): bool;

    abstract public function type(): string;

    public function is($type): bool
    {
        if (is_array($type)) {
            return in_array($this->type(), $type, true);
        }
        return $this->type() === $type;
    }
}
