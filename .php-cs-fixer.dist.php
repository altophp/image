<?php

declare(strict_types=1);

$licence = <<<'EOF'
This file is part of the ALTO library.

© 2026-present Simon André

For full copyright and license information, please see
the LICENSE file distributed with this source code.
EOF;

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__.'/src', __DIR__.'/tests'])
    ->append([__DIR__.'/bin/image'])
    ->exclude('Fixtures');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        'declare_strict_types' => true,
        'header_comment' => ['header' => $licence],
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const']],
        'phpdoc_line_span' => ['const' => 'multi', 'property' => 'multi', 'method' => 'multi'],
    ])
    ->setFinder($finder);
