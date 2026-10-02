<?php

namespace App\Platform\Attention;

use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Attention\Providers\PendingRuleApprovals;
use App\Platform\Attention\Providers\PendingSupportRequests;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Everything waiting for the person in the current organization: the
 * platform's own items, then those of every module that is on here.
 */
final class AttentionCollector
{
    /** @var list<class-string<AttentionProvider>> */
    private const PLATFORM = [PendingRuleApprovals::class, PendingSupportRequests::class];

    public function __construct(
        private Container $container,
        private ModuleRegistry $modules,
        private ModuleResolver $resolver,
    ) {}

    /**
     * @return list<AttentionItem>
     */
    public function collect(CurrentContext $context): array
    {
        $classes = self::PLATFORM;
        $resolved = $this->resolver->resolveAll($context->organization());

        foreach ($this->modules->all() as $key => $module) {
            if ($resolved[$key]->enabled) {
                array_push($classes, ...$module->attention);
            }
        }

        $items = [];
        foreach ($classes as $class) {
            $provider = $this->container->make($class);
            if (! $provider instanceof AttentionProvider) {
                throw new InvalidArgumentException("[{$class}] is not an AttentionProvider.");
            }
            foreach ($provider->items($context) as $item) {
                if ($item->count > 0) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }
}
