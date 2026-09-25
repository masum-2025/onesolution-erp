<?php

namespace App\Platform\Modules\Http\Middleware;

use App\Platform\Modules\Exceptions\ModuleException;
use App\Platform\Modules\ModuleRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard: `->middleware(['auth:sanctum', 'org', 'module:payroll'])`.
 * Must run after `org`, which sets the tenant context.
 */
class EnsureModuleEnabled
{
    public function __construct(
        private CurrentContext $context,
        private ModuleRegistry $registry,
        private ModuleResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next, string $moduleKey): Response
    {
        $module = $this->registry->get($moduleKey);

        if (! $this->resolver->isEnabled($moduleKey, $this->context->organization())) {
            throw ModuleException::disabled($module->label());
        }

        return $next($request);
    }
}
