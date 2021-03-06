<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\Exception as FileSystemException;
use ricwein\FileSystem\Exceptions\RuntimeException as FileSystemRuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException as FileSystemUnexpectedValueException;
use ricwein\FileSystem\File;
use ricwein\Templater\Config;
use ricwein\Templater\Engine\CoreFunctions;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Exceptions\UnexpectedValueException;
use ricwein\Templater\Resolver\ExpressionResolver;
use ricwein\FileSystem\Storage;

class ResolverTest extends TestCase
{
    /**
     * @throws RuntimeException
     */
    public function testDirectResolving(): void
    {
        $resolver = new ExpressionResolver();

        $this->assertSame('test', $resolver->resolve('"test"'));
        $this->assertSame('test', $resolver->resolve("'test'"));
        $this->assertSame(true, $resolver->resolve("true"));
        $this->assertSame(false, $resolver->resolve("false"));
        $this->assertSame(42, $resolver->resolve("42"));
        $this->assertSame(42.0, $resolver->resolve("42.0"));
        $this->assertSame(3.14, $resolver->resolve("3.14"));
        $this->assertSame(null, $resolver->resolve("null"));
        $this->assertSame(['test'], $resolver->resolve("['test']"));
        $this->assertSame(['key' => 'value'], $resolver->resolve("{'key': 'value'}"));
        $this->assertSame([['key_test' => 'nice value'], 'yay'], $resolver->resolve("[{'key_test': 'nice value'}, 'yay']"));
        $this->assertSame(['key_test' => ['value1', 'value2']], $resolver->resolve("{'key_test': ['value1', 'value2']}"));
        $this->assertSame([['value1', 'value2'], ['value3', 'value4']], $resolver->resolve("[['value1', 'value2'], ['value3', 'value4']]"));
        $this->assertSame(['object1' => ['key1' => 'value1'], 'object2' => ['key2' => 'value2']], $resolver->resolve("{'object1': {'key1': 'value1'}, 'object2' : {'key2': 'value2'}}"));
        $this->assertSame('value1', $resolver->resolve("['value1', 'value2'].0"));
        $this->assertSame('value2', $resolver->resolve("['value1', 'value2'].1"));
        $this->assertSame('value1', $resolver->resolve("['value1', 'value2'][0]"));
        $this->assertSame('value2', $resolver->resolve("['value1', 'value2'][1]"));
    }

    /**
     * @throws AccessDeniedException
     * @throws FileSystemException
     * @throws FileSystemRuntimeException
     * @throws FileSystemUnexpectedValueException
     * @throws UnexpectedValueException
     * @throws RuntimeException
     */
    public function testBindingsResolving(): void
    {
        $bindings = [
            'value1' => 'yay',
            'value2' => true,
            'nested' => ['test' => 'success'],
            'array' => ['value1', 'value2'],
            'nestedArray' => [['val11', 'val12'], ['val21', 'val22']],
            'file' => new File(new Storage\Disk(__FILE__)),
        ];

        $functions = (new CoreFunctions(new Config()))->get();
        $resolver = new ExpressionResolver($bindings, $functions);

        $this->assertSame('yay', $resolver->resolve('value1'));
        $this->assertSame(true, $resolver->resolve('value2'));
        $this->assertSame('success', $resolver->resolve('nested.test'));
        $this->assertSame('value1', $resolver->resolve('array[0]'));
        $this->assertSame('value2', $resolver->resolve('array[1]'));

        $this->assertSame('val12', $resolver->resolve('nestedArray[0][1]'));
        $this->assertSame('val21', $resolver->resolve('nestedArray[1][0]'));
        $this->assertSame('val21', $resolver->resolve('nestedArray[1] | first'));

        $this->assertSame(__DIR__, $resolver->resolve('file.path().directory'));
        $this->assertSame('php', $resolver->resolve('file.path().extension'));
        $this->assertSame('text/x-php', $resolver->resolve('file.getType()'));
        $this->assertSame('text/x-php', $resolver->resolve('file.getType(false)'));
        $this->assertSame(hash_file('sha256', __FILE__), $resolver->resolve('file.getHash(constant("\\\\ricwein\\\\FileSystem\\\\Enum\\\\Hash::CONTENT"))'));
        $this->assertSame('text/x-php; charset=us-ascii', $resolver->resolve('file.getType(true)'));
    }

    /**
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function testArrayAccess(): void
    {
        $bindings = [
            'value1' => 'yay',
            'value2' => 1,
            'nested' => ['yay' => 'success'],
            'array' => ['value1', 'value2'],
            'key' => ['name' => 'yay'],
        ];
        $functions = (new CoreFunctions(new Config()))->get();
        $resolver = new ExpressionResolver($bindings, $functions);

        $this->assertSame('success', $resolver->resolve('nested[value1]'));
        $this->assertSame('value2', $resolver->resolve('array[value2]'));
        $this->assertSame('success', $resolver->resolve('nested[key.name]'));
        $this->assertSame('success', $resolver->resolve('nested[key.name]'));
    }

    /**
     * @throws RuntimeException
     */
    public function testConditionResolving(): void
    {
        $resolver = new ExpressionResolver();

        $this->assertSame('yay', $resolver->resolve("true ? 'yay'"));
        $this->assertSame('yay', $resolver->resolve("true ? 'yay' : 'oh noe'"));

        $this->assertSame(null, $resolver->resolve("false ? 'yay'"));
        $this->assertSame('oh noe', $resolver->resolve("false ? 'yay' : 'oh noe'"));

        $this->assertSame('yay', $resolver->resolve("true ? 'yay' : true ? 'oh no' : 'my bad'"));
        $this->assertSame('yay', $resolver->resolve("true ? 'yay' : false ? 'oh no' : 'my bad'"));
        $this->assertSame('oh no', $resolver->resolve("false ? 'yay' : (true ? 'oh no' : 'my bad')"));
        $this->assertSame('my bad', $resolver->resolve("false ? 'yay' : false ? 'oh no' : 'my bad'"));
        //$this->assertSame('oh no', $resolver->resolve("false ? 'yay' : true ? 'oh no' : 'my bad'"));
    }

    /**
     * @throws RuntimeException
     */
    public function testConditionalBindingResolving(): void
    {
        $bindings = [
            'data' => [true, false],
            'strings' => ['yay', 'no', 'another string'],
        ];

        $tests = [
            "'yay' in strings ? 'exists'" => 'exists',
        ];

        $resolver = new ExpressionResolver($bindings);
        foreach ($tests as $input => $expection) {
            $resolved = $resolver->resolve((string)$input);
            $this->assertSame($expection, $resolved);
        }
    }

    /**
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function testFunctionCalls(): void
    {
        $bindings = [
            'data' => [true, false],
            'nested' => ['test' => 'success'],
            'strings' => ['yay', 'no', 'another string'],
        ];

        $functions = (new CoreFunctions(new Config()))->get();
        $resolver = new ExpressionResolver($bindings, $functions);

        $this->assertSame('value1', $resolver->resolve("['value1', 'value2'] | first()"));
        $this->assertSame('value2', $resolver->resolve("['value1', 'value2'] | last"));
        $this->assertSame(2, $resolver->resolve("['value1', 'value2'] | count()"));
        $this->assertSame(0, $resolver->resolve("['value1', 'value2'] | keys() | first()"));

        $this->assertSame(1, $resolver->resolve("['value1', 'value2'] | keys | last()"));
        $this->assertSame(0, $resolver->resolve("['value1', 'value2'] | flip() | first()"));
        $this->assertSame('value1', $resolver->resolve("['value1', 'value2'] | flip() | keys() | first()"));
        $this->assertSame(0, $resolver->resolve("['value1', 'value2'] | flip().value1"));
        $this->assertSame(1, $resolver->resolve("['value1', 'value2'] | flip().value2"));

        $this->assertSame('success', $resolver->resolve("nested | first()"));

        $this->assertSame('value: 1', $resolver->resolve(" 'value: %d' | format(1)"));

        $this->assertSame('value1, value2', $resolver->resolve("['value1', 'value2'] | join(', ')"));
        $this->assertSame(['n', 'i', 'c', 'e'], $resolver->resolve("'nice' | split"));
        $this->assertSame(['n', 'ce'], $resolver->resolve("'nice' | split('i')"));
        $this->assertSame(['1', '2', '3'], $resolver->resolve("'1.2.3' | split('.')"));
        $this->assertSame(['1', '2.3'], $resolver->resolve("'1.2.3' | split('.', 2)"));
        $this->assertSame(['1.', '2.', '3'], $resolver->resolve("'1.2.3' | split(2)"));

        $this->assertSame('["value1","value2"]', $resolver->resolve("['value1', 'value2'] | json_encode()"));
        $this->assertSame("[\n    \"value1\",\n    \"value2\"\n]", $resolver->resolve("['value1', 'value2'] | json_encode(constant('JSON_PRETTY_PRINT'))"));

        $this->assertSame(["value1", "value2", "value3", "value4"], $resolver->resolve("['value1', 'value2'] | merge(['value3', 'value4'])"));

        $this->assertSame('Test succeeded', $resolver->resolve("'Test failed' | replace('failed', 'succeeded')"));
        $this->assertSame('Test succeeded', $resolver->resolve("'%this% %status%' | replace({'%this%': 'Test', '%status%': 'succeeded'})"));

        $filepath = __FILE__;
        $this->assertSame(file_get_contents($filepath), $resolver->resolve("file('{$filepath}').read()"));

        $this->assertSame('yay', $resolver->resolve("data | first ? 'yay'"));
        $this->assertSame(null, $resolver->resolve("data | last ? 'yay'"));
        $this->assertSame('success', $resolver->resolve("strings | first == strings.0 ? 'success'"));
        $this->assertSame('mismatches', $resolver->resolve("strings | first != strings | last ? 'mismatches'"));
        $this->assertSame('success', $resolver->resolve("strings | first == strings.0 ? 'success'"));
        $this->assertSame('mismatches', $resolver->resolve("strings | first != strings | last ? 'mismatches'"));

        // TODO: fix this:
//        $this->assertSame('also exists', $resolver->resolve("'another' in strings[2] ? 'also exists'"));
//        $this->assertSame('also exists', $resolver->resolve("'another' in (strings | last) ? 'also exists'"));
//        $this->assertSame('also exists', $resolver->resolve("'another' in last(strings) ? 'also exists'"));
        $this->assertSame('also exists', $resolver->resolve("'another' in (['another'] | last) ? 'also exists'"));
    }

    /**
     * @throws AccessDeniedException
     * @throws FileSystemException
     * @throws FileSystemRuntimeException
     * @throws FileSystemUnexpectedValueException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function testOperators(): void
    {
        $bindings = [
            'data' => [true, false],
            'nested' => ['test' => 'success'],
            'strings' => ['yay', 'no', 'another string'],
            'non_value' => null,
            'file' => new File(new Storage\Disk(__FILE__)),
            'index' => 2,
            'another_index' => 3,
        ];

        $functions = (new CoreFunctions(new Config()))->get();
        $resolver = new ExpressionResolver($bindings, $functions);

        $this->assertSame(false, $resolver->resolve('true && false'));
        $this->assertSame(true, $resolver->resolve('true || false'));

        $this->assertSame(true, $resolver->resolve('(true and false) or (true and true)'));
        $this->assertSame(false, $resolver->resolve('(true and false) and (true and true)'));
        $this->assertSame(true, $resolver->resolve('2 in range(1, 3) and (true)'));
        $this->assertSame(true, $resolver->resolve('another_index in range(index - 2, index + 2) and (non_value is empty)'));

        $this->assertSame("was nil", $resolver->resolve("non_value ?? 'was nil'"));
        $this->assertSame("success", $resolver->resolve("nested.test ?? 'was nil'"));
        $this->assertSame("was nil", $resolver->resolve("nested.unExisting ?? 'was nil'"));
        $this->assertSame("yay", $resolver->resolve("nested['unExisting'] ?? strings[0]"));
        $this->assertSame("no", $resolver->resolve("nested['unExisting'] ?? strings.1"));

        $this->assertSame(true, $resolver->resolve("'1.2.3' matches '/.*/'"));
        $this->assertSame(false, $resolver->resolve("'1.2.3'  matches '/^\\d+$/'"));
        $this->assertSame(true, $resolver->resolve("'10'  matches '/^\\d+$/'"));
        $this->assertSame(true, $resolver->resolve("10  matches '/^\\d+$/'"));

        $this->assertSame(true, $resolver->resolve("data is array"));
        $this->assertSame(false, $resolver->resolve("unknownvar is defined"));
        $this->assertSame(false, $resolver->resolve("unknownvar.test is defined"));
        $this->assertSame(true, $resolver->resolve("unknownvar is undefined"));
        $this->assertSame(true, $resolver->resolve("data.test is undefined"));
        $this->assertSame(true, $resolver->resolve("unknownvar.test is undefined"));
        $this->assertSame(false, $resolver->resolve("unknownvar is not undefined"));
        $this->assertSame(false, $resolver->resolve("unknownvar is defined"));
        $this->assertSame(true, $resolver->resolve("unknownvar is not defined"));

        $this->assertSame('-', $resolver->resolve("(unknownvar.test ?? 0) > 0 ? unknownvar.test : '-'"));
        $this->assertSame('-', $resolver->resolve("(unknownvar ?? 0) > 0 ? unknownvar.test : '-'"));
        $this->assertSame('-', $resolver->resolve("(data.unknownkey ?? 0) > 0 ? unknownvar.test : '-'"));

        $this->assertSame(true, $resolver->resolve("file instanceof '\\\\ricwein\\\\FileSystem\\\\File'"));

        $this->assertSame(false, $resolver->resolve("data is undefined"));
        $this->assertSame(true, $resolver->resolve("data.1 is not undefined"));
        $this->assertSame(true, $resolver->resolve("data is defined"));
        $this->assertSame(false, $resolver->resolve("data is not defined"));

        $this->assertSame(true, $resolver->resolve("10.1 is numeric"));
        $this->assertSame(true, $resolver->resolve("10.1 is float"));
        $this->assertSame(false, $resolver->resolve("10.1 is int"));
        $this->assertSame(true, $resolver->resolve("10.1 is not int"));
        $this->assertSame(true, $resolver->resolve("non_value is null"));
        $this->assertSame(true, $resolver->resolve("strings | first() is string"));

        $this->assertSame(true, $resolver->resolve("2 in (1...10) "));
        $this->assertSame(true, $resolver->resolve("11 not in (1...10) "));

        $this->assertSame("success no", $resolver->resolve("nested['test'] ~ ' ' ~ strings.1"));
    }

    /**
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function testBracketingResolution(): void
    {
        $bindings = [
            'nested' => ['test' => 'success'],
            'strings' => ['yay', 'test'],
        ];

        $functions = (new CoreFunctions(new Config()))->get();
        $resolver = new ExpressionResolver($bindings, $functions);

        $this->assertSame("was nil", $resolver->resolve("(nested.unExisting ?? 'was nil') ?? 'doh'"));
        $this->assertSame("success", $resolver->resolve("nested.(strings.1)"));
    }

    /**
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function testLateResolution(): void
    {
        $bindings = [];
        $functions = (new CoreFunctions(new Config()))->get();
        $resolver = new ExpressionResolver($bindings, $functions);

        $this->assertSame(null, $resolver->resolve("(unknownvar.test ?? 0) > 0 ? '-' ~ unknownvar.test"));
    }
}
