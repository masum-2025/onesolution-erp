<?php

namespace App\Platform\Dashboard\Contracts;

use App\Platform\Tenancy\Context\CurrentContext;

/**
 * One widget on a module's dashboard (manifest `dashboard.widgets[].provider`).
 * Asked only while the module is on and the person holds the widget's
 * permission; it reads the current organization and the units below it that
 * the person may see. Build the answer with WidgetData.
 */
interface DashboardWidget
{
    /**
     * @return array<string, mixed> From WidgetData::stat(), ::bars() or ::list().
     */
    public function data(CurrentContext $context): array;
}
