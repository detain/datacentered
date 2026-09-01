<?php

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__,
    ])
    ->exclude([
      '.git',
      'vendor',
      // Reference-only third-party samples (see CLAUDE.md "Experiments"):
      // amphp/Aerys/Artax and Swoole examples. Not our code, not loaded at
      // runtime, and 11 of them are pre-PHP8 syntax that does not even lint
      // under 8.3 — so the fixer reported them as errors on every run and
      // reformatting the rest would be pure diff noise.
      'experiments',
    ])
/*    ->notPath([
        'dump.php',
        'src/exception_file.php',
    ]) */
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR2' => true,
        // Was '@PHP74Migration'. composer.json requires php >=8.2 and the hub
        // runs 8.3, so the 7.4 target had stopped matching the codebase — it
        // left 8.0/8.1/8.2 modernisations (constructor promotion-adjacent
        // syntax, ::class on objects, non-capturing catch, readonly-safe
        // formatting, str_contains/str_starts_with over substr/strpos idioms)
        // un-applied and un-checked.
        '@PHP82Migration' => true,
//        '@PhpCsFixer' => true,
//        '@PSR12' => true,
//        '@PER-CS' => true,
//        'array_syntax' => ['syntax' => 'short'],
        'method_argument_space' => false,
        'heredoc_indentation' => false,
        'trailing_comma_in_multiline' => false,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/.php-cs-fixer.cache')
    ->setUsingCache(true)
    ->setHideProgress(false)
    //->setRiskyAllowed(false)
;
