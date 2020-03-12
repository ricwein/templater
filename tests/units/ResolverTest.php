<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use ricwein\FileSystem\File;
use ricwein\Templater\Config;
use ricwein\Templater\Engine\CoreFunctions;
use ricwein\Templater\Resolver\Resolver;
use ricwein\FileSystem\Storage;

class ResolverTest extends TestCase
{
    public function testDirectResolving()
    {
        $resolver = new Resolver();

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

    public function testUnmatchingBindings()
    {
        $resolver = new Resolver();

        $this->expectException(\ricwein\Templater\Exceptions\RuntimeException::class);
        $resolver->resolve("unknownvar");
    }

    public function testNestedUnmatchingBindings()
    {
        $resolver = new Resolver();

        $this->expectException(\ricwein\Templater\Exceptions\RuntimeException::class);
        $resolver->resolve("unknown.var");
    }

    public function testBindingsResolving()
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
        $resolver = new Resolver($bindings, $functions);

        $this->assertSame('yay', $resolver->resolve('value1'));
        $this->assertSame(true, $resolver->resolve('value2'));
        $this->assertSame('success', $resolver->resolve('nested.test'));
        $this->assertSame('value1', $resolver->resolve('array[0]'));
        $this->assertSame('value2', $resolver->resolve('array[1]'));

        $this->assertSame('val12', $resolver->resolve('nestedArray[0][1]'));
        $this->assertSame('val21', $resolver->resolve('nestedArray[1][0]'));
        $this->assertSame('val21', $resolver->resolve('nestedArray[1] | first'));

        $this->assertSame(dirname(__FILE__), $resolver->resolve('file.path().directory'));
        $this->assertSame('php', $resolver->resolve('file.path().extension'));
        $this->assertSame('text/x-php', $resolver->resolve('file.getType()'));
        $this->assertSame('text/x-php', $resolver->resolve('file.getType(false)'));
        $this->assertSame('text/x-php; charset=us-ascii', $resolver->resolve('file.getType(true)'));
    }

    public function testConditionResolving()
    {
        $this->markTestSkipped();

        $tests = [
            "true ? 'yay'" => 'yay',
            "true ? 'yay' : 'oh noe'" => 'yay',

            "false ? 'yay'" => '',
            "false ? 'yay' : 'oh noe'" => 'oh noe',

            "true ? 'yay' : true ? 'oh no' : 'my bad'" => 'yay',
            "true ? 'yay' : false ? 'oh no' : 'my bad'" => 'yay',
            "false ? 'yay' : true ? 'oh no' : 'my bad'" => 'oh no',
            "false ? 'yay' : false ? 'oh no' : 'my bad'" => 'my bad',
        ];

        $resolver = new Resolver();
        foreach ($tests as $input => $expection) {
            $resolved = $resolver->resolve((string)$input);
            $this->assertSame($expection, $resolved);
        }
    }

    public function testConditionalBindingResolving()
    {
        $this->markTestSkipped();

        $bindings = [
            'data' => [true, false],
            'strings' => ['yay', 'no', 'another string'],
        ];

        $tests = [
            "'yay' in strings ? 'exists'" => 'exists',
        ];

        $resolver = new Resolver($bindings);
        foreach ($tests as $input => $expection) {
            $resolved = $resolver->resolve((string)$input);
            $this->assertSame($expection, $resolved);
        }
    }

    public function testFunctionCalls()
    {
        $bindings = [
            'data' => [true, false],
            'nested' => ['test' => 'success'],
            'strings' => ['yay', 'no', 'another string'],
        ];

        $functions = (new CoreFunctions(new Config()))->get();
        $resolver = new Resolver($bindings, $functions);

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
        return;

        $this->assertSame('yay', $resolver->resolve("data | first ? 'yay'"));
        $this->assertSame('', $resolver->resolve("data | last ? 'yay'"));
        $this->assertSame('success', $resolver->resolve("strings | first == strings.0 ? 'success'"));
        $this->assertSame('mismatches', $resolver->resolve("strings | first != strings | last ? 'mismatches'"));
        $this->assertSame('also exists', $resolver->resolve("'another' in strings | last ? 'also exists'"));
        $this->assertSame('success', $resolver->resolve("strings | first == strings.0 ? 'success'"));
        $this->assertSame('mismatches', $resolver->resolve("strings | first != strings | last ? 'mismatches'"));
    }
}
