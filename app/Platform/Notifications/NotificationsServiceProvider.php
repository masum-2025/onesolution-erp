<?php

namespace App\Platform\Notifications;

use App\Platform\Billing\Events\InvoiceIssued;
use App\Platform\Billing\SelfServe\Events\PaymentOverdue;
use App\Platform\Billing\SelfServe\Events\TrialEnded;
use App\Platform\Billing\SelfServe\Events\TrialEnding;
use App\Platform\Billing\SelfServe\Events\WorkspaceRestored;
use App\Platform\Billing\SelfServe\Events\WorkspaceRestricted;
use App\Platform\Payments\Events\PaymentFailed;
use App\Platform\Payments\Events\PaymentSucceeded;
use App\Platform\DataExport\Events\DataExportReady;
use App\Platform\Legal\Events\LegalDocumentPublished;
use App\Platform\Transfers\Events\ClientTransferred;
use App\Platform\Transfers\Events\ClientTransferRequested;
use App\Platform\Notifications\Console\DecideSmsSender;
use App\Platform\Notifications\Contracts\SmsGateway;
use App\Platform\Notifications\Listeners\SendPlatformNotifications;
use App\Platform\Notifications\Services\LogSmsGateway;
use App\Platform\SupportAccess\Events\SupportAccessDecided;
use App\Platform\SupportAccess\Events\SupportAccessRequested;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Branded email and SMS (Phase 5B-3b): partner sending domains, SMS sender
 * IDs, partner wording, and the notifications platform events send.
 */
class NotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NotificationCatalog::class);

        // One provider per installation; the log driver until a real one is added (5C).
        $this->app->singleton(SmsGateway::class, fn () => match (config('notifications.sms.driver')) {
            'log' => new LogSmsGateway,
            default => throw new InvalidArgumentException('Unknown SMS driver ['.config('notifications.sms.driver').'].'),
        });
    }

    public function boot(): void
    {
        // Listeners are resolved when an event fires, never at boot (a fresh
        // install has no tables yet when the app starts).
        Event::listen(SupportAccessRequested::class, [SendPlatformNotifications::class, 'supportRequested']);
        Event::listen(SupportAccessDecided::class, [SendPlatformNotifications::class, 'supportDecided']);
        Event::listen(DataExportReady::class, [SendPlatformNotifications::class, 'exportReady']);
        Event::listen(InvoiceIssued::class, [SendPlatformNotifications::class, 'invoiceIssued']);
        Event::listen(ClientTransferRequested::class, [SendPlatformNotifications::class, 'transferRequested']);
        Event::listen(ClientTransferred::class, [SendPlatformNotifications::class, 'transferred']);
        Event::listen(LegalDocumentPublished::class, [SendPlatformNotifications::class, 'legalPublished']);
        // Self-serve billing (Phase 5C-2).
        Event::listen(PaymentSucceeded::class, [SendPlatformNotifications::class, 'paymentSucceeded']);
        Event::listen(PaymentFailed::class, [SendPlatformNotifications::class, 'paymentFailed']);
        Event::listen(TrialEnding::class, [SendPlatformNotifications::class, 'trialEnding']);
        Event::listen(TrialEnded::class, [SendPlatformNotifications::class, 'trialEnded']);
        Event::listen(PaymentOverdue::class, [SendPlatformNotifications::class, 'paymentOverdue']);
        Event::listen(WorkspaceRestricted::class, [SendPlatformNotifications::class, 'workspaceRestricted']);
        Event::listen(WorkspaceRestored::class, [SendPlatformNotifications::class, 'workspaceRestored']);

        // Test messages go to real inboxes and phones: a few per hour per person.
        RateLimiter::for('notification-test', fn (Request $request) => Limit::perHour(5)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([DecideSmsSender::class]);
        }
    }
}
