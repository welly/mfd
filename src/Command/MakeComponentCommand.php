<?php

declare(strict_types=1);

namespace Manifesto\Mfd\Command;

use Manifesto\Mfd\Component\ComponentGenerator;
use Manifesto\Mfd\Config\Naming;
use Manifesto\Mfd\Exception\UserError;
use Manifesto\Mfd\Project\ProjectRoot;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'make:component', description: 'Create a Single Directory Component in the project theme')]
final class MakeComponentCommand extends Command
{
    /** @param string|null $workingDir where to start looking for the project; null means the current directory */
    public function __construct(private readonly ?string $workingDir = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                "Component machine name (lowercase letters, digits, '-' or '_')",
            )
            ->addOption(
                'theme',
                null,
                InputOption::VALUE_REQUIRED,
                'Theme machine name (default: the theme recorded in .mfd.json)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        try {
            $root = ProjectRoot::find($this->workingDir ?? (string) getcwd());
            $themeOption = $input->getOption('theme');
            if (is_string($themeOption) && $themeOption !== '') {
                if (!Naming::isValidThemeName($themeOption)) {
                    throw new UserError(sprintf(
                        "--theme '%s' must be lowercase letters, digits and '_', start with a letter, "
                            . 'be at most 50 characters, and not be a core machine name',
                        $themeOption,
                    ));
                }
                $theme = $themeOption;
            } else {
                $theme = $root->theme();
            }
            $themeDir = $root->path('web/themes/custom/' . $theme);
            if (!is_dir($themeDir)) {
                throw new UserError(sprintf(
                    'theme directory web/themes/custom/%s not found in %s',
                    $theme,
                    $root->dir,
                ));
            }
            $files = (new ComponentGenerator())->generate($themeDir, $name);
        } catch (UserError $e) {
            $this->error($output, $e->getMessage());

            return self::INVALID;
        } catch (\RuntimeException $e) {
            $this->error($output, $e->getMessage());

            return self::FAILURE;
        }

        foreach ($files as $file) {
            $output->writeln(sprintf('created web/themes/custom/%s/%s', $theme, $file));
        }
        $output->writeln('Give every prop realistic examples: Storybook builds the story from them.');
        $output->writeln('Then place the component on a real page and add a site story in qa/stories.ts.');

        return self::SUCCESS;
    }

    private function error(OutputInterface $output, string $message): void
    {
        $stream = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $stream->writeln('<error>error:</error> ' . $message);
    }
}
