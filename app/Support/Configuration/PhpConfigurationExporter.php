<?php

namespace App\Support\Configuration;

final readonly class PhpConfigurationExporter
{
    /**
     * @param  array<string, mixed>  $configuration
     */
    public function export(array $configuration): string
    {
        return "<?php\n\nreturn ".$this->exportArray($configuration).";\n";
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function exportArray(array $values, int $depth = 0): string
    {
        if ($values === []) {
            return '[]';
        }

        $indent = str_repeat('    ', $depth);
        $entryIndent = str_repeat('    ', $depth + 1);
        $entries = [];

        foreach ($values as $key => $value) {
            $prefix = array_is_list($values) ? '' : var_export($key, true).' => ';
            $exported = is_array($value)
                ? $this->exportArray($value, $depth + 1)
                : var_export($value, true);

            $entries[] = $entryIndent.$prefix.$exported.',';
        }

        return "[\n".implode("\n", $entries)."\n{$indent}]";
    }
}
