<?php

declare(strict_types=1);

/*
 * This file is part of DOCtor-RST.
 *
 * (c) Oskar Stark <oskarstark@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Command;

use App\Handler\RulesHandler;
use App\Rst\RstParser;
use App\Rule\Configurable;
use App\Rule\Rule;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Yaml;

class CheckCommand extends Command
{
    protected static $defaultName = 'check';

    /** @var SymfonyStyle */
    private $io;

    /** @var bool */
    private $violations = false;

    /** @var RulesHandler */
    private $rulesHandler;

    /** @var Rule[] */
    private $rules = [];

    /** @var bool */
    private $short = false;

    /** @var string|null */
    private $dir;

    /** @var mixed */
    private $config;

    public function __construct(RulesHandler $rulesHandler, ?string $name = null)
    {
        $this->rulesHandler = $rulesHandler;

        parent::__construct($name);
    }

    protected function configure()
    {
        $this
            ->setDescription('Check *.rst files')
            ->addArgument('dir', InputArgument::OPTIONAL, 'Directory', '.')
            ->addOption('rule', 'r', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Which rule should be applied?')
            ->addOption('group', 'g', InputOption::VALUE_REQUIRED, 'Which groups should be used?')
            ->addOption('short', null, InputOption::VALUE_NONE, 'Do not output valid files.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->io = new SymfonyStyle($input, $output);

        if (!$this->dir = realpath($input->getArgument('dir'))) {
            $this->io->error(sprintf('Could not find directory: %s', $input->getArgument('dir')));

            return 1;
        }

        $this->io->text(sprintf('Check *.rst(.inc) files in: <info>%s</info>', $this->dir));

        if (!is_file($configFile = $this->dir.'/.doctor-rst.yaml')) {
            $this->io->error(sprintf('Could not find config file: %s', $configFile));
        }

        $this->io->text(sprintf('Used config file:           <info>%s</info>', $configFile));
        $this->io->newLine();

        $this->config = Yaml::parseFile($configFile);

        foreach ($this->config['rules'] as $rule => $options) {
            /** @var Rule[] $rules */
            $rules = $this->rulesHandler->getRulesByName($rule);

            foreach ($rules as $rule) {
                if ($rule instanceof Configurable && null !== $options) {
                    $rule->setOptions($options);
                }

                $this->rules[] = $rule;
            }
        }

        if (!empty($input->getOption('rule') && !empty($input->getOption('group')))) {
            $this->io->error('You can only provide "rule" or "group"!');

            return 1;
        }

        if (\is_array($input->getOption('rule')) && !empty($input->getOption('rule'))) {
            foreach ($input->getOption('rule') as $rule) {
                $this->rules[] = $this->rulesHandler->getRule($rule);
            }
        }

        if (!empty($input->getOption('group'))) {
            $this->rules = $this->rulesHandler->getRulesByGroup($input->getOption('group'));
        }

        if (empty($this->rules)) {
            $this->io->warning('No rules selected!');

            return 1;
        }

        if ($input->getOption('short')) {
            $this->short = true;
        }

        $finder = new Finder();
        $finder->files()->name(['*.rst', '*.rst.inc'])->in($this->dir);

        $violatedFiles = 0;
        foreach ($finder as $file) {
            $violatedFiles = $violatedFiles + $this->checkFile($file);
        }

        if ($this->violations) {
            $this->io->warning(sprintf(
                'Found "%s" invalid %s!',
                $violatedFiles,
                1 === $violatedFiles ? 'file' : 'files'
            ));
        } else {
            $this->io->success('All files are valid!');
        }

        return $this->violations ? 1 : 0;
    }

    private function checkFile(SplFileInfo $file): int
    {
        $lines = new \ArrayIterator(file($file->getRealPath()));

        $violations = [];
        foreach ($lines as $no => $line) {
            if (RstParser::isBlankLine($line)) {
                continue;
            }

            /** @var Rule $rule */
            foreach ($this->rules as $rule) {
                if (Rule::TYPE_FILE === $rule::getType() && $no > 0) {
                    continue;
                }
                $violation = $rule->check(clone $lines, $no);

                if (null !== $violation) {
                    $violations[] = [
                        $rule::getName(),
                        $violation,
                        $no + 1,
                        trim($line),
                    ];
                }
            }
        }

        $violations = $this->filterWhitelistedViolations($violations);

        if (!$this->short || !empty($violations)) {
            $this->io->writeln(ltrim(str_replace($this->dir, '', $file->getPathname()), '/'));
        }

        if (!empty($violations)) {
            $this->violations = true;

            $this->io->table(['Rule', 'Violation', 'Line', 'Extracted line from file'], $violations);

            return 1;
        }

        return 0;
    }

    private function filterWhitelistedViolations(array $violations): array
    {
        if (isset($this->config['whitelist'])) {
            foreach ($violations as $key => $violation) {
                if (isset($this->config['whitelist']['regex'])) {
                    foreach ($this->config['whitelist']['regex'] as $pattern) {
                        if (preg_match($pattern, $violation[3])) {
                            unset($violations[$key]);

                            break;
                        }
                    }
                }

                if (isset($this->config['whitelist']['lines'])) {
                    foreach ($this->config['whitelist']['lines'] as $line) {
                        if ($line === $violation[3]) {
                            unset($violations[$key]);

                            break;
                        }
                    }
                }
            }
        }

        return $violations;
    }
}
