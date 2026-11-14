<?php

declare(strict_types=1);

// Follow Symfony AI's rules, preserving our strict typing and portable stream modes.
// https://github.com/symfony/ai/blob/main/.php-cs-fixer.dist.php
$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude(['vendor', 'var', 'node_modules', '.git', '.phpunit.cache'])
    ->ignoreDotFiles(false)
    ->name('*')
    // Maker templates contain PHP that emits PHP. Check their rendered output instead.
    ->notName('*.tpl.php')
    ->notPath('/(^|\/)config\/reference\.php$/')
    ->filter(static function (SplFileInfo $file): bool {
        if (str_ends_with($file->getFilename(), '.php')) {
            return true;
        }

        // Include existing and future extensionless PHP commands in every bin/ directory.
        if (!preg_match('{(?:^|/)bin/[^/]+$}', str_replace('\\', '/', $file->getPathname()))) {
            return false;
        }

        return str_contains(file_get_contents($file->getPathname(), length: 128), '<?php');
    })
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setCacheFile(__DIR__.'/.php-cs-fixer.cache')
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'protected_to_private' => false,
        'declare_strict_types' => false,
        // Removing a binary flag changes byte handling on Windows.
        'fopen_flags' => false,
        'php_unit_test_case_static_method_calls' => ['call_type' => 'this'],
        'ordered_class_elements' => [
            'order' => [
                'use_trait', 'case', 'constant_public', 'constant_protected', 'constant_private',
                'property_public', 'property_protected', 'property_private', 'construct',
                'destruct', 'magic', 'phpunit', 'method_public', 'method_protected', 'method_private',
            ],
        ],
    ])
    ->setRuleCustomisationPolicy(new class implements PhpCsFixer\Config\RuleCustomisationPolicyInterface {
        public function getPolicyVersionForCache(): string
        {
            return hash_file('sha256', __FILE__);
        }

        public function getRuleCustomisers(): array
        {
            return [
                // Match Symfony AI: do not add void return types to test methods.
                'void_return' => static fn (SplFileInfo $file): bool => !preg_match('{/(?:tests|Tests)/}', str_replace('\\', '/', $file->getPathname())),
                // The standalone guest defines the public Tools API; it is not PSR-4 autoloaded.
                'psr_autoloading' => static fn (SplFileInfo $file): bool => !str_ends_with(str_replace('\\', '/', $file->getPathname()), '/runner/bootstrap.php'),
            ];
        }
    })
    ->setFinder($finder)
;
