<?php declare(strict_types=1);

/**
 * @license Apache 2.0
 */

namespace OpenApi;

use OpenApi\Annotations as OA;
use OpenApi\Loggers\DefaultLogger;
use Psr\Log\LoggerInterface;

/**
 * The context in which the annotation is parsed.
 *
 * Contexts are nested to reflect the code/parsing hierarchy. They include useful metadata
 * which the processors can use to augment the annotations.
 *
 * @property string|null                  $comment     The PHP DocComment
 * @property string|null                  $filename
 * @property int|null                     $line
 * @property int|null                     $character
 * @property string|null                  $namespace
 * @property array|null                   $uses
 * @property string|null                  $class
 * @property string|null                  $interface
 * @property string|null                  $trait
 * @property string|null                  $enum
 * @property array|string|null            $extends     Interfaces may extend a list of interfaces
 * @property array|null                   $implements
 * @property string|null                  $method
 * @property string|null                  $property
 * @property string|\ReflectionType|null  $type
 * @property bool|null                    $static      Indicate a static method
 * @property bool|null                    $nullable    Indicate a nullable value
 * @property bool|null                    $generated   Indicate the context was generated by a processor or
 *                                                     the serializer
 * @property OA\AbstractAnnotation|null   $nested
 * @property OA\AbstractAnnotation[]|null $annotations
 * @property LoggerInterface|null         $logger      Guaranteed to be set when using the `Generator`
 * @property array|null                   $scanned     Details of file scanner when using ReflectionAnalyser
 * @property string|null                  $version     The OpenAPI version in use
 */
#[\AllowDynamicProperties]
class Context
{
    /**
     * Prototypical inheritance for properties.
     */
    private ?Context $parent;

    public function clone()
    {
        return new Context(get_object_vars($this), $this->parent);
    }

    public function __construct(array $properties = [], ?Context $parent = null)
    {
        foreach ($properties as $property => $value) {
            $this->{$property} = $value;
        }
        $this->parent = $parent;

        $this->logger = $this->logger ?: new DefaultLogger();

        if (!$this->version) {
            $this->root()->version = OA\OpenApi::DEFAULT_VERSION;
        }
    }

    /**
     * Check if a property is set directly on this context and not its parent context.
     *
     * Example: $c->is('method') or $c->is('class')
     */
    public function is(string $property): bool
    {
        return property_exists($this, $property);
    }

    /**
     * Check if a property is NOT set directly on this context and its parent context.
     *
     * Example: $c->not('method') or $c->not('class')
     */
    public function not(string $property): bool
    {
        return $this->is($property) === false;
    }

    /**
     * Return the context containing the specified property.
     */
    public function with(string $property): ?Context
    {
        if ($this->is($property)) {
            return $this;
        }
        if ($this->parent instanceof Context) {
            return $this->parent->with($property);
        }

        return null;
    }

    /**
     * Get the root context.
     */
    public function root(): Context
    {
        if ($this->parent instanceof Context) {
            return $this->parent->root();
        }

        return $this;
    }

    /**
     * Check if one of the given version numbers matches the current OpenAPI version.
     *
     * @param string|array $versions One or more version numbers
     */
    public function isVersion($versions): bool
    {
        $versions = (array) $versions;
        $currentVersion = $this->version ?: OA\OpenApi::DEFAULT_VERSION;

        return in_array($currentVersion, $versions);
    }

    /**
     * Export location for debugging.
     *
     * @return string Example: "file1.php on line 12"
     */
    public function getDebugLocation(): string
    {
        $location = '';
        if ($this->class && ($this->method || $this->property)) {
            $location .= $this->fullyQualifiedName($this->class);
            if ($this->method) {
                $location .= ($this->static ? '::' : '->') . $this->method . '()';
            } elseif ($this->property) {
                $location .= ($this->static ? '::$' : '->') . $this->property;
            }
        }
        if ($this->filename) {
            if ($location !== '') {
                $location .= ' in ';
            }
            $location .= $this->filename;
        }
        if ($this->line) {
            if ($location !== '') {
                $location .= ' on';
            }
            $location .= ' line ' . $this->line;
            if ($this->character) {
                $location .= ':' . $this->character;
            }
        }

        return $location;
    }

    /**
     * Traverse the context tree to get the property value.
     */
    public function __get(string $property)
    {
        if ($this->parent instanceof Context) {
            return $this->parent->{$property};
        }

        return null;
    }

    public function __toString()
    {
        return $this->getDebugLocation();
    }

    public function __debugInfo()
    {
        return ['-' => $this->getDebugLocation()];
    }

    /**
     * Create a Context based on `debug_backtrace`.
     *
     * @deprecated
     */
    public static function detect(int $index = 0): Context
    {
        // trigger_deprecation('zircote/swagger-php', '4.9', 'Context detecting is deprecated');

        $context = new Context();
        $backtrace = debug_backtrace();
        $position = $backtrace[$index];
        if (isset($position['file'])) {
            $context->filename = $position['file'];
        }
        if (isset($position['line'])) {
            $context->line = $position['line'];
        }
        $caller = $backtrace[$index + 1] ?? null;
        if (isset($caller['function'])) {
            $context->method = $caller['function'];
            if (isset($caller['type']) && $caller['type'] === '::') {
                $context->static = true;
            }
        }
        if (isset($caller['class'])) {
            $fqn = explode('\\', $caller['class']);
            $context->class = array_pop($fqn);
            if ($fqn !== []) {
                $context->namespace = implode('\\', $fqn);
            }
        }

        // @todo extract namespaces and use statements
        return $context;
    }

    /**
     * Resolve the fully qualified name.
     */
    public function fullyQualifiedName(?string $source): string
    {
        if ($source === null) {
            return '';
        }

        $namespace = $this->namespace ? str_replace('\\\\', '\\', '\\' . $this->namespace . '\\') : '\\';

        $thisSource = $this->class ?? $this->interface ?? $this->trait;
        if ($thisSource && strcasecmp($source, $thisSource) === 0) {
            return $namespace . $thisSource;
        }
        $pos = strpos($source, '\\');
        if ($pos !== false) {
            if ($pos === 0) {
                // Fully qualified name (\Foo\Bar)
                return $source;
            }
            // Qualified name (Foo\Bar)
            if ($this->uses) {
                foreach ($this->uses as $alias => $aliasedNamespace) {
                    $alias .= '\\';
                    if (strcasecmp(substr($source, 0, strlen($alias)), $alias) === 0) {
                        // Aliased namespace (use \Long\Namespace as Foo)
                        return '\\' . $aliasedNamespace . substr($source, strlen($alias) - 1);
                    }
                }
            }
        } elseif ($this->uses) {
            // Unqualified name (Foo)
            foreach ($this->uses as $alias => $aliasedNamespace) {
                if (strcasecmp($alias, $source) === 0) {
                    return '\\' . $aliasedNamespace;
                }
            }
        }

        return $namespace . $source;
    }
}
