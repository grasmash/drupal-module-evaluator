<?php

namespace Grasmash\Evaluator;

use Alchemy\Zippy\Zippy;
use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Countable;
use Doctrine\Common\Cache\FilesystemCache;
use Exception;
use Grasmash\Evaluator\DrupalOrgData\Categories;
use Grasmash\Evaluator\DrupalOrgData\CoreCompatibilityTerms;
use Grasmash\Evaluator\DrupalOrgData\Priorities;
use Grasmash\Evaluator\DrupalOrgData\Statuses;
use Grasmash\Evaluator\DrupalOrgData\Vocabularies;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\TransferStats;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\Storage\DoctrineCacheStorage;
use Kevinrob\GuzzleCache\Strategy\PrivateCacheStrategy;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Class EvaluateCommand.
 *
 * @package Grasmash\Evaluator
 */
class EvaluateCommand
{
    /**
     * Command input.
     *
     * @var \Symfony\Component\Console\Input\InputInterface
     */
    protected $input;

    /**
     * Command output.
     *
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    protected $output;

    /**
     * Symfony filesystem component.
     *
     * @var \Symfony\Component\Filesystem\Filesystem
     */
    protected $fs;

    /**
     * Temporary directory.
     *
     * @var string
     */
    protected $tmp;

    /**
     * Symfony progress bar component.
     *
     * @var \Symfony\Component\Console\Helper\ProgressBar
     */
    protected $progressBar;

    /**
     * Scored points.
     *
     * @var int
     */
    protected $score;

    /**
     * Total available points.
     *
     * @var int
     */
    protected $total;

    /**
     * @var bool
     */
    protected $drupalCoreDownloaded = false;

    /**
     * @var SymfonyStyle
     */
    protected $io;

    /**
     * Shared setup for both commands.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     *   Command input.
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *   Command output.
     */
    protected function setup(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
        $this->io = new SymfonyStyle($input, $output);
        $this->fs = new Filesystem();
        $this->tmp = sys_get_temp_dir();
        $this->score = 0;
        $this->total = 0;
    }

    /**
     * Evaluate a multiple contributed Drupal projects.
     *
     * @command create-report
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param string $file
     *   The file path to the yml file containing list of modules to evaluate.
     *   See ./acquia.yml for example format.
     *
     * @param array $options
     *
     * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
     *   Exit code of the command.
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @option string $format Valid formats are: csv,json,list,null,php,print-r,
     * tsv,var_export,xml,yaml
     *
     * @usage acquia.yml --format=csv
     * @usage acquia.yml --fields=name,title,score,deprecation_errors
     *
     */
    public function createReport(
        InputInterface $input,
        OutputInterface $output,
        $file,
        $options = [
            'format' => 'json',
            'fields' => '',
        ]
    ): RowsOfFields {
        $this->setup($input, $output);
        $list = Yaml::parseFile($file);
        $default_options = [
            'version' => null,
            'scan-stable' => false,
            'skip-core-download' => false,
        ];
        $options = array_merge($default_options, $options);
        $output_data = [];
        foreach ($list as $key => $args) {
            $args['options'] = array_key_exists('options', $args) ? $args['options'] : [];
            $command_options = array_merge($options, $args['options']);
            $command_output = $this->evaluate($input, $output, $args['name'], $args['branch'], $command_options);
            $output_data[] = (array) $command_output;
        }


        return new RowsOfFields($output_data);
    }

    /**
     * Evaluate a contributed Drupal project.
     *
     * @command evaluate
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param string $name
     *   The machine name of the project to evaluate.
     * @param string $branch
     *   The dev version to evaluate. This is used for issue statistics.
     * @param array $options
     *
     * @option string $format Valid formats are: csv,json,list,null,php,print-r,tsv,var_export,xml,yaml
     * @option scan-stable Scan stable release rather than dev
     * @option fields Specify which fields are output.
     * @option skip-core-download Do not re-download core. Only use this if you're repeatedly running the tool.
     * @field-labels
     *   name: Name
     *   title: Title
     *   branch: Branch
     *   score: Score
     *   scored_points: Scored points
     *   total_points: Total points
     *   downloads: Downloads
     *   security_advisory_coverage: Security Advisory Coverage
     *   starred: Starred
     *   usage: Usage
     *   recommended_version: Recommended version
     *   scanned_version: Scanned version
     *   is_stable: Is stable
     *   issues_total: Total issues
     *   issues_priority_critical: Priority Critical Issues
     *   issues_priority_major: Priority Major Issues
     *   issues_priority_normal: Priority Normal Issues
     *   issues_priority_minor: Priority Minor Issues
     *   issues_category_bug: Category Bug Issues
     *   issues_category_feature: Category Feature Issues
     *   issues_category_support: Category Support Issues
     *   issues_category_task: Category Task Issues
     *   issues_category_plan: Category Plan Issues
     *   issues_status_rtbc: Status RTBC Issues
     *   issues_status_fixed_last: Last "Closed/fixed" issue date
     *   releases_total: Total releases
     *   releases_last: Last release date
     *   releases_days_since: Days since last release
     *   deprecation_errors: Deprecation errors
     *   deprecation_file_errors: Deprecation file errors
     *   phpcs_drupal_errors: PHPCS Drupal errors
     *   phpcs_drupal_warnings: PHPCS Drupal warnings
     *   phpcs_compat_errors: PHPCS compat errors
     *   phpcs_compat_warnings: PHPCS compat warnings
     *   composer_validate: Composer validation status
     *   orca_integrated: ORCA Integrated
     *   report_datetime: Report Date Time
     *
     * @usage acquia_connector 8.x-1.x-dev
     *
     * @return \Consolidation\OutputFormatters\StructuredData\PropertyList
     *   Exit code of the command.
     *
     * @throws \Exception*
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function evaluate(
        InputInterface $input,
        OutputInterface $output,
        $name,
        $branch,
        $options = [
            'format' => 'table',
            'scan-stable' => false,
            'skip-core-download' => false,
            'fields' => '',
        ]
    ): PropertyList {
        $this->setup($input, $output);
        ProgressBar::setFormatDefinition('custom', 'Evaluating <comment>%module%</comment>
 %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%
 %message%
 ');
        $this->progressBar = new ProgressBar($output, 22);
        $this->progressBar->setFormat('custom');
        $this->progressBar->setMessage('Starting...');
        $this->progressBar->setMessage($name . ':' . $branch, 'module');
        $this->progressBar->start();

        // @see https://www.drupal.org/drupalorg/docs/api
        // @see https://www.drupal.org/api-d7/node.json?field_project_machine_name=[project-name]
        // You can pass special meta controls to your query: limit, page, sort,
        // and direction.
        $major_version_int = $branch[0];
        if ($major_version_int == 8) {
            $core_compatibility = CoreCompatibilityTerms::DRUPAL_8X;
        } elseif ($major_version_int == 7) {
            $core_compatibility = CoreCompatibilityTerms::DRUPAL_7X;
        } else {
            throw new Exception('You must specify either a major version of either 7 or 8!');
        }
        $major_version = str_replace('-dev', '', $branch);

        $this->progressBar->setMessage('Querying drupal.org for project metadata...');
        $this->progressBar->advance();
        $project = $this->getProject($name);
        $metadata = [
            'name' => $name,
            'title' => $project->title,
            'branch' => $branch,
            'downloads' => NULL,
            // @todo Remove this from the schema. Drupal.org no longer returns this information.
            // 'downloads' => $project->field_download_count,
            'security_advisory_coverage' => $project->field_security_advisory_coverage,
            'starred' => (is_array($project->flag_project_star_user) || $project->flag_project_star_user instanceof Countable) ? count($project->flag_project_star_user) : 0,
            'usage' => $project->project_usage->{"$major_version"},
        ];

        $this->progressBar->setMessage('Querying drupal.org for project releases...');
        $this->progressBar->advance();

        $project_releases = $this->getProjectReleases($project, $core_compatibility);
        $recommended_release = $this->findRecommendedRelease($project_releases, $major_version_int, $branch);
        $recommended_version = $recommended_release->field_release_version;
        $metadata['recommended_version'] = $recommended_version;
        $metadata['is_stable'] = is_null($recommended_release->field_release_version_extra) ? 'yes' : 'no';

        // Download Drupal core.
        if (!$this->drupalCoreDownloaded && $options['skip-core-download'] !== null) {
            $this->progressBar->setMessage('Downloading Drupal core via Composer...');
            $this->progressBar->advance();
            $core_download_process = $this->downloadDrupalCore($major_version_int);
            $core_download_process->wait();
            if (!$core_download_process->isSuccessful()) {
                throw new Exception('Failed to download Drupal core');
            }
        }

        // Download module.
        $this->progressBar->setMessage('Downloading project from Drupal.org...');
        $this->progressBar->advance();
        if ($options['scan-stable']) {
            $metadata['scanned_version'] = $recommended_version;
        } else {
            $metadata['scanned_version'] = $branch;
        }
        $download_path = $this->downloadProjectFromDrupalOrg($name, $metadata['scanned_version']);

        // Start code analysis.
        $this->progressBar->setMessage('Starting code analysis in background...');
        $this->progressBar->advance();

        if ($major_version_int == 8) {
            $drupal_check_process = $this->startDrupalCheck($download_path);
        }
        $phpcs_drupal_process = $this->startPhpCsDrupal($download_path);
        $phpcs_php_compat_process = $this->startPhpCsPhpCompat($download_path);
        $composer_validate_process = $this->startComposerValidate($download_path);

        // Get issue statistics.
        $this->progressBar->setMessage('Calculating issues statistics...');
        $this->progressBar->advance();
        $issue_stats = $this->calculateIssueStatistics($project, $branch);
        $this->progressBar->setMessage('Calculating release statistics...');
        $this->progressBar->advance();
        $release_stats = $this->summarizeReleases($project_releases);

        // End code analysis processes.
        if ($major_version_int == 8) {
            $drupal_check_stats = $this->endDrupalCheck($drupal_check_process, $name);
        } else {
            $drupal_check_stats['deprecation_errors'] = null;
            $drupal_check_stats['deprecation_file_errors'] = null;
        }
        $phpcs_drupal_stats = $this->endPhpCsDrupal($phpcs_drupal_process, $name);
        $phpcs_php_compat_stats = $this->endPhpCsPhpCompat($phpcs_php_compat_process, $name);
        $composer_stats = $this->endComposerValidate($composer_validate_process);

        $metadata['orca_integrated'] = $this->isOrcaIntegrated($name, $metadata['scanned_version']) ? 'yes' : 'no';

        // Prepare output.
        $output_data = array_merge($metadata, $issue_stats, $release_stats, $drupal_check_stats, $phpcs_drupal_stats, $phpcs_php_compat_stats, $composer_stats);

        $this->calculateScore($output_data);
        $output_data['scored_points'] = $this->score;
        $output_data['total_points'] = $this->total;
        $output_data['score'] = $this->formatPercentage($this->score, $this->total);
        $output_data['report_datetime'] = date('c');

        $this->progressBar->setMessage('Done!');
        $this->progressBar->advance();

        // Usage in per major branch. E.g., 8.2.x and 8.1.x share same usage stats.
        // Downloads is per module. Does not distinguish between branches.

        return new PropertyList($output_data);
    }

    /**
     * Counts project issues that match a particular query.
     *
     * @param string $project
     *   The project machine name.
     * @param array $query
     *   An array of query parameters for the Drupal.org request.
     *
     * @return int
     *   The number of issues.
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function countProjectIssues($project, $query = []): int
    {
        if ($project->field_project_has_issue_queue) {
            $default_query = [
                'field_project' => $project->nid,
                'type' => 'project_issue',
            ];
            $query = array_merge($default_query, $query);
            $response_object = $this->requestNode($query);
            $last = $response_object->last;
            $url_parts = parse_url($last);
            $query = $url_parts['query'];
            parse_str($query, $query_parts);
            $num_pages = $query_parts['page'];

            if ($num_pages > 1) {
                $response_object = $this->requestNode([
                    'field_project' => $project->nid,
                    'type' => 'project_issue',
                    'page' => $num_pages,
                ]);
                $list_count = count($response_object->list);
                $num_issues = (($num_pages - 1) * 100) + $list_count;
            } else {
                $num_issues = count($response_object->list);
            }
            return $num_issues;
        }

        return 0;
    }

    /**
     * Gets all of the releases for a project matching a major core version.
     *
     * @param string $project
     *   Project machine name.
     * @param $core_compatibility
     *   Core compatibility. E.g., CoreCompatibilityTerms::DRUPAL_8X.
     *
     * @return array
     *   An array of releases.
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function getProjectReleases($project, $core_compatibility): array
    {
        // Maybe use a different endpoint?
        // @see https://updates.drupal.org/release-history/ctools/8.x
        if ($project->field_project_has_releases) {
            $response_object = $this->requestNode([
                'field_release_project' => $project->nid,
                'type' => 'project_release',
                'taxonomy_vocabulary_' . Vocabularies::CORE_COMPATIBILITY => $core_compatibility,
                'sort' => 'created',
                'direction' => 'DESC',
            ]);
            return $response_object->list;
        }

        return [];
    }

    /**
     * Creates a Guzzle client with local file caching middleware.
     *
     * @return \GuzzleHttp\Client
     *   The Guzzle client.
     */
    protected function createGuzzleClient()
    {
        $stack = HandlerStack::create();
        $stack->push(
            new CacheMiddleware(new PrivateCacheStrategy(new DoctrineCacheStorage(new FilesystemCache(__DIR__ . '/../cache')))),
            'cache'
        );

        return new Client(['handler' => $stack]);
    }

    /**
     * Requests a node from the Drupal.org API.
     *
     * @param array $query
     *   The query to add to the end of the Drupal.org API.
     *
     * @return object
     *   The response object.
     *
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     *   Thrown if request is unsuccessful.
     */
    protected function requestNode($query)
    {
        $client = $this->createGuzzleClient();
        $response = $client->request('GET', 'https://www.drupal.org/api-d7/node.json', [
                'query' => $query,
                'on_stats' => static function (TransferStats $stats) use (&$url) {
                    $url = $stats->getEffectiveUri();
                },
            ]);
        if ($response->getStatusCode() !== 200) {
            throw new Exception("Request to $url failed, returned {$response->getStatusCode()} with reason: {$response->getReasonPhrase()}");
        }
        $body = $response->getBody()->getContents();

        return json_decode($body);
    }

    /**
     * Formats a percentage, given a numerator and denominator.
     *
     * @param int $numerator
     *   The numerator.
     * @param int $denominator
     *   The denominator.
     *
     * @return string
     *   The formatted percentage.
     */
    protected function formatPercentage($numerator, $denominator): string
    {
        return number_format($numerator / $denominator * 100, 2);
    }

    /**
     * Downloads Drupal core using drupal-composer/drupal-project via Composer.
     *
     * @param int $major_version
     *   The major version of Drupal core. E.g., 8.
     *
     * @return \Symfony\Component\Process\Process
     */
    protected function downloadDrupalCore($major_version): Process
    {
        $this->drupalCoreDownloaded = true;
        $dirname = 'drupal' . $major_version;
        $download_path = $this->tmp . '/' . $dirname;
        $this->fs->remove($download_path);
        $this->fs->mkdir($download_path);
        // @todo Cache the shit out of this!
        $process = $this->startProcess("composer create-project drupal-composer/drupal-project:{$major_version}.x-dev $dirname --no-interaction --no-ansi --stability=dev --working-dir={$this->tmp}");

        return $process;
    }

    /**
     * Downloads a project tarball from Drupal.org.
     *
     * @param string $project_string
     *   E.g., acquia_connector-8.x-1.0.
     *
     * @return string
     *   The file path to the untarred archive.
     */
    protected function downloadProjectFromDrupalOrg($name, $version): string
    {
        $targz_filename = "{$name}-{$version}.tar.gz";
        $targz_filepath = "{$this->tmp}/$targz_filename";
        $untarred_dirpath = "{$this->tmp}/drupal8/web/modules/contrib";
        file_put_contents($targz_filepath, fopen("https://ftp.drupal.org/files/projects/$targz_filename", 'r'));
        $this->fs->remove($untarred_dirpath);
        if (!file_exists($untarred_dirpath)) {
            $this->fs->mkdir($untarred_dirpath);
            $zippy = Zippy::load();
            $archive = $zippy->open($targz_filepath);
            $archive->extract($untarred_dirpath);
        }
        // @todo Throw error if download fails!

        $project_path = $untarred_dirpath . "/$name";

        return $project_path;
    }

    /**
     * Starts a phpstan process.
     *
     * @param string $download_path
     *  The path of the directory to scan.
     *
     * @return \Symfony\Component\Process\Process
     */
    protected function startDrupalCheck(
        $download_path
    ): Process {
        $command = "./vendor/bin/drupal-check --format=json --deprecations --no-interaction --no-ansi --no-progress '$download_path'";
        return $this->startProcess($command);
    }

    /**
     * Starts a command process.
     *
     * @param string $command
     *   The command to run.
     *
     * @param null $dir
     * @return \Symfony\Component\Process\Process
     */
    protected function startProcess(
        $command,
        $dir = null
    ): Process {
        if ($dir === null) {
            $dir = dirname(__DIR__);
        }
        $process = new Process($command, $dir, null, null, 1200);
        $process->start();
        if ($this->output->isVerbose()) {
            $this->output->writeln("Executing <comment>$command</comment> in <info>$dir</info>");
            foreach ($process as $type => $data) {
                $this->output->writeln($data);
            }
        }
        return $process;
    }

    /**
     * Ends phpstan process and processes output.
     *
     * @param Process $phpstan_process
     * @param string $project_name
     *
     * @return array
     */
    protected function endDrupalCheck(
        Process $phpstan_process,
        $project_name
    ): array {
        $this->progressBar->setMessage('Waiting for phpstan to finish...');
        $this->progressBar->advance();
        $phpstan_process->wait();
        $output_data = [];

        if ($phpstan_process->getOutput()) {
            $phpstan_output = json_decode($phpstan_process->getOutput(), false);
            if (is_object($phpstan_output) && property_exists($phpstan_output, 'totals')) {
                $output_data['deprecation_errors'] = $phpstan_output->totals->errors;
                $output_data['deprecation_file_errors'] = $phpstan_output->totals->file_errors;
            }
        }
        // Handle failure.
        if (!array_key_exists('deprecation_errors', $output_data)) {
            // Unfortunately errors are being written to stdout and polluting
            // the output files, so I'm disabling writing errors be default.
            if ($this->output->isVerbose()) {
                $this->io->error("  Failed to execute PHPStan against $project_name");
                $this->io->error($phpstan_process->getErrorOutput());
            }
            $output_data['deprecation_errors'] = null;
            $output_data['deprecation_file_errors'] = null;
        }

        return $output_data;
    }

    /**
     * Ends phpcs process and processes output.
     *
     * @param \Symfony\Component\Process\Process $phpcs_process
     * @param string $project_name
     *
     * @return array
     */
    protected function endPhpCsDrupal(
        $phpcs_process,
        $project_name
    ): array {
        $this->progressBar->setMessage('Waiting for phpcs to finish...');
        $this->progressBar->advance();
        $phpcs_process->wait();
        $output_data = [];
        if ($phpcs_process->getOutput()) {
            $phpcs_output = json_decode($phpcs_process->getOutput(), false);
            $output_data['phpcs_drupal_errors'] = $phpcs_output->totals->errors;
            $output_data['phpcs_drupal_warnings'] = $phpcs_output->totals->warnings;
        } else {
            // Unfortunately errors are being written to stdout and polluting
            // the output files, so I'm disabling writing errors be default.
            if ($this->output->isVerbose()) {
                $this->io->error("  Failed to execute PHPCS for Drupal standards against $project_name");
                $this->io->error($phpcs_process->getErrorOutput());
            }
            $output_data['phpcs_drupal_errors'] = null;
            $output_data['phpcs_drupal_warnings'] = null;
        }

        return $output_data;
    }

    /**
     * Ends phpcs process and processes output.
     *
     * @param \Symfony\Component\Process\Process $phpcs_process
     * @param string $project_name
     *
     * @return array
     */
    protected function endPhpCsPhpCompat(
        $phpcs_process,
        $project_name
    ): array {
        $this->progressBar->setMessage('Waiting for phpcs to finish...');
        $this->progressBar->advance();
        $phpcs_process->wait();
        $output_data = [];
        if ($phpcs_process->getOutput()) {
            $phpcs_output = json_decode($phpcs_process->getOutput(), false);
            $output_data['phpcs_compat_errors'] = $phpcs_output->totals->errors;
            $output_data['phpcs_compat_warnings'] = $phpcs_output->totals->warnings;
        } else {
            if ($this->output->isVerbose()) {
                $this->io->error("  Failed to execute PHPCS for PHP compatibility against $project_name");
                $this->io->error($phpcs_process->getErrorOutput());
            }
            $output_data['phpcs_compat_errors'] = null;
            $output_data['phpcs_compat_warnings'] = null;
        }

        return $output_data;
    }

    /**
     * Starts the `composer validate` process.
     *
     * @param string $download_path
     *  The path of the directory to scan.
     *
     * @return \Symfony\Component\Process\Process
     *   The started PHP process.
     */
    protected function startComposerValidate($download_path): Process
    {
        return $this->startProcess('composer validate --strict', $download_path);
    }

    /**
     * Ends composer validate process and processes output.
     *
     * @param \Symfony\Component\Process\Process $process
     *   The `composer validate` process.
     *
     * @return array
     */
    protected function endComposerValidate($process): array
    {
        $this->progressBar->setMessage('Waiting for composer to finish...');
        $this->progressBar->advance();
        $process->wait();
        $exit_code = $process->getExitCode();
        $output_data = [];

        switch ($exit_code) {
            // Success.
            case 0:
                $output_data['composer_validate'] = 'passes';
                break;

            case 1:
                $output_data['composer_validate'] = 'warnings';
                break;

            case 2:
                $output_data['composer_validate'] = 'errors';
                break;

            case 3:
                $output_data['composer_validate'] = 'null';
                break;
        }

        return $output_data;
    }

    /**
     * Starts the `phpcs` process.
     *
     * @param string $download_path
     *  The decompressed archive
     *
     * @return \Symfony\Component\Process\Process
     */
    protected function startPhpCsDrupal($download_path): Process
    {
        $command = "./vendor/bin/phpcs '$download_path' --standard=./vendor/drupal/coder/coder_sniffer/Drupal --report=json -q --no-colors";

        return $this->startProcess($command);
    }

    /**
     * Starts the `phpcs` process.
     *
     * @param string $download_path
     *  The decompressed archive
     *
     * @return \Symfony\Component\Process\Process
     */
    protected function startPhpCsPhpCompat($download_path): Process
    {
        $command = "./vendor/bin/phpcs '$download_path' --standard=./vendor/phpcompatibility/php-compatibility/PHPCompatibility --report=json -q --no-colors";

        return $this->startProcess($command);
    }

    /**
     * Calculates issue statistics.
     *
     * @param string $project
     *   The project machine name. E.g., machine name.
     * @param string $branch
     *   The module branch. E.g., 8.x-2.x-dev.
     *
     * @return array
     *   The output data.
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function calculateIssueStatistics($project, $branch): array
    {
        // Determine version to filter on.
        $this->progressBar->setMessage('Counting total open issues...');
        $this->progressBar->advance();
        $num_issues = $this->countOpenIssues($project, ['field_issue_version' => $branch]);
        $output_data['issues_total'] = $num_issues;
        $issue_stats = $this->getIssueStatistics($project, $branch);
        $output_data = array_merge($output_data, $issue_stats);
        $this->progressBar->setMessage('Counting total rtbc issues...');
        $this->progressBar->advance();
        $query = [
            'field_project' => $project->nid,
            'type' => 'project_issue',
            'field_issue_status' => Statuses::RTBC,
            'sort' => 'changed',
            'direction' => 'DESC',
            'field_issue_version' => $branch,
        ];
        $response_object = $this->requestNode($query);
        $num_rtbc = count($response_object->list);
        $output_data['issues_status_rtbc'] = $num_rtbc;

        $this->progressBar->setMessage('Finding last fixed issue...');
        $this->progressBar->advance();
        $query = [
            'field_project' => $project->nid,
            'type' => 'project_issue',
            'field_issue_status' => Statuses::CLOSED_FIXED,
            'sort' => 'changed',
            'direction' => 'DESC',
        ];
        $response_object = $this->requestNode($query);
        if (count($response_object->list)) {
            $latest_issue = $response_object->list[0];
            $latest_issue_date = date('r', $latest_issue->changed);
        } else {
            $latest_issue_date = 'never';
        }
        $output_data['issues_status_fixed_last'] = $latest_issue_date;

        return $output_data;
    }

    /**
     * Gets statistics about project releases.
     *
     * @param array $project_releases
     *   An array of project releases.
     *
     * @return array
     *   Array of output data.
     */
    protected function summarizeReleases($project_releases) : array
    {
        // This maxes out at the items per page limit, 50.
        $num_releases = count($project_releases);
        $last_release = reset($project_releases);
        $last_release_date = date('r', $last_release->created);
        $now = time();
        $datediff = $now - $last_release->created;
        $days_since_last_release = round($datediff / (60 * 60 * 24));

        $output_data['releases_total'] = $num_releases;
        $output_data['releases_last'] = $last_release_date;
        $output_data['releases_days_since'] = $days_since_last_release;

        return $output_data;
    }

    /**
     * Gets the project node from Drupal.org.
     *
     * @param string $project_name
     *   The project machine name.
     *
     * @return object
     *   The project node data.
     *
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     *   Throws an exception if the project was not found on Drupal.org.
     */
    protected function getProject($project_name) : object
    {
        $response_object = $this->requestNode(['field_project_machine_name' => $project_name]);
        $list_count = count($response_object->list);
        if (!$list_count) {
            throw new \Exception("No project with machine name $project_name could be found.");
        }

        return $response_object->list[0];
    }

    /**
     * Finds the latest recommended release for a project.
     *
     * Matches a given major version and branch.
     *
     * @param $project_releases
     * @param int $major_version_int
     * @param string $branch
     *   The module branch. E.g., 8.x-2.x-dev.
     *
     * @return object
     *   The recommended release.
     */
    protected function findRecommendedRelease(
        $project_releases,
        $major_version_int,
        $branch
    ) {
        $major_version = $major_version_int . '.x';
        $branch_minor_version = $branch[4];
        foreach ($project_releases as $project_release) {
            // If field_release_version_extra is null, then it is not a dev
            // alpha, beta, or rc release.
            $release_major_version = substr($project_release->field_release_version, 0, 3);
            if (is_null($project_release->field_release_version_extra) && $release_major_version == $major_version && $project_release->field_release_version_major == $branch_minor_version) {
                return $project_release;
            }
        }
        // Otherwise, return a non-stable release.
        foreach ($project_releases as $project_release) {
            $release_major_version = substr($project_release->field_release_version, 0, 3);
            if ($release_major_version === $major_version && $project_release->field_release_version_major === $branch_minor_version) {
                return $project_release;
            }
        }
    }

    /**
     * Counts the number of open Drupal.org issues matching a query.
     *
     * @param string $project
     *   The project machine name.
     * @param array $query
     *   An associative array to populate the request query string.
     *
     * @return int
     *   The number of issues.
     * @throws \Exception
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function countOpenIssues($project, array $query = []): int
    {
        $num_issues = 0;
        foreach (Statuses::getOpenStatuses() as $status) {
            $query['field_issue_status'] = $status;
            $num_issues += $this->countProjectIssues($project, $query);
        }
        return $num_issues;
    }

    /**
     * Gets statistics about issue priority, types, and categories.
     *
     * @param string $project
     *   The project machine name. E.g., ctools.
     * @param string $branch
     *  The module version. E.g., 8.x-2.x-dev.
     *
     * @return array
     *   An array of issues statistics.
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function getIssueStatistics(
        $project,
        $branch
    ) : array {
        $this->progressBar->setMessage('Counting open critical issues...');
        $this->progressBar->advance();
        $num_crit_issues = $this->countOpenIssues($project, [
            'field_issue_priority' => Priorities::CRITICAL,
            'field_issue_version' => $branch,
        ]);
        $output_data['issues_priority_critical'] = $num_crit_issues;

        $this->progressBar->setMessage('Counting open major issues...');
        $this->progressBar->advance();
        $num_major_issues = $this->countOpenIssues($project, [
            'field_issue_priority' => Priorities::MAJOR,
            'field_issue_version' => $branch,
        ]);
        $output_data['issues_priority_major'] = $num_major_issues;

        $this->progressBar->setMessage('Counting open normal issues...');
        $this->progressBar->advance();
        $num_normal_issues = $this->countOpenIssues($project, [
            'field_issue_priority' => Priorities::NORMAL,
            'field_issue_version' => $branch,
        ]);
        $output_data['issues_priority_normal'] = $num_normal_issues;

        $this->progressBar->setMessage('Counting open minor issues...');
        $this->progressBar->advance();
        $num_minor_issues = $this->countOpenIssues($project, [
            'field_issue_priority' => Priorities::MINOR,
            'field_issue_version' => $branch,
        ]);
        $output_data['issues_priority_minor'] = $num_minor_issues;

        $this->progressBar->setMessage('Counting open bug issues...');
        $this->progressBar->advance();
        $num_bug_issues = $this->countOpenIssues($project, [
            'field_issue_category' => Categories::BUG_REPORT,
            'field_issue_version' => $branch,
        ]);
        $output_data['issues_category_bug'] = $num_bug_issues;

        $this->progressBar->setMessage('Counting open feature issues...');
        $this->progressBar->advance();
        $num_feature_issues = $this->countOpenIssues($project, [
            'field_issue_category' => Categories::FEATURE_REQUEST,
            'field_issue_version' => $branch,
        ]);
        $output_data['issues_category_feature'] = $num_feature_issues;

        $this->progressBar->setMessage('Counting open support issues...');
        $this->progressBar->advance();
        $num_support_issues = $this->countOpenIssues($project, [
            'field_issue_category' => Categories::SUPPORT_REQUEST,
            'field_issue_version' => $branch,
        ]);
        $output_data['issues_category_support'] = $num_support_issues;

        $this->progressBar->setMessage('Counting open task issues...');
        $this->progressBar->advance();
        $num_task_issues = $this->countOpenIssues($project, [
            'field_issue_category' => Categories::TASK,
            'field_issue_version' => $branch,
        ]);
        $output_data['issues_category_task'] = $num_task_issues;

        $this->progressBar->setMessage('Counting open plan issues...');
        $this->progressBar->advance();
        $num_plan_issues = $this->countOpenIssues($project, [
            'field_issue_category' => Categories::PLAN,
            'field_issue_version' => $branch,
        ]);
        $output_data['issues_category_plan'] = $num_plan_issues;

        return $output_data;
    }

    /**
     * Calculates scored points.
     *
     * @param array $output_data
     *   The fully populated output array.
     */
    protected function calculateScore($output_data): void
    {
        $this->evaluateAndAddPoints($output_data['security_advisory_coverage'] === 'covered', 5, 5);
        $this->evaluateAndAddPoints($output_data['is_stable'] === 'yes', 5, 5);
        $this->addScaledPoints(1.5, $output_data['issues_priority_critical'], 5);
        $this->addScaledPoints(1, $output_data['issues_priority_major'], 5);
        $this->addScaledPoints(1, $output_data['issues_status_rtbc'], 5);
        $this->addScaledPoints(.1, $output_data['deprecation_errors'], 5);
        $this->addScaledPoints(.01, $output_data['phpcs_drupal_errors'] + $output_data['phpcs_drupal_warnings'], 5);
        $this->addScaledPoints(.75, $output_data['phpcs_compat_errors'] + $output_data['phpcs_compat_warnings'], 5);
        $this->addScaledPoints(.01, $output_data['releases_days_since'], 5);
        $this->evaluateAndAddPoints($output_data['composer_validate'] === 'passes', 5, 5);
        $this->evaluateAndAddPoints($output_data['orca_integrated'] === 'yes', 10, 10);
    }

    /**
     * Calculate and set the scored points based on an inverse linear function.
     *
     * E.g., a coeffient of .01 would produce the following scores:
     *
     * | variable | score |
     * | 0        | 5     |
     * | 90       | 4.1   |
     * | 180      | 3.2   |
     * | 360      | 1.4   |
     * | 720      | 0     |
     *
     * @param $coefficient
     * @param $variable
     * @param $max_points
     */
    protected function addScaledPoints($coefficient, $variable, $max_points): void
    {
        $scored_points = max($max_points - ($coefficient * $variable), 0);
        $this->score += $scored_points;
        $this->total += $max_points;
    }

    /**
     * Increase scored points if criteria evaluates as true.
     *
     * @param bool $passes
     *   Indicates whether criteria evaluates as TRUE or FALSE.
     * @param int $scored_points
     *   The number of points scored.
     * @param int $total_points
     *   The total number of available points to score for criteria.
     */
    protected function evaluateAndAddPoints($passes, $scored_points, $total_points) : void
    {
        if ($passes) {
            $this->score += $scored_points;
        }
        $this->total += $total_points;
    }

    /**
     * @param string $project
     * @param string $scanned_version
     *
     * @return bool
     */
    protected function isOrcaIntegrated(string $project, string $scanned_version): bool
    {
        // Weirdly, dev tarballs don't include .travis.yml, even though stable tarballs do.
        $scanned_version = str_replace('-dev', '', $scanned_version);
        $url = "https://git.drupalcode.org/project/$project/raw/$scanned_version/.travis.yml";
        $travis_yml_contents = $this->downloadFile($url);
        if (strpos($travis_yml_contents, 'ORCA_SUT_NAME') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Download a file from a url.
     * @param $url
     *
     * @return bool|string
     */
    protected function downloadFile($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // We spoof the user agent because GitLab will deny access otherwise.
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13');

        if (curl_exec($ch) === false) {
            curl_close($ch);
            return false;
        } else {
            $output = curl_exec($ch);
            curl_close($ch);
            return $output;
        }
    }
}
