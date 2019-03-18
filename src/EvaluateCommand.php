<?php

namespace Grasmash\Evaluator;

use Doctrine\Common\Cache\FilesystemCache;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Kevinrob\GuzzleCache\CacheMiddleware;
use Kevinrob\GuzzleCache\Storage\DoctrineCacheStorage;
use Kevinrob\GuzzleCache\Strategy\PrivateCacheStrategy;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Command\BaseCommand;
use Symfony\Component\Filesystem\Filesystem;

abstract class Priorities {
    const CRITICAL = 400;
    const MAJOR = 300;
    const NORMAL = 200;
    const MINOR = 100;
}
abstract class Categories {
    const BUG_REPORT = 1;
    const TASK = 2;
    const FEATURE_REQUEST = 3;
    const SUPPORT_REQUEST = 4;
    const PLAN = 5;
}

class EvaluateCommand extends BaseCommand
{

    /** @var InputInterface */
    protected $input;

    /** @var Filesystem */
    protected $fs;

    public function configure()
    {
        $this->setName('evaluate');
        $this->setDescription("Evaluate a contributed Drupal project.");
        $this->addArgument('project', InputArgument::REQUIRED, 'The machine name of the project to evaluate.');
        $this->addUsage('ctools');
        // @todo Assume major version, allow to be specified.
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return int
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        // @see https://www.drupal.org/drupalorg/docs/api
        // https://www.drupal.org/api-d7/node.json?field_project_machine_name=[project-name]
        // You can pass special meta controls to your query: limit, page, sort, and direction.

        $this->input = $input;
        $project_name = $input->getArgument('project');
        $major_version = '8.x';
        $this->fs = new Filesystem();

        $client = $this->createGuzzleClient();
        $response = $client->request('GET', 'https://www.drupal.org/api-d7/node.json', [
            'query' => ['field_project_machine_name' => $project_name],
        ]);
        $body = $response->getBody()->getContents();
        $response_object = json_decode($body);
        $list_count = count($response_object->list);
        if (!$list_count) {
            throw new \Exception("No project with machine name $project_name could be found.");
        }

        $project = $response_object->list[0];
        $num_issues = $this->countProjectIssues($project);
        //$this->getProjectReleases($project);

        $output->writeln($project->title . ' (' . $project_name . ')');
        $output->writeln('<info>Version</info>:  ' . $major_version);
        $output->writeln('<info>Downloads</info>:  ' . $project->field_download_count);
        $output->writeln('<info>SA Coverage</info>:  ' . $project->field_security_advisory_coverage);
        $output->writeln('<info>Starred</info>:  ' . count($project->flag_project_star_user));
        $output->writeln('<info>Usage</info>:  ' . $project->project_usage->{"$major_version"});
        $output->writeln('<info># issues</info>:  ' . $num_issues);
        $output->writeln('  <info># critical</info>:  ');
        $output->writeln('  <info># major</info>:     ');
        $output->writeln('  <info># bugs</info>:      ');
        $output->writeln('  <info># rtbc</info>:      ');
        $output->writeln('<info>Latest release date</info>:      ');
        $output->writeln('<info>Latest issue update date</info>:      ');

        // Calculate a "maintenance health" score based on:
        // Average time for issue response.
        // % critical vs major vs minor.
        // % bugs.
        // Average time between releases.
        // Maintenance status.
        // Development status.
        // SA Coverage
        // # of uncommitted rtbcs.

        $project = new ContribProject($response_object);
        // https://www.drupal.org/api-d7/node.json?type=project_issue&field_project=3060&taxonomy_vocabulary_9=187541

        return $response->getStatusCode();
    }

    /**
     * @param $project
     *
     * @return int
     */
    protected function countProjectIssues($project) {
        if ($project->field_project_has_issue_queue) {
            // Query issue queue.
            $client = $this->createGuzzleClient();
            $response = $client->request('GET',
                'https://www.drupal.org/api-d7/node.json', [
                    'query' => [
                        'field_project' => $project->nid,
                        'type' => 'project_issue',
                    ],
                ]);
            $body = $response->getBody()->getContents();
            $response_object = json_decode($body);
            $last = $response_object->last;
            $url_parts = parse_url($last);
            $query = $url_parts['query'];
            parse_str($query, $query_parts);
            $num_pages = $query_parts['page'];

            if ($num_pages > 1) {
                $client = new Client();
                $response = $client->request('GET',
                    'https://www.drupal.org/api-d7/node.json', [
                        'query' => [
                            'field_project' => $project->nid,
                            'type' => 'project_issue',
                            'page' => $num_pages,
                            // @todo Filter by 8.x.
                        ],
                    ]);
                $body = $response->getBody()->getContents();
                $response_object = json_decode($body);
                $list_count = count($response_object->list);
                $num_issues = (($num_pages - 1) * 100) + $list_count;
            }
            else {
                $num_issues = count($response_object->list);
            }
            return $num_issues;
        }
        else {
            return 0;
        }
    }

    /**
     * @param $project
     */
    protected function getProjectReleases($project) {
        // Maybe use a different endpoint?
        // @see https://updates.drupal.org/release-history/ctools/8.x

        if ($project->field_project_has_releases) {
            $client = $this->createGuzzleClient();
            $response = $client->request('GET',
                'https://www.drupal.org/api-d7/node.json', [
                    'query' => [
                        'field_project' => $project->nid,
                        'type' => 'project_release',
                    ],
                ]);
            $body = $response->getBody()->getContents();
            $response_object = json_decode($body);

        }
    }

    /**
     * @return \GuzzleHttp\Client
     */
    protected function createGuzzleClient() {
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
}
