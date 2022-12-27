<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PDG\Command;

use PDG\Services\Reference\PhpDocHelper;
use PDG\Services\Reference\ReflectionHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Path;

#[AsCommand(name: 'pdg:generate-reference')]
class GenerateReferenceCommand extends Command
{
    private readonly array $config;
    private string $root;
    private \ReflectionClass $reflectionClass;

    public function __construct(
        private readonly PhpDocHelper $phpDocHelper,
        private readonly ReflectionHelper $reflectionHelper,
        string $name = null
    ) {
        parent::__construct($name);
        $this->config = (require 'src/config.php')();
        $this->root = Path::makeAbsolute($this->config['reference']['src'], getcwd());
    }

    public function openFile(mixed $fileName): void
    {
        $handle = fopen($fileName, 'r');
        if (!$handle) {
            fwrite(\STDERR, sprintf('Error opening %s. %s', $fileName, \PHP_EOL));
            exit(1);
        }
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'filename',
                InputArgument::REQUIRED
            )
            ->addArgument(
                'output',
                InputArgument::OPTIONAL,
                'leave empty for automatic generation, use `stdout` for printing'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);
        $fileName = $input->getArgument('filename');

        $this->openFile($fileName);

        $file = Path::makeAbsolute($fileName, getcwd());
        $relative = Path::makeRelative($file, $this->root);

        $output->writeln(sprintf('Generating reference for %s', $relative));
        $namespace = 'ApiPlatform\\'.str_replace(['/', '.php'], ['\\', ''], $relative);
        $content = '';

        $this->reflectionClass = new \ReflectionClass($namespace);
        $outputFile = match ($outputArg = $input->getArgument('output')) {
            null => $this->getOutPutFile($file),
            'stdout' => \STDOUT,
            default => $outputArg
        };

        $content = $this->writePageTitle($content);
        $content = $this->writeClassName($content);
        $content = $this->reflectionHelper->handleParent($this->reflectionClass, $content);
        $content = $this->reflectionHelper->handleImplementations($this->reflectionClass, $content);
        $content = $this->phpDocHelper->handleClassDoc($this->reflectionClass, $content);
        $content = $this->reflectionHelper->handleClassConstants($this->reflectionClass, $content);
        $content = $this->reflectionHelper->handleProperties($this->reflectionClass, $content);
        $content = $this->reflectionHelper->handleMethods($this->reflectionClass, $content);

        if (\STDOUT !== $outputFile) {
            if (!fwrite(fopen($outputFile, 'w'), $content)) {
                $style->error('Error opening or writing '.$outputFile);

                return Command::FAILURE;
            }
            $style->success('Reference successfully generated for '.$relative);

            return Command::SUCCESS;
        }
        fwrite($outputFile, $content);
        $style->success('Reference successfully printed on stdout for '.$relative);

        return Command::SUCCESS;
    }

    private function getOutPutFile(string $file): string
    {
        $relativeToSrc = Path::makeRelative($file, $this->root);
        $array = explode('/', $relativeToSrc);
        array_pop($array);
        $x = implode('/', $array);

        $referencePath = $this->config['sidebar']['directories']['Reference'][0];

        return $referencePath.'/'.$x.'/'.strtolower($this->reflectionClass->getShortName()).'.mdx';
    }

    private function writePageTitle(string $content): string
    {
        $content .= 'import Head from "next/head";'.\PHP_EOL.\PHP_EOL;
        $content .= '<Head><title>'.$this->reflectionClass->getShortName().'</title></Head> '.\PHP_EOL.\PHP_EOL;

        return $content;
    }

    private function writeClassName(string $content): string
    {
        return $content."# \\{$this->reflectionClass->getName()}".\PHP_EOL;
    }
}
