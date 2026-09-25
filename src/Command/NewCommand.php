<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Command;

use Manifesto\Mfd\Config\ProjectConfig;
use Manifesto\Mfd\Exception\StepFailed;
use Manifesto\Mfd\Exception\UserError;
use Manifesto\Mfd\Harness\HarnessPaths;
use Manifesto\Mfd\Mfd;
use Manifesto\Mfd\Preflight;
use Manifesto\Mfd\Shell\ProcessShell;
use Manifesto\Mfd\Shell\Shell;
use Manifesto\Mfd\State;
use Manifesto\Mfd\Step\Context;
use Manifesto\Mfd\Step\Step;
use Manifesto\Mfd\Step\StepRunner;
use Manifesto\Mfd\Steps;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'new',
    description: 'Create a Drupal 11 project with SDC, Storybook, PHPUnit and the mf-harness review tooling',
)]
final class NewCommand extends Command
{
    /** @param list<Step>|null $steps null means Steps::all() */
    public function __construct(
        private readonly ?Shell $shell = null,
        private readonly ?array $steps = null,
        private readonly ?HarnessPaths $harness = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'name',
                InputArgument::OPTIONAL,
                'The project\'s name, e.g. "Acme Corp". Every other name is derived from it',
            )
            ->addOption(
                'project-name',
                null,
                InputOption::VALUE_REQUIRED,
                'Optional. DDEV/project machine name (default: derived from the name, e.g. acme-corp)',
            )
            ->addOption(
                'theme',
                null,
                InputOption::VALUE_REQUIRED,
                "Optional. Theme machine name (default: the project name with '-' as '_')",
            )
            ->addOption(
                'theme-label',
                null,
                InputOption::VALUE_REQUIRED,
                'Optional. Theme human name (default: project name in title case)',
            )
            ->addOption(
                'site-name',
                null,
                InputOption::VALUE_REQUIRED,
                'Optional. Drupal site name (default: the theme label)',
            )
            ->addOption(
                'dir',
                null,
                InputOption::VALUE_REQUIRED,
                'Optional. Target directory (default: ./<project name>)',
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Optional. Print the steps and change nothing')
            ->addOption(
                'yes',
                null,
                InputOption::VALUE_NONE,
                'Optional. Never prompt (mfd new does not prompt; kept for harness parity)',
            )
            ->setHelp(<<<'HELP'
                Exit codes: 0 success, 1 a step failed (rerun the same command to resume),
                2 bad input or failed pre-flight (nothing written).

                Only the name is required, e.g. mfd new "Acme Corp" gives DDEV project acme-corp,
                theme acme_corp, and label and site name "Acme Corp".
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $shell = $this->shell ?? new ProcessShell($output);
        $harness = $this->harness ?? HarnessPaths::fromEnvironment();
        try {
            $config = $this->configFrom($input);
            $targetDir = self::absolute($config->targetDir);
            (new Preflight($shell, $harness))->check($config, $targetDir);
        } catch (UserError $e) {
            $this->error($output, $e->getMessage());

            return self::INVALID;
        }

        $runner = new StepRunner($this->steps ?? Steps::all());
        $context = new Context($config, $shell, $output, $targetDir, Mfd::root(), $harness);

        if ($input->getOption('dry-run') === true) {
            $output->writeln('Dry run: nothing will be changed.');
            $this->printConfig($output, $config, $targetDir);
            $runner->plan($context);

            return self::SUCCESS;
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0777, true);
        }
        $state = new State($targetDir);
        $state->init($config);
        try {
            $runner->run($context, $state);
        } catch (StepFailed $e) {
            $this->error($output, $e->getMessage());
            $output->writeln('Fix the problem above, then rerun the same command to resume.');

            return self::FAILURE;
        }

        $this->printSummary($output, $context);

        return self::SUCCESS;
    }

    private function printConfig(OutputInterface $output, ProjectConfig $config, string $targetDir): void
    {
        $output->writeln(sprintf('Project:    %s (%s)', $config->project, $config->siteUrl()));
        $output->writeln(sprintf('Theme:      %s (%s)', $config->theme, $config->themeLabel));
        $output->writeln(sprintf('Site name:  %s', $config->siteName));
        $output->writeln(sprintf('Directory:  %s', $targetDir));
    }

    private function printSummary(OutputInterface $output, Context $context): void
    {
        $config = $context->config;
        $output->writeln('');
        $output->writeln('Project ready in ' . $context->projectDir);
        $output->writeln(sprintf('  Site:     %s   (task be:login for an admin link)', $config->siteUrl()));
        $output->writeln('  Theme:    web/themes/custom/' . $config->theme);
        $output->writeln("  Tasks:    run 'task' in the project to list them");
        foreach ($context->notices->all() as $notice) {
            $output->writeln('<comment>warning:</comment> ' . $notice);
        }
        $output->writeln('');
        $output->writeln('Next:');
        $output->writeln('  1. Review the files, then commit them yourself (git add -A && git commit).');
        $output->writeln('  2. Open Claude Code in the project and run /setup-project to finish the harness');
        $output->writeln('     setup (MCP, Figma, enrolment). It skips what is already done.');
    }

    /** An absolute path for $dir, relative to the current directory when not absolute. */
    private static function absolute(string $dir): string
    {
        $path = str_starts_with($dir, '/') ? $dir : getcwd() . '/' . $dir;
        $path = (string) preg_replace('#/(\./)+#', '/', $path);

        return rtrim($path, '/');
    }

    private function configFrom(InputInterface $input): ProjectConfig
    {
        return ProjectConfig::fromInput(
            self::stringOrNull($input->getArgument('name')),
            self::stringOrNull($input->getOption('project-name')),
            self::stringOrNull($input->getOption('theme')),
            self::stringOrNull($input->getOption('theme-label')),
            self::stringOrNull($input->getOption('site-name')),
            self::stringOrNull($input->getOption('dir')),
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function error(OutputInterface $output, string $message): void
    {
        $stream = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $stream->writeln('<error>error:</error> ' . $message);
    }
}
