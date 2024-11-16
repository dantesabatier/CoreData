<?php

$finder = new PhpCsFixer\Finder()->in(__DIR__);

/** @psalm-suppress InvalidArgument */
return new PhpCsFixer\Config()
    ->setRiskyAllowed(true)
    ->setRules([
        "@PSR12" => true,
        "strict_param" => true,
        "array_syntax" => ["syntax" => "short"],
        "blank_line_between_import_groups" => false,
        "closure_fn_spacing" => "none",
    ])->setFinder($finder);
