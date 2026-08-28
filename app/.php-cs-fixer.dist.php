<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests'])
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        '@PHP83Migration' => true,
        'declare_strict_types' => true,
        'global_namespace_import' => ['import_classes' => true, 'import_functions' => false],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'phpdoc_align' => false,
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder);
