<?php

namespace Grasmash\Evaluator;

use Alchemy\Zippy\Zippy;
use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Doctrine\Common\Cache\FilesystemCache;
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
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Class EvaluateCommand
 * @package Grasmash\Evaluator
 */
class EvaluateCommand
{
    /** @var InputInterface */
    protected $input;

    /** @var OutputInterface */
    protected $output;

    /** @var Filesystem */
    protected $fs;

    /** @var string */
    protected $tmp;

    /**
     * @var int
     */
    protected $phpStanErrorThreshold = 1;
    /**
     * @var int
     */
    protected $phpStanFileErrorThreshold = 1;
    /**
     * @var int
     */
    protected $phpCsErrorThreshold = 5;
    /**
     * @var int
     */
    protected $phpCsWarningThreshold = 10;

    /**
     * @var ProgressBar
     */
    protected $progressBar;

    /** @var \Symfony\Component\Console\Application */
    protected $application;

    public function __construct($application) {
        $this->application = $application;
    }

    /**
     * @param $input
     * @param $output
     */
    protected function setup($input, $output) {
        $this->input = $input;
        $this->output = $output;
        $this->fs = new Filesystem();
        $this->tmp = sys_get_temp_dir();
    }

    /**
     * Evaluate a multiple contributed Drupal projects.
     *
     * @command evaluate-multiple
     * @param string $file The file path to the yml file containing list of modules to evaluate.
     * @option string $format Valid formats are: csv,json,list,null,php,print-r,tsv,var_export,xml,yaml
     * @usage ./acquia.yml --format=csv
     * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
     *   Exit code of the command.
     */
    public function evaluateMultiple(InputInterface $input, OutputInterface $output, $file, $options = [
        'format' => 'table',
    ]) {
        $this->setup($input, $output);
        $list = Yaml::parseFile($file);
        $output_data = [];
        foreach ($list as $name => $options) {
            $default_options = [
                'dev-version' => null,
                'stable-version' => null,
            ];
            $options = array_merge($default_options, $options);
            $command_output = $this->evaluate($input, $output, $name, $options);
            $output_data[$name] = (array) $command_output;
        }
        return new RowsOfFields($output_data);
    }

    /**
     * Evaluate a contributed Drupal project.
     *
     * @command evaluate
     * @param string $project_name The machine name of the project to evaluate
     * @option string $format Valid formats are: csv,json,list,null,php,print-r,tsv,var_export,xml,yaml
     * @option dev-version The dev version to evaluate. This is used for issue statistics.
     * @option stable-version The stable version to evaluate. This is used for code analysis.
     * @usage acquia_connector --dev-version=8.x-1.x-dev
     * @return \Consolidation\OutputFormatters\StructuredData\PropertyList
     *   Exit code of the command.
     */
    public function evaluate(InputInterface $input, OutputInterface $output, $project_name, $options = [
        'format' => 'table',
        'dev-version' => null,
        'stable-version' => null,
    ]) {
        $this->setup($input, $output);
        ProgressBar::setFormatDefinition('custom', 'Evaluating <comment>%module%</comment>:
 %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%
 %message%
 ');
        $this->progressBar = new ProgressBar($output, 21);
        $this->progressBar->setFormat('custom');
        $this->progressBar->setMessage('Starting...');
        $this->progressBar->start();

        // @see https://www.drupal.org/drupalorg/docs/api
        // https://www.drupal.org/api-d7/node.json?field_project_machine_name=[project-name]
        // You can pass special meta controls to your query: limit, page, sort, and direction.
        // @todo Change this to a dynamic value from argument.
        $major_version = '8.x';
        $core_compatibility = CoreCompatibilityTerms::DRUPAL_8X;
        $this->progressBar->setMessage($project_name, 'module');
        $this->progressBar->setMessage('Querying drupal.org for project metadata...');
        $this->progressBar->advance();
        $project = $this->getProject($project_name);
        $metadata = [
            'name' => $project_name,
            'title' => $project->title,
            'downloads' => $project->field_download_count,
            'security_advisory_coverage' => $project->field_security_advisory_coverage,
            'starred' => count($project->flag_project_star_user),
            'usage' => $project->project_usage->{"$major_version"},
        ];

        $this->progressBar->setMessage('Querying drupal.org for project releases...');
        $this->progressBar->advance();
        $project_releases = $this->getProjectReleases($project, $core_compatibility);

        if ($options['dev-version']) {
            $dev_version = $options['dev-version'];
        } else {
            $dev_version = $this->determineDevRelease(
                $project_releases,
                $major_version,
                $project_name
            );
        }
        $metadata['dev-version'] = $dev_version;

        if ($options['stable-version']) {
            $stable_version = $options['stable-version'];
        } else {
            $stable_version = $this->determineRecommendedRelease($project_releases, $major_version, $project_name);
        }
        $metadata['stable-version'] = $stable_version;

        // Download module.
        $this->progressBar->setMessage('Downloading project from Drupal.org...');
        $this->progressBar->advance();
        $project_string = $project_name . "-" . $stable_version;
        $download_path = $this->downloadProjectFromDrupalOrg($project_string);

        // Code analysis.
        $this->progressBar->setMessage('Starting code analysis in background...');
        $this->progressBar->advance();
        $phpstan_process = $this->startPhpStan($download_path);
        $phpcs_process = $this->startPhpCs($download_path);
        $composer_validate_process = $this->startComposerValidate();

        $this->progressBar->setMessage('Calculating issues statistics...');
        $this->progressBar->advance();
        $issue_stats = $this->summarizeIssues($project, $dev_version);
        $this->progressBar->setMessage('Calculating release statistics...');
        $this->progressBar->advance();
        $release_stats = $this->summarizeReleases($project_releases);

        // Calculate a "maintenance health" score based on:
        // Average time for issue response.
        // % critical vs major vs minor.
        // % bugs.
        // Average time between releases.
        // Maintenance status.
        // Development status.
        // SA Coverage
        // # of uncommitted rtbcs.

        // $project = new ContribProject($response_object);
        // https://www.drupal.org/api-d7/node.json?type=project_issue&field_project=3060&taxonomy_vocabulary_9=187541

        $phpstan_stats = $this->endPhpStan($phpstan_process, $project_name);
        $phpcs_stats = $this->endPhpCs($phpcs_process, $project_name);
        $composer_stats = $this->endComposerValidate($composer_validate_process);

        $output = array_merge(
            $metadata,
            $issue_stats,
            $release_stats,
            $phpstan_stats,
            $phpcs_stats,
            $composer_stats
        );

        return new PropertyList($output);
    }

    /**
     * @param $project
     *
     * @return int
     */
    protected function countProjectIssues($project, $query = [])
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
        } else {
            return 0;
        }
    }


    /**
     * @param $project
     * @param $core_compatibility
     *
     * @return array
     */
    protected function getProjectReleases($project, $core_compatibility)
    {
        // Maybe use a different endpoint?
        // @see https://updates.drupal.org/release-history/ctools/8.x
        if ($project->field_project_has_releases) {
            $response_object = $this->requestNode([
                'field_release_project' => $project->nid,
                'type' => 'project_release',
                'taxonomy_vocabulary_' . Vocabularies::CORE_COMPATIBILITY => $core_compatibility,
            ]);
            return $response_object->list;
        } else {
            return [];
        }
    }

    /**
     * @return \GuzzleHttp\Client
     */
    protected function createGuzzleClient()
    {
        $stack = HandlerStack::create();
        $stack->push(
            new CacheMiddleware(
                new PrivateCacheStrategy(
                    new DoctrineCacheStorage(
                        new FilesystemCache(__DIR__ . '/../cache')
                    )
                )
            ),
            'cache'
        );
        $client = new Client(['handler' => $stack]);
        return $client;
    }

    /**
     * @param array $query
     *
     * @return object
     *
     * @throws \Exception
     */
    protected function requestNode($query)
    {
        $client = $this->createGuzzleClient();
        $response = $client->request(
            'GET',
            'https://www.drupal.org/api-d7/node.json',
            [
                'query' => $query,
                'on_stats' => function (TransferStats $stats) use (&$url) {
                    $url = $stats->getEffectiveUri();
                }
            ]
        );
        if ($response->getStatusCode() !== 200) {
            // @todo Make this display even when not verbose.
            throw new \Exception("Request to $url failed, returned {$response->getStatusCode()} with reason: {$response->getReasonPhrase()}");
        }
        $body = $response->getBody()->getContents();
        $response_object = json_decode($body);
        return $response_object;
    }

    /**
     * @param $numerator
     * @param $denominator
     *
     * @return string
     */
    protected function formatPercentage($numerator, $denominator)
    {
        return number_format($numerator / $denominator * 100, 2);
    }

    protected function downloadProjectFromDrupalOrg($project_string)
    {
        $targz_filename = "$project_string.tar.gz";
        $targz_filepath = "{$this->tmp}/$targz_filename";
        $untarred_dirpath = "{$this->tmp}/$project_string";
        file_put_contents(
            $targz_filepath,
            fopen(
                "https://ftp.drupal.org/files/projects/$targz_filename",
                'r'
            )
        );
        if (!file_exists($untarred_dirpath)) {
            $this->fs->mkdir($untarred_dirpath);
            $zippy = Zippy::load();
            $archive = $zippy->open($targz_filepath);
            $archive->extract($untarred_dirpath);
        }

        return $untarred_dirpath;
    }

    /**
     * @param string $download_path
     *
     * @return \Symfony\Component\Process\Process
     */
    protected function startPhpStan(
        $download_path
    ): \Symfony\Component\Process\Process {
        $command = "./vendor/bin/phpstan analyse '$download_path' --error-format=json --no-progress";
        return $this->startProcess($command);
    }

    /**
     * @param string $command
     *
     * @return \Symfony\Component\Process\Process
     */
    protected function startProcess(
        $command
    ): \Symfony\Component\Process\Process {
        $root_dir = dirname(__DIR__);
        $process = new Process($command, $root_dir, null, null, 300);
        $process->start();
        if ($this->output->isVerbose()) {
            foreach ($process as $type => $data) {
                $this->output->writeln($data);
            }
        }
        return $process;
    }

    /**
     * @param $phpstan_process
     * @param $project_name
     */
    protected function endPhpStan(
        Process $phpstan_process,
        $project_name
    ) {
        $this->progressBar->setMessage('Waiting for phpstan to finish...');
        $this->progressBar->advance();
        $phpstan_process->wait();
        $output_data = [];
        if ($phpstan_process->getOutput()) {
            $phpstan_output = json_decode($phpstan_process->getOutput());
            $output_data['deprecation_errors'] = $phpstan_output->totals->errors;
            $output_data['deprecation_file_errors'] = $phpstan_output->totals->file_errors;
        } else {
            $this->output->writeln("  <error>Failed to execute PHPStan against $project_name</error>");
            $this->output->write($phpstan_process->getErrorOutput());
        }

        return $output_data;
    }

    /**
     * @param Process $phpcs_process
     * @param string $project_name
     * @param Process $phpstan_process
     */
    protected function endPhpCs(
        $phpcs_process,
        $project_name
    ) {
        $this->progressBar->setMessage('Waiting for phpcs to finish...');
        $this->progressBar->advance();
        $phpcs_process->wait();
        $output_data = [];
        if ($phpcs_process->getOutput()) {
            $phpcs_output = json_decode($phpcs_process->getOutput());
            $output_data['phpcs_errors'] = $phpcs_output->totals->errors;
            $output_data['phpcs_warnings'] = $phpcs_output->totals->warnings;
        } else {
            $this->output->writeln("  <error>Failed to execute PHPCS against $project_name</error>");
            $this->output->write($phpcs_process->getErrorOutput());
        }

        return $output_data;
    }

    /**
     * @param Process $process
     */
    protected function endComposerValidate($process) {
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
     * @param
     *
     * @return \Symfony\Component\Process\Process
     */
    protected function startComposerValidate(): \Symfony\Component\Process\Process
    {
        $process = $this->startProcess("composer validate --strict");
        return $process;
    }

    /**
     * @param $download_path
     *
     * @return \Symfony\Component\Process\Process
     */
    protected function startPhpCs($download_path
    ): \Symfony\Component\Process\Process
    {
        $process = $this->startProcess("./vendor/bin/phpcs '$download_path' --standard=./vendor/drupal/coder/coder_sniffer/Drupal --report=json");
        return $process;
    }

    /**
     * @param $project
     * @param $version
     */
    protected function summarizeIssues(
        $project,
        $version
    ) {
        // Determine version to filter on.
        $this->progressBar->setMessage('Counting total open issues...');
        $this->progressBar->advance();
        $num_issues = $this->countOpenIssues(
            $project,
            ['field_issue_version' => $version]
        );
        $output_data['issues_total'] = $num_issues;
        if ($num_issues) {
            $issue_stats = $this->outputIssueStatistics($project, $version);
            $output_data = array_merge($output_data, $issue_stats);
        }

        $this->progressBar->setMessage('Counting total rtbc issues...');
        $this->progressBar->advance();
        $query = [
            'field_project' => $project->nid,
            'type' => 'project_issue',
            'field_issue_status' => Statuses::RTBC,
            'sort' => 'changed',
            'direction' => 'DESC',
        ];
        $response_object = $this->requestNode($query);
        $num_rtbc = count($response_object->list);
        // @todo Add limit!
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
     * @param $project_releases
     */
    protected function summarizeReleases(
        $project_releases
    ) {
        $num_releases = count($project_releases);
        $last_release = end($project_releases);
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
     * @param $project_name
     *
     * @return mixed
     * @throws \Exception
     */
    protected function getProject($project_name)
    {
        $response_object = $this->requestNode(['field_project_machine_name' => $project_name]);
        $list_count = count($response_object->list);
        if (!$list_count) {
            throw new \Exception("No project with machine name $project_name could be found.");
        }

        $project = $response_object->list[0];
        return $project;
    }

    /**
     * @param $project_releases
     * @param $major_version
     * @param $project_name
     *
     * @throws \Exception
     * @return string
     */
    protected function determineRecommendedRelease(
        $project_releases,
        $major_version,
        $project_name
    ) {
        // Stable releases are typically at the end of the array.
        $releases = array_reverse($project_releases);
        foreach ($releases as $project_release) {
            // If field_release_version_extra is null, then it is not a dev
            // alpha, beta, or rc release.
            if (is_null($project_release->field_release_version_extra)
                && substr(
                    $project_release->field_release_version,
                    0,
                    3
                ) == $major_version) {
                $recommended_version = $project_release->field_release_version;
                return $recommended_version;
            }
        }
        // Otherwise, return a non-stable release.
        foreach ($releases as $project_release) {
            if (substr(
                    $project_release->field_release_version,
                    0,
                    3
                ) == $major_version) {
                $recommended_version = $project_release->field_release_version;
                return $recommended_version;
            }
        }
        if (!isset($recommended_version)) {
            throw new \Exception("Unable to determine recommended release for $project_name for Drupal major version $major_version.");
        }
    }

    /**
     * @param $project_releases
     * @param $major_version
     * @param $project_name
     *
     * @return mixed
     * @throws \Exception
     */
    protected function determineDevRelease(
        $project_releases,
        $major_version,
        $project_name
    ) {
        // We're only querying dev versions. E.g., 8.x-3-x-dev.
        foreach ($project_releases as $project_release) {
            if ($project_release->field_release_version_extra
                && substr($project_release->field_release_version, 0, 3) == $major_version
                && $project_release->field_release_version_extra == 'dev') {
                $dev_version = $project_release->field_release_version;
                return $dev_version;
            }
        }
        if (!isset($dev_version)) {
            throw new \Exception("Unable to find development release for $project_name for Drupal major version $major_version.");
        }
    }

    /**
     * @param $label
     * @param $value
     * @param $threshold
     */
    protected function printMetric($label, $value, $threshold, $suffix = '')
    {
        $message_type = 'info';
        if ($value >= $threshold) {
            $message_type = 'error';
        }
        $this->output->writeln("<$message_type>$label</$message_type>: $value $suffix");
    }

    /**
     * @param $project
     * @param array $priority
     *
     * @return int
     */
    protected function countOpenIssues($project, array $query = []): int {
        $num_crit_issues = 0;
        foreach (Statuses::getOpenStatuses() as $status) {
            $query['field_issue_status'] = $status;
            $num_crit_issues += $this->countProjectIssues($project, $query);
        }
        return $num_crit_issues;
    }

    /**
     * @param $project
     * @param $version
     */
    protected function outputIssueStatistics(
        $project,
        $version
    ) {
        $this->progressBar->setMessage('Counting open critical issues...');
        $this->progressBar->advance();
        $num_crit_issues = $this->countOpenIssues($project, [
            'field_issue_priority' => Priorities::CRITICAL,
            'field_issue_version' => $version
        ]);
        $output_data['issues_priority_critical'] = $num_crit_issues;

        $this->progressBar->setMessage('Counting open major issues...');
        $this->progressBar->advance();
        $num_major_issues = $this->countOpenIssues($project, [
            'field_issue_priority' => Priorities::MAJOR,
            'field_issue_version' => $version
        ]);
        $output_data['issues_priority_major'] = $num_major_issues;

        $this->progressBar->setMessage('Counting open normal issues...');
        $this->progressBar->advance();
        $num_normal_issues = $this->countOpenIssues($project, [
            'field_issue_priority' => Priorities::NORMAL,
            'field_issue_version' => $version
        ]);
        $output_data['issues_priority_normal'] = $num_normal_issues;

        $this->progressBar->setMessage('Counting open minor issues...');
        $this->progressBar->advance();
        $num_minor_issues = $this->countOpenIssues($project, [
            'field_issue_priority' => Priorities::MINOR,
            'field_issue_version' => $version
        ]);
        $output_data['issues_priority_minor'] = $num_minor_issues;

        $this->progressBar->setMessage('Counting open bug issues...');
        $this->progressBar->advance();
        $num_bug_issues = $this->countOpenIssues($project, [
            'field_issue_category' => Categories::BUG_REPORT,
            'field_issue_version' => $version
        ]);
        $output_data['issues_category_bug'] = $num_bug_issues;

        $this->progressBar->setMessage('Counting open feature issues...');
        $this->progressBar->advance();
        $num_feature_issues = $this->countOpenIssues($project, [
            'field_issue_category' => Categories::FEATURE_REQUEST,
            'field_issue_version' => $version
        ]);
        $output_data['issues_category_feature'] = $num_feature_issues;

        $this->progressBar->setMessage('Counting open support issues...');
        $this->progressBar->advance();
        $num_support_issues = $this->countOpenIssues($project, [
            'field_issue_category' => Categories::SUPPORT_REQUEST,
            'field_issue_version' => $version
        ]);
        $output_data['issues_category_support'] = $num_support_issues;

        $this->progressBar->setMessage('Counting open task issues...');
        $this->progressBar->advance();
        $num_task_issues = $this->countOpenIssues($project, [
            'field_issue_category' => Categories::TASK,
            'field_issue_version' => $version
        ]);
        $output_data['issues_category_task'] = $num_task_issues;

        $this->progressBar->setMessage('Counting open plan issues...');
        $this->progressBar->advance();
        $num_plan_issues = $this->countOpenIssues($project, [
            'field_issue_category' => Categories::PLAN,
            'field_issue_version' => $version
        ]);
        $output_data['issues_category_plan'] = $num_plan_issues;

        return $output_data;
    }
}
