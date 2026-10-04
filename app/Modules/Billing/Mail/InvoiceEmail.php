<?php

declare(strict_types=1);

namespace App\Modules\Billing\Mail;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoiceDelivery;
use App\Modules\Billing\Support\InvoicePdfRenderer;
use App\Modules\Billing\Support\MoneyFormatter;
use App\Modules\Billing\Support\OfficialInvoicePdf;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mime\Email;

/**
 * The client-facing invoice email: who, what and from whom all come from the
 * delivery snapshot and the invoice's own snapshot; the official PDF is the
 * attachment and the authoritative document. Never queued itself: it is sent
 * inside the delivery job through the workspace's own server.
 */
final class InvoiceEmail extends Mailable
{
    public function __construct(
        private readonly Invoice $invoice,
        private readonly InvoiceDelivery $delivery,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->delivery->from_email, $this->delivery->from_name),
            to: [$this->delivery->recipient],
            cc: $this->delivery->cc ?? [],
            replyTo: $this->delivery->reply_to_email ? [$this->delivery->reply_to_email] : [],
            subject: $this->delivery->subject,
            using: [fn (Email $message) => $message->getHeaders()->addIdHeader('Message-ID', $this->delivery->message_id)],
        );
    }

    public function content(): Content
    {
        $invoice = $this->invoice;

        return new Content(
            view: 'emails.billing.invoice',
            text: 'emails.billing.invoice-text',
            with: [
                'clientName' => $invoice->bill_to['name'],
                'number' => $invoice->number,
                'total' => MoneyFormatter::format($invoice->total_minor, $invoice->currency),
                'dueDate' => $invoice->due_date?->format('F j, Y'),
                'senderName' => $invoice->bill_from['business_name'] ?? $this->delivery->from_name,
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk(OfficialInvoicePdf::DISK, (string) $this->invoice->pdf_path)
                ->as(app(InvoicePdfRenderer::class)->filename($this->invoice))
                ->withMime('application/pdf'),
        ];
    }
}
