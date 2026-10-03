<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Billing\Actions\ApproveInvoiceAction;
use App\Modules\Billing\Actions\GenerateInvoiceFromPlanAction;
use App\Modules\Billing\Actions\IssueInvoiceAction;
use App\Modules\Billing\Actions\UpdateInvoicingLogoAction;
use App\Modules\Billing\Data\InvoiceStatus;
use App\Modules\Billing\Exceptions\InvoiceException;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoicingProfile;
use App\Modules\Billing\Support\BillingSchedule;
use App\Modules\Billing\Support\InvoicePdfRenderer;
use App\Modules\Billing\Support\InvoiceRecalculator;
use App\Modules\Billing\Support\OfficialInvoicePdf;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Billing\Concerns\BuildsRocketFlood;
use Tests\TestCase;

/**
 * Sender details, issuing and the invoice document (HTML and PDF), always
 * built from the invoice's own snapshot.
 */
final class InvoiceDocumentTest extends TestCase
{
    use BuildsRocketFlood, RefreshDatabase;

    private BillingPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Carbon::setTestNow('2026-10-03 10:00:00');
        $this->setUpRocketFlood();
        $this->plan = $this->devAndOffice();
    }

    private function invoice(): Invoice
    {
        return app(GenerateInvoiceFromPlanAction::class)->handle(
            $this->plan,
            app(BillingSchedule::class)->latestDueCycle($this->plan, CarbonImmutable::parse('2026-10-03')),
            $this->owner,
        );
    }

    private function approved(?Invoice $invoice = null): Invoice
    {
        $invoice ??= $this->invoice();

        return app(ApproveInvoiceAction::class)->handle($invoice, $invoice->fresh()->version, $this->owner);
    }

    private function issued(): Invoice
    {
        return app(IssueInvoiceAction::class)->handle($this->approved(), null, sendNow: true);
    }

    private function setLogo(int $width): string
    {
        app(UpdateInvoicingLogoAction::class)->handle(
            InvoicingProfile::query()->sole(),
            UploadedFile::fake()->image('logo.png', $width, 40)->getContent(),
            $this->owner,
        );

        return InvoicingProfile::query()->sole()->logo_path;
    }

    private function download(Invoice $invoice, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)->get(route('workspace.invoices.pdf', [$this->workspace, $invoice]));
    }

    /* ---- Sender snapshot ---------------------------------------------------- */

    public function test_new_invoices_snapshot_the_sender_including_its_logo(): void
    {
        $logo = $this->setLogo(120);

        $invoice = $this->invoice();

        $this->assertSame('SprintSync QA', $invoice->bill_from['business_name']);
        $this->assertSame('billing@example.com', $invoice->bill_from['billing_email']);
        $this->assertSame('123 Test Street', $invoice->bill_from['address_line1']);
        $this->assertSame('Pakistan', $invoice->bill_from['country']);
        $this->assertSame('TEST-123', $invoice->bill_from['tax_id']);
        $this->assertSame($logo, $invoice->bill_from['logo_path']);
    }

    public function test_later_settings_and_logo_changes_do_not_reach_existing_invoices(): void
    {
        $oldLogo = $this->setLogo(120);
        $invoice = $this->invoice();
        $oldLogoBytes = Storage::disk('local')->get($oldLogo);

        $this->invoicingDetails(['business_name' => 'Renamed Ltd', 'tax_id' => 'NEW-999']);
        $newLogo = $this->setLogo(260);

        $fresh = $invoice->fresh();
        $this->assertSame('SprintSync QA', $fresh->bill_from['business_name']);
        $this->assertSame('TEST-123', $fresh->bill_from['tax_id']);
        $this->assertSame($oldLogo, $fresh->bill_from['logo_path']);
        $this->assertNotSame($oldLogo, $newLogo);
        Storage::disk('local')->assertExists($oldLogo);

        $this->actingAs($this->owner)
            ->get(route('workspace.invoices.logo', [$this->workspace, $invoice]))
            ->assertOk()
            ->assertStreamedContent($oldLogoBytes);
    }

    public function test_a_draft_can_explicitly_take_the_current_details(): void
    {
        $invoice = $this->invoice();
        $this->invoicingDetails(['business_name' => 'Renamed Ltd']);

        $this->actingAs($this->owner)
            ->post(route('workspace.invoices.sender.refresh', [$this->workspace, $invoice]))
            ->assertSessionHas('success', 'This invoice now uses your current invoicing details.');

        $this->assertSame('Renamed Ltd', $invoice->fresh()->bill_from['business_name']);

        $approved = $this->approved($invoice);
        $this->invoicingDetails(['business_name' => 'Too late Ltd']);

        $this->actingAs($this->owner)
            ->withHeader('X-Inertia', 'true')
            ->post(route('workspace.invoices.sender.refresh', [$this->workspace, $approved]))
            ->assertSessionHas('error');

        $this->assertSame('Renamed Ltd', $approved->fresh()->bill_from['business_name']);
    }

    public function test_an_invoice_prepared_before_invoicing_details_takes_them_on_approval(): void
    {
        InvoicingProfile::query()->delete();
        $invoice = $this->invoice();
        $this->assertNull($invoice->bill_from);

        try {
            $this->approved($invoice);
            $this->fail('Approving without sender details must be refused.');
        } catch (InvoiceException $exception) {
            $this->assertSame('Complete invoicing details before approving this invoice.', $exception->getMessage());
        }

        $this->assertSame(InvoiceStatus::ReadyToReview, $invoice->fresh()->status);

        $this->invoicingDetails();
        $approved = $this->approved($invoice);

        $this->assertSame(InvoiceStatus::Approved, $approved->status);
        $this->assertSame('SprintSync QA', $approved->bill_from['business_name']);
    }

    /* ---- Issuing ------------------------------------------------------------- */

    public function test_issuing_requires_a_complete_sender(): void
    {
        $approved = $this->approved();
        Invoice::query()->whereKey($approved->id)->update(['bill_from' => json_encode(['business_name' => 'Half done'])]);

        $this->expectExceptionMessage('Complete invoicing details before issuing this invoice.');

        app(IssueInvoiceAction::class)->handle($approved->fresh(), null, sendNow: true);
    }

    public function test_issuing_waits_for_the_cancel_window_unless_sent_now(): void
    {
        $approved = $this->approved();

        try {
            app(IssueInvoiceAction::class)->handle($approved, null);
            $this->fail('Issuing inside the cancel window must be refused.');
        } catch (InvoiceException) {
        }

        $this->assertNull($approved->fresh()->number);

        $this->travel(61)->minutes();
        $issued = app(IssueInvoiceAction::class)->handle($approved->fresh(), null);

        $this->assertSame('INV-2026-0001', $issued->number);
    }

    public function test_the_due_date_follows_the_workspace_calendar(): void
    {
        $this->workspace->forceFill(['timezone' => 'Asia/Karachi'])->save();
        $approved = $this->approved();

        // 21:00 UTC on Nov 2 is already Nov 3 in Lahore.
        Carbon::setTestNow('2026-11-02 21:00:00');
        $issued = app(IssueInvoiceAction::class)->handle($approved, null);

        $this->assertSame('2026-11-03', $issued->issue_date->toDateString());
        $this->assertSame('2026-11-18', $issued->due_date->toDateString());
    }

    public function test_the_issue_command_is_for_development_only(): void
    {
        $approved = $this->approved();

        $this->artisan('invoices:issue', ['invoice' => $approved->public_id])->assertFailed();
        $this->assertNull($approved->fresh()->number, 'still inside the cancel window');

        $this->artisan('invoices:issue', ['invoice' => $approved->public_id, '--now' => true])
            ->expectsOutput('Issued INV-2026-0001 (no email was sent).')
            ->assertSuccessful();

        $this->app['env'] = 'production';
        $this->artisan('invoices:issue', ['invoice' => $approved->public_id, '--now' => true])->assertFailed();
    }

    /* ---- The document -------------------------------------------------------- */

    public function test_the_draft_document_says_draft_and_has_no_number(): void
    {
        $html = app(InvoicePdfRenderer::class)->html($this->invoice()->load(['lines', 'adjustments']));

        $this->assertStringContainsString('DRAFT', $html);
        $this->assertStringNotContainsString('INV-', $html);
        foreach (['SprintSync QA', '123 Test Street', 'TEST-123', 'RocketFlood', 'billing@rocketflood.com', 'Ali Hassan', 'Developer', '$2,000.00', 'Office Rent', 'Deel fee 2%', '$99.20', '$4,960.00', '$5,059.20', 'September 1–30, 2026'] as $text) {
            $this->assertStringContainsString(e($text), $html, "missing {$text}");
        }
    }

    public function test_the_issued_document_has_its_number_and_dates_and_no_draft_mark(): void
    {
        $html = app(InvoicePdfRenderer::class)->html($this->issued()->load(['lines', 'adjustments']));

        $this->assertStringNotContainsString('DRAFT', $html);
        $this->assertStringNotContainsString('Draft', $html);
        $this->assertStringContainsString('INV-2026-0001', $html);
        $this->assertStringContainsString('Oct 3, 2026', $html);
        $this->assertStringContainsString('Oct 18, 2026', $html);
        $this->assertStringContainsString('$5,059.20', $html);
    }

    public function test_hourly_documents_show_hours_and_rates(): void
    {
        $csr = $this->csrTeam();
        $invoice = app(GenerateInvoiceFromPlanAction::class)->handle($csr, app(BillingSchedule::class)->latestDueCycle($csr, CarbonImmutable::parse('2026-10-03')), null);
        $invoice->lines()->update(['quantity_centi' => 16000]);
        $invoice = $invoice->fresh();
        app(InvoiceRecalculator::class)->recalculate($invoice);

        $html = app(InvoicePdfRenderer::class)->html($invoice);

        $this->assertStringContainsString('Hours', $html);
        $this->assertStringContainsString('160.00', $html);
        $this->assertStringContainsString('$5.55', $html);
        $this->assertStringContainsString('$888.00', $html);
    }

    public function test_the_owner_downloads_a_draft_pdf(): void
    {
        $response = $this->download($this->invoice())
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringContainsString('attachment; filename=Draft-RocketFlood-September-2026.pdf', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertSame([], Storage::disk('local')->allFiles(), 'drafts are rendered on demand, never stored');
    }

    public function test_the_official_pdf_is_stored_once_and_reused(): void
    {
        $invoice = $this->issued();

        $path = app(OfficialInvoicePdf::class)->path($invoice);
        $this->assertSame($path, $invoice->pdf_path);
        $this->assertSame("workspaces/{$this->workspace->id}/invoices/{$invoice->public_id}/official.pdf", $path);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($path));

        $first = $this->download($invoice)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('filename=INV-2026-0001-RocketFlood.pdf', $first->headers->get('Content-Disposition'));

        // Prove the stored document is served as-is, never regenerated.
        Storage::disk('local')->put($path, '%PDF-official-bytes');
        $this->plan->lines()->update(['unit_price_minor' => 1]);
        $this->team['Ali Hassan']->forceFill(['name' => 'Someone else'])->save();
        $this->rocketFlood->forceFill(['name' => 'Renamed client', 'billing_email' => 'new@client.com'])->save();
        $this->invoicingDetails(['business_name' => 'Renamed Ltd']);
        $this->setLogo(300);

        $this->assertSame('%PDF-official-bytes', $this->download($invoice)->streamedContent());
        $this->assertSame('%PDF-official-bytes', $this->download($invoice)->streamedContent());
        $this->assertCount(1, Storage::disk('local')->files("workspaces/{$this->workspace->id}/invoices/{$invoice->public_id}"));

        $html = app(InvoicePdfRenderer::class)->html($invoice->fresh()->load(['lines', 'adjustments']));
        $this->assertStringContainsString('SprintSync QA', $html);
        $this->assertStringContainsString('RocketFlood', $html);
        $this->assertStringNotContainsString('Renamed', $html);
    }

    public function test_a_missing_official_pdf_is_written_on_the_next_download(): void
    {
        $invoice = $this->issued();

        // As if the process died after issuing but before the PDF was stored.
        Storage::disk('local')->delete($invoice->pdf_path);
        Invoice::query()->whereKey($invoice->id)->update(['pdf_path' => null]);

        $this->download($invoice->fresh())->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->assertNotNull($invoice->fresh()->pdf_path);
        Storage::disk('local')->assertExists($invoice->fresh()->pdf_path);
        $this->assertSame(InvoiceStatus::Issued, $invoice->fresh()->status);
        $this->assertSame('INV-2026-0001', $invoice->fresh()->number);
    }

    public function test_reissuing_returns_the_same_invoice_and_document(): void
    {
        $invoice = $this->issued();
        $bytes = Storage::disk('local')->get($invoice->pdf_path);

        $again = app(IssueInvoiceAction::class)->handle($invoice, null, sendNow: true);

        $this->assertSame('INV-2026-0001', $again->number);
        $this->assertSame($bytes, Storage::disk('local')->get($again->pdf_path));
    }

    /* ---- Access ----------------------------------------------------------- */

    /**
     * @return array<string, array{0: UserRole}>
     */
    public static function nonOwners(): array
    {
        return ['admin' => [UserRole::ADMIN], 'member' => [UserRole::MEMBER], 'guest client' => [UserRole::CLIENT]];
    }

    #[DataProvider('nonOwners')]
    public function test_only_the_owner_can_download_or_change_the_document(UserRole $role): void
    {
        $this->setLogo(120);
        $invoice = $this->invoice();
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);

        $this->download($invoice, $user)->assertForbidden();
        $this->actingAs($user)->get(route('workspace.invoices.logo', [$this->workspace, $invoice]))->assertForbidden();
        $this->actingAs($user)->post(route('workspace.invoices.sender.refresh', [$this->workspace, $invoice]))->assertForbidden();
    }

    public function test_other_workspaces_and_numeric_ids_get_not_found(): void
    {
        $invoice = $this->invoice();
        $outsider = User::factory()->create();
        $theirs = Workspace::factory()->ownedBy($outsider)->create();

        $this->download($invoice, $outsider)->assertNotFound();
        $this->actingAs($outsider)->get(route('workspace.invoices.pdf', [$theirs, $invoice]))->assertNotFound();
        $this->actingAs($this->owner)->get("/{$this->workspace->slug}/invoices/{$invoice->id}/pdf")->assertNotFound();
    }
}
