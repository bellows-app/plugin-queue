<?php

namespace Bellows\Plugins;

use Bellows\PluginSdk\Contracts\Deployable;
use Bellows\PluginSdk\Contracts\Installable;
use Bellows\PluginSdk\Data\WorkerParams;
use Bellows\PluginSdk\Facades\Artisan;
use Bellows\PluginSdk\Facades\Console;
use Bellows\PluginSdk\Facades\DeployScript;
use Bellows\PluginSdk\Facades\Project;
use Bellows\PluginSdk\Plugin;
use Bellows\PluginSdk\PluginResults\CanBeDeployed;
use Bellows\PluginSdk\PluginResults\CanBeInstalled;
use Bellows\PluginSdk\PluginResults\DeploymentResult;
use Bellows\PluginSdk\PluginResults\InstallationResult;

class Queue extends Plugin implements Deployable, Installable
{
    use CanBeDeployed, CanBeInstalled;

    public function install(): ?InstallationResult
    {
        $queueConnection = $this->getQueueConnection('sync');

        $result = InstallationResult::create()->environmentVariable(
            'QUEUE_CONNECTION',
            $queueConnection
        );

        if ($queueConnection === 'database') {
            $result->installationCommand('queue:table');
        }

        return $result;
    }

    public function deploy(): ?DeploymentResult
    {
        $queueWorkers = [];
        $addAnother = Console::confirm('Do you want to add a queue worker?', true);

        $localConnection = Project::env()->get('QUEUE_CONNECTION', 'database');

        if ($localConnection === 'sync') {
            $localConnection = null;
        }

        do {
            $queueConnection = $this->getQueueConnection($localConnection);

            $queue = Console::ask('Queue', 'default');

            $params = $this->getParams();

            $worker = array_merge([
                'connection' => $queueConnection,
                'queue'      => $queue,
            ], collect($params)->mapWithKeys(
                fn ($item, $key) => [$key => $item['value']]
            )->toArray());

            $queueWorkers[] = WorkerParams::from($worker);

            $addAnother = Console::confirm('Do you want to add another queue worker?');

            // If we're adding another, we don't want to use default,
            // just offer that the first time
            $localConnection = null;
        } while ($addAnother);

        return DeploymentResult::create()
            ->environmentVariable('QUEUE_CONNECTION', $queueConnection)
            ->workers($queueWorkers)
            ->updateDeployScript(
                fn () => DeployScript::addBeforePHPReload(
                    Artisan::inDeployScript('queue:restart'),
                ),
            );
    }

    public function shouldDeploy(): bool
    {
        return true;
    }

    protected function getQueueConnection($default): string
    {
        return Console::choice('Which queue driver would you like to use?', [
            'beanstalkd',
            'database',
            'redis',
            'sqs',
            'sync',
        ], $default);
    }

    protected function getParams(): array
    {
        $params = [
            'timeout' => [
                'label' => 'Maximum Seconds Per Job',
                'value' => 0,
            ],
            'sleep' => [
                'label'    => 'Rest Seconds When Empty',
                'value'    => 60,
                'required' => true,
            ],
            'processes' => [
                'label' => 'Number of Processes',
                'value' => 1,
            ],
            'stopwaitsecs' => [
                'label' => 'Graceful Shutdown Seconds',
                'value' => 10,
            ],
            'daemon' => [
                'label' => 'Run Worker As Daemon',
                'value' => false,
            ],
            'force' => [
                'label' => 'Always Run, Even In Maintenance Mode',
                'value' => false,
            ],
            'tries' => [
                'label' => 'Maximum Tries',
                'value' => null,
            ],
        ];

        Console::table(
            ['Option', 'Value'],
            collect($params)->map(function ($item) {
                $value = $item['value'];

                if (is_bool($value)) {
                    $value = $value ? 'Yes' : 'No';
                } elseif (is_null($value)) {
                    $value = '-';
                }

                return [$item['label'], $value];
            })->toArray(),
        );

        if (Console::confirm('Defaults look ok?', true)) {
            return $params;
        }

        foreach ($params as $key => $item) {
            if (is_bool($item['value'])) {
                $value = Console::confirm($item['label'], $item['value']);
            } else {
                $value = Console::askForNumber($item['label'], $item['value'], $item['required'] ?? false);
            }

            $params[$key]['value'] = $value;
        }

        return $params;
    }
}
