<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Actions\ApproveInvoiceAction;
use App\Modules\Billing\Actions\CancelInvoiceSendingAction;
use App\Modules\Billing\Actions\GenerateInvoiceFromPlanAction;
use App\Modules\Billing\Actions\PrepareInvoiceDeliveryAction;
use App\Modules\Billing\Actions\SendInvoiceDeliveryAction;
use App\Modules\Billing\Data\DeliveryStatus;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Jobs\SendInvoiceEmailJob;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceDelivery;
use App\Modules\Billing\Support\BillingSchedule;
use App\Modules\Workspace\Models\Workspace;
use App\Notifications\InvoiceSentNotification;
use App\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Billing\Concerns\BuildsRocketFlood;
use Tests\Feature\Billing\Concerns\FakesWorkspaceMail;
use Tests\TestCase;

/**
 * Real invoice delivery through the workspace's own SMTP account. The
 * invoice's status (approved → issued) and its email's status (pending →
 * sending → sent / failed / needs attention) are separate: an email problem
 * never un-issues an invoice.
 */
final class InvoiceDeliveryTest extends TestCase
{
    use BuildsRocketFlood, FakesWorkspaceMail, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Carbon::setTestNow('2026-10-03 10:00:00');
        $this->setUpRocketFlood();
        $this->rocketFlood->update(['cc_emails' => ['ops@rocketflood.com']]);
        $this->fakeWorkspaceMail();
    }

    /** Dev & Office for September, approved at 10:00 (sends after 11:00). */
    private function approvedInvoice(): Invoice
    {
        $plan = $this->devAndOffice();
        $invoice = app(GenerateInvoiceFromPlanAction::class)->handle(
            $plan,
            app(BillingSchedule::class)->latestDueCycle($plan, CarbonImmutable::parse('2026-10-03')),
            $this->owner,
        );

        return app(ApproveInvoiceAction::class)->handle($invoice, $invoice->version, $this->owner);
    }

    private function dispatcher(): int
    {
        return $this->artisan('billing:dispatch-approved-invoices')->run();
    }

    private function postAs(string $route, Invoice $invoice, array $data = [], ?User $as = null): TestResponse
    {
        $response = $this->actingAs($as ?? $this->owner)
            ->withHeader('X-Inertia', 'true')
            ->from(route('workspace.invoices.show', [$this->workspace, $invoice]))
            ->post(route($route, [$this->workspace, $invoice]), $data);

        $this->flushHeaders();

        return $response;
    }

    private function sendNow(Invoice $invoice, ?User $as = null): TestResponse
    {
        return $this->postAs('workspace.invoices.send-now', $invoice, [], $as);
    }

    private function retry(Invoice $invoice, bool $confirmed = false, ?User $as = null): TestResponse
    {
        return $this->postAs('workspace.invoices.delivery.retry', $invoice, ['confirmed' => $confirmed], $as);
    }

    private function auditCount(AuditAction $action): int
    {
        return AuditLog::query()->where('action', $action->value)->count();
    }

    /* ---- The scheduler ---------------------------------------------------- */

    public function test_the_scheduler_waits_for_the_cancel_window(): void
    {
        $this->connectMail();
        $invoice = $this->approvedInvoice();

        $this->travelTo('2026-10-03 10:59:00');
        $this->dispatcher();

        $this->assertSame(InvoiceStatus::Approved, $invoice->fresh()->status);
        $this->assertSame(0, InvoiceDelivery::query()->count());
        $this->assertCount(0, $this->smtp->sent);
    }

    public function test_the_scheduler_issues_and_emails_a_due_invoice(): void
    {
        Mail::fake();
        Notification::fake();
        $this->connectMail();
        $invoice = $this->approvedInvoice();

        $this->travelTo('2026-10-03 11:00:00');
        $this->dispatcher();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame('INV-2026-0001', $invoice->number);

        $delivery = $invoice->delivery()->sole();
        $this->assertSame(DeliveryStatus::Sent, $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->sent_at);

        $this->assertCount(1, $this->smtp->sent);
        $email = $this->smtp->sent[0];
        $this->assertSame('Invoice INV-2026-0001 from SprintSync QA', $email->getSubject());
        $this->assertSame(['billing@rocketflood.com'], array_map(fn ($a) => $a->getAddress(), $email->getTo()));
        $this->assertSame(['ops@rocketflood.com'], array_map(fn ($a) => $a->getAddress(), $email->getCc()));
        $this->assertSame('billing@sprintsync.test', $email->getFrom()[0]->getAddress());
        $this->assertSame('SprintSync QA', $email->getFrom()[0]->getName());
        $this->assertSame('accounts@sprintsync.test', $email->getReplyTo()[0]->getAddress());

        $html = (string) $email->getHtmlBody();
        $text = (string) $email->getTextBody();
        foreach ([$html, $text] as $body) {
            $this->assertStringContainsString('Hi RocketFlood', $body);
            $this->assertStringContainsString('INV-2026-0001', $body);
            $this->assertStringContainsString('$5,059.20', $body);
        }

        $attachments = $email->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('application/pdf', $attachments[0]->getMediaType().'/'.$attachments[0]->getMediaSubtype());
        $this->assertSame(Storage::disk('local')->get($invoice->pdf_path), $attachments[0]->getBody(), 'the official PDF, byte for byte');

        Mail::assertNothingOutgoing();
        Notification::assertSentTo($this->owner, InvoiceSentNotification::class, function (InvoiceSentNotification $notification) {
            $data = $notification->toArray($this->owner);

            return $data['message'] === 'INV-2026-0001 was sent to RocketFlood.';
        });
        $this->assertSame(1, $this->auditCount(AuditAction::INVOICE_DELIVERY_SENT));
    }

    public function test_running_the_scheduler_twice_sends_once(): void
    {
        $this->connectMail();
        $invoice = $this->approvedInvoice();
        $this->travelTo('2026-10-03 11:00:00');

        $this->dispatcher();
        $this->dispatcher();
        $this->travel(1)->minute();
        $this->dispatcher();

        $this->assertCount(1, $this->smtp->sent);
        $this->assertSame(1, InvoiceDelivery::query()->count());
        $this->assertSame(1, $invoice->delivery()->sole()->attempts);
        $this->assertSame('INV-2026-0001', $invoice->fresh()->number);
    }

    public function test_without_email_connected_nothing_is_issued(): void
    {
        $invoice = $this->approvedInvoice();
        $this->travelTo('2026-10-03 11:00:00');

        $this->dispatcher();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Approved, $invoice->status, 'stays approved, waiting for email to be connected');
        $this->assertNull($invoice->number);
        $this->assertSame(0, InvoiceDelivery::query()->count());

        $this->connectMail();
        $this->dispatcher();
        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
        $this->assertCount(1, $this->smtp->sent);
    }

    public function test_the_job_carries_only_the_delivery_id(): void
    {
        Queue::fake();
        $this->connectMail();
        $this->approvedInvoice();
        $this->travelTo('2026-10-03 11:00:00');

        $this->dispatcher();

        Queue::assertPushed(SendInvoiceEmailJob::class, function (SendInvoiceEmailJob $job) {
            $payload = serialize($job);

            return $job->deliveryId === InvoiceDelivery::query()->sole()->id
                && ! str_contains($payload, 'super-secret-smtp-pass')
                && ! str_contains($payload, 'smtp.example.com')
                && ! str_contains($payload, '%PDF');
        });
    }

    public function test_two_workers_send_once(): void
    {
        $this->connectMail();
        $invoice = $this->approvedInvoice();
        $this->travelTo('2026-10-03 11:00:00');

        Queue::fake();
        $this->dispatcher();
        $delivery = InvoiceDelivery::query()->sole();

        $first = InvoiceDelivery::query()->findOrFail($delivery->id);
        $second = InvoiceDelivery::query()->findOrFail($delivery->id);
        app(SendInvoiceDeliveryAction::class)->handle($first);
        app(SendInvoiceDeliveryAction::class)->handle($second);
        (new SendInvoiceEmailJob($delivery->id))->handle(app(SendInvoiceDeliveryAction::class));

        $this->assertCount(1, $this->smtp->sent);
        $this->assertSame(1, $delivery->fresh()->attempts);
        $this->assertSame(DeliveryStatus::Sent, $delivery->fresh()->status);
        $this->assertSame('INV-2026-0001', $invoice->fresh()->number);
    }

    public function test_an_interrupted_send_becomes_needs_attention_not_a_resend(): void
    {
        $this->connectMail();
        $this->approvedInvoice();
        $this->travelTo('2026-10-03 11:00:00');
        Queue::fake();
        $this->dispatcher();
        $delivery = InvoiceDelivery::query()->sole();

        /* A worker claimed it and died mid-send. */
        InvoiceDelivery::query()->whereKey($delivery->id)->update(['status' => 'sending', 'attempts' => 1, 'last_attempted_at' => now()]);

        $this->travel(5)->minutes();
        $this->dispatcher();
        $this->assertSame(DeliveryStatus::Sending, $delivery->fresh()->status, 'still within the send time');

        $this->travel(6)->minutes();
        $this->dispatcher();
        $this->dispatcher();

        $this->assertSame(DeliveryStatus::NeedsAttention, $delivery->fresh()->status);
        $this->assertSame(1, $this->auditCount(AuditAction::INVOICE_DELIVERY_UNCERTAIN));
        $this->assertCount(0, $this->smtp->sent);
    }

    /* ---- Send now ------------------------------------------------------ */

    public function test_send_now_issues_and_sends_immediately(): void
    {
        $this->connectMail();
        $invoice = $this->approvedInvoice();

        $this->sendNow($invoice)->assertSessionHasNoErrors()->assertSessionHas('success', 'Sending the invoice now.');

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
        $this->assertSame(DeliveryStatus::Sent, $invoice->delivery()->sole()->status);
        $this->assertCount(1, $this->smtp->sent);
    }

    public function test_double_send_now_sends_once(): void
    {
        $this->connectMail();
        $invoice = $this->approvedInvoice();

        $this->sendNow($invoice);
        $this->sendNow($invoice);

        $this->assertCount(1, $this->smtp->sent);
        $this->assertSame(1, InvoiceDelivery::query()->count());
        $this->assertSame('INV-2026-0001', $invoice->fresh()->number);
    }

    public function test_send_now_needs_email_connected(): void
    {
        $invoice = $this->approvedInvoice();

        $this->sendNow($invoice)->assertSessionHas('error.message', 'Connect email delivery before sending invoices.');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Approved, $invoice->status);
        $this->assertNull($invoice->number, 'no number is taken');
    }

    public function test_an_already_sent_invoice_is_not_sent_again(): void
    {
        $this->connectMail();
        $invoice = $this->approvedInvoice();
        $this->sendNow($invoice);

        $this->sendNow($invoice);
        $this->retry($invoice)->assertSessionHas('error');
        $this->dispatcher();

        $this->assertCount(1, $this->smtp->sent);
        $this->assertSame(DeliveryStatus::Sent, $invoice->delivery()->sole()->status);
    }

    /* ---- Cancel sending vs the worker ---------------------------------- */

    public function test_cancel_before_the_worker_wins(): void
    {
        $this->connectMail();
        $invoice = $this->approvedInvoice();

        app(CancelInvoiceSendingAction::class)->handle($invoice, $this->owner);
        $this->travelTo('2026-10-03 11:00:00');
        $this->dispatcher();

        $this->assertSame(InvoiceStatus::ReadyToReview, $invoice->fresh()->status);
        $this->assertSame(0, InvoiceDelivery::query()->count());
        $this->assertCount(0, $this->smtp->sent);
    }

    public function test_the_worker_before_cancel_wins(): void
    {
        $this->connectMail();
        $invoice = $this->approvedInvoice();
        $stale = Invoice::query()->findOrFail($invoice->id);

        $this->travelTo('2026-10-03 11:00:00');
        $this->dispatcher();

        $this->expectException(InvoiceException::class);

        try {
            app(CancelInvoiceSendingAction::class)->handle($stale, $this->owner);
        } finally {
            $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
            $this->assertCount(1, $this->smtp->sent);
        }
    }

    public function test_preparing_a_cancelled_invoice_issues_nothing(): void
    {
        $this->connectMail();
        $invoice = $this->approvedInvoice();
        $stale = Invoice::query()->with('workspace.mailSettings')->findOrFail($invoice->id);
        app(CancelInvoiceSendingAction::class)->handle($invoice, $this->owner);

        try {
            app(PrepareInvoiceDeliveryAction::class)->handle($stale, null, sendNow: true);
            $this->fail('a cancelled invoice must not be issued');
        } catch (InvoiceException) {
        }

        $this->assertNull($invoice->fresh()->number);
        $this->assertSame(0, InvoiceDelivery::query()->count());
    }

    /* ---- Failures and retry ------------------------------------------- */

    public function test_a_failed_email_leaves_the_invoice_issued(): void
    {
        Notification::fake();
        $this->connectMail();
        $this->fakeWorkspaceMail('fail');
        $invoice = $this->approvedInvoice();

        $this->sendNow($invoice);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);
        $this->assertSame('INV-2026-0001', $invoice->number);

        $delivery = $invoice->delivery()->sole();
        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertSame('Could not connect to the email server. Check the host and port.', $delivery->error);
        $this->assertStringNotContainsString('hunter2', (string) $delivery->error);
        $this->assertSame(1, $this->auditCount(AuditAction::INVOICE_DELIVERY_FAILED));
        $this->assertStringNotContainsString('hunter2', AuditLog::query()->get()->toJson());
        Notification::assertNothingSent();
    }

    public function test_retry_reuses_the_same_invoice_number_pdf_and_recipients(): void
    {
        $this->connectMail();
        $this->fakeWorkspaceMail('fail');
        $invoice = $this->approvedInvoice();
        $this->sendNow($invoice);

        $invoice->refresh();
        $pdf = Storage::disk('local')->get($invoice->pdf_path);
        $deliveryId = $invoice->delivery()->sole()->id;

        $this->smtp->mode = 'ok';
        $this->retry($invoice)->assertSessionHasNoErrors()->assertSessionHas('success', 'Sending the invoice again.');

        $delivery = InvoiceDelivery::query()->sole();
        $this->assertSame($deliveryId, $delivery->id, 'no second delivery row');
        $this->assertSame(DeliveryStatus::Sent, $delivery->status);
        $this->assertSame(2, $delivery->attempts);
        $this->assertSame('INV-2026-0001', $invoice->fresh()->number);
        $this->assertSame($pdf, Storage::disk('local')->get($invoice->fresh()->pdf_path));
        $this->assertSame($pdf, $this->smtp->sent[0]->getAttachments()[0]->getBody());
        $this->assertSame('billing@rocketflood.com', $this->smtp->sent[0]->getTo()[0]->getAddress());
        $this->assertSame(1, $this->auditCount(AuditAction::INVOICE_DELIVERY_RETRIED));
    }

    public function test_repeating_a_retry_sends_once(): void
    {
        $this->connectMail();
        $this->fakeWorkspaceMail('fail');
        $invoice = $this->approvedInvoice();
        $this->sendNow($invoice);

        $this->smtp->mode = 'ok';
        $this->retry($invoice);
        $this->retry($invoice);

        $this->assertCount(1, $this->smtp->sent);
        $this->assertSame(2, $invoice->delivery()->sole()->attempts);
        $this->assertSame(1, $this->auditCount(AuditAction::INVOICE_DELIVERY_RETRIED));
    }

    public function test_an_ambiguous_failure_needs_the_owner_to_confirm_a_retry(): void
    {
        $this->connectMail();
        $this->fakeWorkspaceMail('ambiguous');
        $invoice = $this->approvedInvoice();

        $this->sendNow($invoice);
        $delivery = $invoice->delivery()->sole();
        $this->assertSame(DeliveryStatus::NeedsAttention, $delivery->status);
        $this->assertSame(1, $this->auditCount(AuditAction::INVOICE_DELIVERY_UNCERTAIN));

        $this->dispatcher();
        $this->assertSame(1, $delivery->fresh()->attempts, 'never retried automatically');

        $this->smtp->mode = 'ok';
        $this->retry($invoice)->assertSessionHas('error');
        $this->assertCount(0, $this->smtp->sent);

        $this->retry($invoice, confirmed: true)->assertSessionHasNoErrors();
        $this->assertCount(1, $this->smtp->sent);
        $this->assertSame(DeliveryStatus::Sent, $delivery->fresh()->status);
    }

    public function test_an_unexpected_error_fails_safely(): void
    {
        $this->connectMail();
        $this->fakeWorkspaceMail('explode');
        $invoice = $this->approvedInvoice();

        $this->sendNow($invoice);

        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
        $this->assertSame(DeliveryStatus::Failed, $invoice->delivery()->sole()->status);
    }

    /* ---- Pages and access ---------------------------------------------- */

    public function test_the_invoice_page_shows_delivery_without_credentials(): void
    {
        $this->connectMail();
        $invoice = $this->approvedInvoice();

        $this->actingAs($this->owner)
            ->get(route('workspace.invoices.show', [$this->workspace, $invoice]))
            ->assertInertia(fn (Assert $page) => $page->where('mailConnected', true)->where('canSend', true)->where('invoice.delivery', null));

        $this->sendNow($invoice);

        $response = $this->actingAs($this->owner)
            ->get(route('workspace.invoices.show', [$this->workspace, $invoice]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('invoice.delivery.status', 'sent')
                ->where('invoice.delivery.recipient', 'billing@rocketflood.com')
                ->where('invoice.delivery.cc', ['ops@rocketflood.com'])
                ->where('invoice.summary.delivery_status', 'sent'));

        $this->assertStringNotContainsString('super-secret-smtp-pass', $response->getContent());
        $this->assertStringNotContainsString('smtp.example.com', $response->getContent());

        $this->actingAs($this->owner)
            ->get(route('workspace.invoices.index', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('invoices.0.delivery_status', 'sent'));
    }

    /**
     * @return array<string, array{0: UserRole}>
     */
    public static function nonOwners(): array
    {
        return ['admin' => [UserRole::ADMIN], 'member' => [UserRole::MEMBER], 'guest client' => [UserRole::CLIENT]];
    }

    #[DataProvider('nonOwners')]
    public function test_only_the_owner_can_send_or_retry(UserRole $role): void
    {
        $this->connectMail();
        $invoice = $this->approvedInvoice();
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);

        $this->actingAs($user)->post(route('workspace.invoices.send-now', [$this->workspace, $invoice]))->assertForbidden();
        $this->actingAs($user)->post(route('workspace.invoices.delivery.retry', [$this->workspace, $invoice]))->assertForbidden();

        $this->assertSame(InvoiceStatus::Approved, $invoice->fresh()->status);
        $this->assertCount(0, $this->smtp->sent);
    }

    public function test_other_workspaces_get_not_found(): void
    {
        $this->connectMail();
        $invoice = $this->approvedInvoice();
        $outsider = User::factory()->create();
        Workspace::factory()->ownedBy($outsider)->create();

        $this->actingAs($outsider)->post(route('workspace.invoices.send-now', [$this->workspace, $invoice]))->assertNotFound();
        $this->actingAs($outsider)->post(route('workspace.invoices.delivery.retry', [$this->workspace, $invoice]))->assertNotFound();

        $this->assertCount(0, $this->smtp->sent);
    }
}
