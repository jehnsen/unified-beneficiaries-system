<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifies Provincial Admin supervisors when one or more claims are stuck in
 * PENDING_FRAUD_CHECK beyond the configured threshold. Sent via email so it
 * surfaces even when no one is actively watching the dashboard.
 */
class StaleFraudCheckNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Collection $staleClaims)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = $this->staleClaims->count();
        $claimList = $this->staleClaims->map(fn ($claim) => sprintf(
            '- UUID: %s | %s %s | %s | Stuck since: %s',
            $claim->uuid,
            optional($claim->beneficiary)->first_name,
            optional($claim->beneficiary)->last_name,
            optional($claim->municipality)->name ?? 'Unknown',
            $claim->updated_at->toDateTimeString(),
        ))->join("\n");

        return (new MailMessage)
            ->subject("[UBIS ALERT] {$count} claim(s) stuck in PENDING_FRAUD_CHECK")
            ->greeting("Hello {$notifiable->name},")
            ->line("The following {$count} claim(s) have been stuck in PENDING_FRAUD_CHECK for over 1 hour. The fraud scan job likely failed permanently — manual review or job re-queuing is required.")
            ->line($claimList)
            ->line('Please log in to UBIS to investigate and resolve these claims.')
            ->salutation('UBIS Automated Alert System');
    }
}
