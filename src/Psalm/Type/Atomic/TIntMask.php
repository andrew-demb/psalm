<?php

declare(strict_types=1);

namespace Psalm\Type\Atomic;

use Override;

use function substr;

/**
 * Represents the type that is the result of a bitmask combination of its parameters.
 * `int-mask<1, 2, 4>` corresponds to `0|1|2|3|4|5|6|7`
 *
 * @psalm-immutable
 */
final class TIntMask extends TInt
{
    /** @param non-empty-array<TLiteralInt|TClassConstant> $values */
    public function __construct(public array $values, bool $from_docblock = false)
    {
        parent::__construct($from_docblock);
    }

    #[Override]
    public function getKey(bool $include_extra = true): string
    {
        $s = '';

        foreach ($this->values as $value) {
            $s .= $value->getKey() . ', ';
        }

        return 'int-mask<' . substr($s, 0, -2) . '>';
    }

    #[Override]
    public function getId(bool $exact = true, bool $nested = false): string
    {
        $s = '';

        foreach ($this->values as $value) {
            $s .= $value->getId($exact) . ', ';
        }

        return 'int-mask<' . substr($s, 0, -2) . '>';
    }

    /**
     * @param  array<lowercase-string, string> $aliased_classes
     */
    #[Override]
    public function toNamespacedString(
        ?string $namespace,
        array $aliased_classes,
        ?string $this_class,
        bool $use_phpdoc_format,
    ): string {
        if ($use_phpdoc_format) {
            return 'int';
        }

        $s = '';

        foreach ($this->values as $value) {
            $s .= $value->toNamespacedString($namespace, $aliased_classes, $this_class, false) . ', ';
        }

        return 'int-mask<' . substr($s, 0, -2) . '>';
    }

    #[Override]
    public function canBeFullyExpressedInPhp(int $analysis_php_version_id): bool
    {
        return false;
    }
}
