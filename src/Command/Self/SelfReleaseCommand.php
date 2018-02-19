<?php
namespace Platformsh\Cli\Command\Self;

use GuzzleHttp\Client;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SelfReleaseCommand extends CommandBase
{
    protected $hiddenInList = true;

    protected function configure()
    {
        $this
            ->setName('self:release')
            ->setDescription('Build and release a new version')
            ->addOption('phar', null, InputOption::VALUE_REQUIRED, 'The path to a newly built Phar file')
            ->addOption('repo', null, InputOption::VALUE_REQUIRED, 'The GitHub repository', $this->config()->has('application.github_repo') ? $this->config()->get('application.github_repo') : null);
    }

    public function isEnabled()
    {
        return $this->config()->has('application.github_repo')
            && (!extension_loaded('Phar') || !\Phar::running(false));
    }

    /**
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');

        /** @var \Platformsh\Cli\Service\Git $git */
        $git = $this->getService('git');
        $git->setDefaultRepositoryDir(CLI_ROOT);

        if ($git->getCurrentBranch(CLI_ROOT, true) !== 'master') {
            $this->stdErr->writeln('You must be on the master branch to make a release.');

            return 1;
        }

        if ($git->execute(['diff', 'master...development'], CLI_ROOT, true) && $questionHelper->confirm('Merge changes from development?')) {
            $git->execute(['merge', 'development'], CLI_ROOT, true);
        }

        $gitStatus = $git->execute(['status', '--porcelain'], CLI_ROOT, true);
        if (is_string($gitStatus) && !empty($gitStatus)) {
            foreach (explode("\n", $gitStatus) as $statusLine) {
                if (strpos($statusLine, ' config.yaml') === false) {
                    $this->stdErr->writeln('There are uncommitted changes in Git. Cannot proceed.');

                    return 1;
                }
            }
        }

        if (getenv('GITHUB_TOKEN')) {
            $gitHubToken = getenv('GITHUB_TOKEN');
        } else {
            $this->stdErr->writeln('The GITHUB_TOKEN environment variable must be set');

            return 1;
        }

        $newVersion = $this->config()->get('application.version');
        $this->stdErr->writeln('The version number defined in the config.yaml file is: <comment>' . $newVersion . '</comment>');

        if (substr($newVersion, 0, 1) === 'v') {
            $this->stdErr->writeln('The version number should not be prefixed by `v`.');

            return 1;
        }
        if (!$questionHelper->confirm('Is <comment>' . $newVersion . '</comment> the correct new version number?')) {
            $this->stdErr->writeln('Update the version number in config.yaml and re-run this command.');

            return 1;
        }

        $tagName = 'v' . $newVersion;
        $http = new Client();
        $repo = $input->getOption('repo') ?: $this->config()->get('application.github_repo');
        $repoUrl = implode('/', array_map('rawurlencode', explode('/', $repo)));
        $repoApiUrl = 'https://api.github.com/repos/' . $repoUrl;
        $repoGitUrl = 'git@github.com:' . $repo . '.git';

        $existsResponse = $http->get($repoApiUrl . '/releases/tags/' . $tagName, [
            'headers' => [
                'Authorization' => 'token ' . $gitHubToken,
                'Accept' => 'application/vnd.github.v3+json',
                'Content-Type' => 'application/json',
            ],
            'exceptions' => false,
            'debug' => $output->isDebug(),
        ]);
        if ($existsResponse->getStatusCode() !== 404) {
            if ($existsResponse->getStatusCode() >= 300) {
                $this->stdErr->writeln('Failed to check for an existing release on GitHub.');

                return 1;
            }
            $this->stdErr->writeln('A release tagged ' . $tagName . ' already exists on GitHub.');

            return 1;
        }

        $pharFilename = $input->getOption('phar');
        if ($pharFilename && !file_exists($pharFilename)) {
            $this->stdErr->writeln('File not found: <error>' . $pharFilename . '</error>');

            return 1;
        }
        if (!$pharFilename) {
            $pharFilename = CLI_ROOT . '/' . $this->config()->get('application.executable') . '.phar';
            $result = $this->runOtherCommand('self:build', [
                '--output' => $pharFilename,
            ]);
            if ($result !== 0) {
                $this->stdErr->writeln('The build failed');

                return $result;
            }
        } else {
            $versionInPhar = $shell->execute(['php', $pharFilename, '--version'], null, true);
            if (strpos($versionInPhar, $newVersion) === false) {
                $this->stdErr->writeln('The file ' . $pharFilename . ' reports a different version: "' . $versionInPhar . '"');

                return 1;
            }
        }

        $gitStatus = $git->execute(['status', '--porcelain'], CLI_ROOT, true);
        if (is_string($gitStatus) && !empty($gitStatus)) {
            $this->stdErr->writeln('Committing changes to Git');

            $result = $shell->executeSimple('git commit --patch config.yaml dist/manifest.json --message ' . escapeshellarg('Release v' . $newVersion) . ' --edit', CLI_ROOT);
            if ($result !== 0) {
                return $result;
            }
        }

        $latest = $http->get($repoApiUrl . '/releases/latest', [
            'headers' => [
                'Authorization' => 'token ' . $gitHubToken,
                'Accept' => 'application/vnd.github.v3+json',
                'Content-Type' => 'application/json',
            ],
            'debug' => $output->isDebug(),
        ])->json();
        $lastVersion = $latest['tag_name'];

        $changelog = $git->execute([
            'log',
            '--pretty=format:* %s',
            '--no-merges',
            '--invert-grep',
            '--grep=(Release v|\[skip changelog\])',
            '--perl-regexp',
            '--regexp-ignore-case',
            $lastVersion . '...' . $tagName
        ], CLI_ROOT, true);
        if (empty($changelog)) {
            $this->stdErr->writeln('Failed to find changelog for ' . $lastVersion . '...' . $tagName);

            return 1;
        }

        $this->stdErr->writeln('Creating tag <info>' . $tagName . '</info>');
        $git->execute(['tag', '--force', $tagName], CLI_ROOT, true);

        if (!$questionHelper->confirm('Push changes and tag to <comment>master</comment> branch on ' . $repoGitUrl . '?')) {
            return 1;
        }
        $shell->execute(['git', 'push', $repoGitUrl, 'HEAD:master'], CLI_ROOT, true);
        $shell->execute(['git', 'push', '--force', $repoGitUrl, $tagName], CLI_ROOT, true);

        $lastReleasePublicUrl = 'https://github.com/' . $repoUrl . '/releases/' . $lastVersion;
        $pharPublicFilename = $this->config()->get('application.executable') . '.phar';
        $releaseDescription = sprintf('Changes since [%s](%s):', $lastVersion, $lastReleasePublicUrl)
            . "\n\n" . $changelog
            . "\n\n" . sprintf('SHA-256 checksum for `%s`:', $pharPublicFilename)
            . "\n" . sprintf('`%s`', hash_file('sha256', $pharFilename));

        $this->stdErr->writeln('');
        $this->stdErr->writeln('Creating new release ' . $tagName . ' on GitHub');
        $this->stdErr->writeln('Release description:');
        $this->stdErr->writeln(preg_replace('/^/m', '  ', $releaseDescription));
        $this->stdErr->writeln('');

        if (!$questionHelper->confirm('Is this OK?')) {
            return 1;
        }

        $http = new Client();
        $response = $http->post($repoApiUrl . '/releases', [
            'headers' => [
                'Authorization' => 'token ' . $gitHubToken,
                'Accept' => 'application/vnd.github.v3+json',
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'tag_name' => $tagName,
                'name' => $tagName,
                'body' => $releaseDescription,
                'draft' => true,
            ],
            'debug' => $output->isDebug(),
        ]);
        $release = $response->json();
        $releaseUrl = $repoApiUrl . '/releases/' . $release['id'];
        $uploadUrl = preg_replace('/\{.+?\}/', '', $release['upload_url']);

        $this->stdErr->writeln('Uploading the Phar file to the release');
        $fileResource = fopen($pharFilename, 'r');
        if (!$fileResource) {
            throw new \RuntimeException('Failed to open file for reading: ' . $fileResource);
        }
        $http->post($uploadUrl . '?name=' . rawurldecode($pharPublicFilename), [
            'headers' => [
                'Authorization' => 'token ' . $gitHubToken,
                'Accept' => 'application/vnd.github.v3+json',
                'Content-Type' => 'application/octet-stream',
            ],
            'body' => $fileResource,
            'debug' => $output->isDebug(),
        ]);

        $this->stdErr->writeln('Publishing the release');
        $http->patch($releaseUrl, [
            'headers' => [
                'Authorization' => 'token ' . $gitHubToken,
                'Accept' => 'application/vnd.github.v3+json',
                'Content-Type' => 'application/json',
            ],
            'json' => ['draft' => false],
            'debug' => $output->isDebug(),
        ]);

        $this->stdErr->writeln('');
        $this->stdErr->writeln('Release successfully published');
        $this->stdErr->writeln('https://github.com/' . $repoUrl . '/releases/latest');

        return 0;
    }
}