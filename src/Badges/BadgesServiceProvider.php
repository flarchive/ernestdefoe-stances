<?php

namespace ErnestDefoe\Stances\Badges;

use Flarum\Foundation\AbstractServiceProvider;

/**
 * Adds Stances' two metrics to FoF Badges' trigger builder.
 *
 * 🚨 Nothing here may load a fof/badges class when fof/badges is not installed: `::class` only
 * names a class, and the callback runs only when fof/badges builds its MetricManager, which a
 * forum without it never does.
 */
class BadgesServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->resolving('FoF\Badges\Trigger\MetricManager', function ($metrics, $container) {
            $metrics->register($container->make(VotesMetric::class));
            $metrics->register($container->make(CreatedMetric::class));
        });
    }
}
