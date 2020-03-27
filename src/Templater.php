<?php
/**
 * @author Richard Weinhold
 */

namespace ricwein\Templater;

use Exception;
use Phpfastcache\Core\Pool\ExtendedCacheItemPoolInterface;
use ricwein\FileSystem\Directory;
use ricwein\FileSystem\Exceptions\AccessDeniedException;
use ricwein\FileSystem\Exceptions\ConstraintsException;
use ricwein\FileSystem\Exceptions\Exception as FileSystemException;
use ricwein\FileSystem\Exceptions\FileNotFoundException;
use ricwein\FileSystem\Exceptions\RuntimeException as FileSystemRuntimeException;
use ricwein\FileSystem\Exceptions\UnexpectedValueException;
use ricwein\FileSystem\File;
use ricwein\FileSystem\Helper\Constraint;
use ricwein\FileSystem\Storage;
use ricwein\Templater\Engine\BaseFunction;
use ricwein\Templater\Engine\Context;
use ricwein\Templater\Engine\CoreFunctions;
use ricwein\Templater\Engine\Statement;
use ricwein\Templater\Exceptions\RenderingException;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Exceptions\TemplatingException;
use ricwein\Templater\Processors;
use ricwein\Tokenizer\InputSymbols\Block;
use ricwein\Tokenizer\Result\BlockToken;
use ricwein\Tokenizer\Result\Token;
use ricwein\Tokenizer\Result\TokenStream;
use ricwein\Tokenizer\Tokenizer;

/**
 * simple Template parser with Twig-like syntax
 */
class Templater
{
    protected ?Directory $assetsDir;
    protected Directory $templateDir;

    protected Config $config;
    protected ?ExtendedCacheItemPoolInterface $cache = null;

    private array $functions = [];

    /**
     * @var Processors\Processor[]
     */
    private array $processors = [];

    /**
     * @param Config $config
     * @param ExtendedCacheItemPoolInterface|null $cache
     * @throws AccessDeniedException
     * @throws FileNotFoundException
     * @throws FileSystemRuntimeException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function __construct(Config $config, ?ExtendedCacheItemPoolInterface $cache = null)
    {
        $this->config = $config;
        $this->cache = $cache;

        if (null === $templatePath = $config->templateDir) {
            throw new RuntimeException("Initialization of the Templater class requires Config::\$templateDir to be set, but is not.", 500);
        }

        $templateDir = new Directory(new Storage\Disk($templatePath), Constraint::IN_OPENBASEDIR);
        if (!$templateDir->isDir() && !$templateDir->isReadable()) {
            throw new FileNotFoundException("Unable to open the given template dir ({$templateDir->path()->raw}). Check if the directory exists and is readable.", 404);
        }

        $this->templateDir = $templateDir;

        if (null !== $assetPath = $config->assetDir) {
            $assetDir = new Directory(new Storage\Disk($assetPath), Constraint::IN_OPENBASEDIR);
            if (!$assetDir->isDir() && !$assetDir->isReadable()) {
                throw new FileNotFoundException("Unable to open the given asset dir ({$assetDir->path()->raw}). Check if the directory exists and is readable.", 404);
            }
            $this->assetsDir = $assetDir;
        }

        // load core functions
        foreach ((new CoreFunctions($this->config))->get() as $function) {
            $this->addFunction($function);
        }

        // setup core processors
        $this->processors = [
            new Processors\BlockProcessor($this),
            new Processors\IncludeProcessor($this),
            new Processors\IfProcessor($this),
            new Processors\ForLoopProcessor($this),
        ];
    }

    public function addFunction(BaseFunction $function): self
    {
        $this->functions[$function->getName()] = $function;
        return $this;
    }

    public function addProcessor(Processors\Processor $processor): self
    {
        $this->processors[] = $processor;
        return $this;
    }

    /**
     * @param string $templateName
     * @param array|object $bindings
     * @param callable|null $filter
     * @return string
     * @throws FileNotFoundException
     * @throws FileSystemRuntimeException
     * @throws TemplatingException
     */
    public function render(string $templateName, array $bindings = [], callable $filter = null): string
    {
        try {
            $templateFile = $this->getRelativeTemplateFile(null, $templateName);
        } catch (Exception $exception) {
            throw new FileNotFoundException("Error opening template: {$templateName}.", 404, $exception);
        }

        if ($templateFile === null) {
            throw new FileNotFoundException("No template file found for: {$templateName}.", 404);
        }

        try {

            $content = $this->renderFile(new Context(
                $templateFile,
                array_replace_recursive($bindings, ['template' => ['file' => $templateFile], 'config' => $this->config->asArray()]),
                $this->functions,
                [],
            ));

        } catch (RenderingException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            throw new TemplatingException(
                "Error rendering Template: {$templateFile->path()->filepath}",
                $exception->getCode() > 0 ? $exception->getCode() : 500,
                $exception
            );
        }

        if ($filter !== null) {
            $content = call_user_func_array($filter, [$content]);
        }

        return $content;
    }

    /**
     * @param Context $context
     * @return string
     * @throws RenderingException
     */
    public function renderFile(Context $context): string
    {
        $line = 0;
        try {
            $tokenizer = new Tokenizer([], [
                new Block('{#', '#}', false, true), // comment
                new Block('{{', '}}', false, true), // variable or function call
                new Block('{%', '%}', false, true), // statement
            ], [
                'maxDepth' => 0
            ]);

            $templateContent = $context->template()->read();
            $tokenStream = $tokenizer->tokenize($templateContent);

            $blocks = $this->resolveStream($tokenStream, $context, $line);

        } catch (RenderingException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            throw new RenderingException("Error rendering template.", 500, $exception, $context->template(), $line);
        }

        return implode('', $blocks);
    }

    /**
     * @param TokenStream $stream
     * @param Context $context
     * @param int|null &$line
     * @return array
     * @throws RenderingException
     */
    public function resolveStream(TokenStream $stream, Context $context, ?int &$line = null): array
    {
        $blocks = [];

        while ($token = $stream->next()) {

            if ($line !== null) {
                // expose current line number for better exception messages
                $line = $token->line();
            }

            if ($token instanceof Token) {
                $blocks[] = $token->content();
            } elseif ($token instanceof BlockToken) {
                $blocks = array_merge($blocks, $this->resolveToken($token, $context, $stream));
            }
        }

        return $blocks;
    }

    /**
     * @param BlockToken $token
     * @param Context $context
     * @param TokenStream $stream
     * @return array|null
     * @throws RenderingException
     */
    public function resolveToken(BlockToken $token, Context $context, TokenStream $stream): array
    {
        $blocks = [];

        // handle block-types
        $key = trim($token->content());

        try {

            if ($token->block()->is('{{', '}}')) {

                $value = $context->resolver()->resolve($key);
                $blocks[] = $this->asPrintable($value, $key);

            } elseif ($token->block()->is('{#', '#}') && !$this->config->stripComments) {

                $blocks[] = sprintf("%s<!-- %s -->", PHP_EOL, $key);

            } elseif ($token->block()->is('{%', '%}')) {

                $statement = new Statement($token, $context);

                $matched = false;

                foreach ($this->processors as $processor) {
                    if ($processor->isQualified($statement)) {
                        $matched = true;
                        $blocks[] = $processor->process($statement, $stream);
                        break;
                    }
                }

                if (!$matched) {
                    throw new RenderingException(sprintf("Found unsupported statement with keyword: %s", trim($token->content())), 500, null, $context->template(), $token->line());
                }
            }

        } catch (RenderingException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            throw new RenderingException("Error rendering template.", 500, $exception, $context->template(), $token->line());
        }

        return $blocks;
    }

    /**
     * @param mixed $value
     * @param string $path
     * @return string
     * @throws RuntimeException
     */
    private function asPrintable($value, string $path): string
    {
        // check for return type
        if ($value === null) {
            return '';
        } elseif (is_string($value)) {
            return trim($value);
        } elseif (is_bool($value)) {
            return $value ? 'true' : 'false';
        } elseif (is_scalar($value)) {
            return $value;
        } elseif (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        if ($this->config->debug) {
            throw new RuntimeException(sprintf(
                "Unable to print non-scalar value for '%s' (type: %s | is: %s)",
                $path,
                is_object($value) ? sprintf('class (%s)', get_class($value)) : gettype($value),
                str_replace([PHP_EOL, ' '], '', print_r($value, true)),
            ), 500);
        }

        return '';

    }

    /**
     * @param File $file
     * @return string
     * @throws FileSystemRuntimeException
     */
    public static function getCacheKeyFor(File $file): string
    {
        return sprintf(
            "view.%s_%s",
            str_replace(
                ['{', '}', '(', ')', '/', '\\', '@', ':'],
                ['|', '|', '|', '|', '.', '.', '-', '_'],
                $file->path()->filepath
            ),
            hash('sha256', $file->getTime())
        );
    }

    /**
     * @param Directory|null $relativeDir
     * @param string $filename
     * @return File|null
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileSystemException
     * @throws FileSystemRuntimeException
     */
    public function getRelativeTemplateFile(?Directory $relativeDir, string $filename): ?File
    {
        return static::getTemplateFile($this->templateDir, $relativeDir, $filename, $this->config->fileExtension);
    }

    /**
     * @param Directory $baseDir
     * @param Directory $relativeDir
     * @param string $filename
     * @param string $extension
     * @return File|null
     * @throws AccessDeniedException
     * @throws ConstraintsException
     * @throws FileSystemException
     * @throws FileSystemRuntimeException
     */
    public static function getTemplateFile(Directory $baseDir, ?Directory $relativeDir, string $filename, string $extension): ?File
    {
        /** @var Directory[] $dirs */
        $dirs = array_filter([$baseDir, $relativeDir], function (?Directory $dir): bool {
            return $dir !== null;
        });

        foreach ($dirs as $dir) {
            foreach ([$filename, "{$filename}{$extension}"] as $filenameVariation) {
                $file = $dir->file($filenameVariation);
                if ($file->isFile()) {
                    return $file;
                }
            }
        }

        return null;
    }
}
