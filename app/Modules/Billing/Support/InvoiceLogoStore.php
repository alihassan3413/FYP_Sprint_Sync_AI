<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\InvoicingProfile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Stores workspace invoice logos content-addressed:
 * workspaces/{id}/invoicing/logos/{sha256}.png on the private disk.
 *
 * The upload is decoded and re-encoded as PNG first, which drops metadata and
 * anything that is not image data, and gives the PDF renderer one format it
 * always supports. A given path therefore always holds the same bytes: an
 * invoice that snapshotted it keeps its logo for good, whatever the workspace
 * uploads later. A logo file is only deleted once no profile and no invoice
 * refers to it.
 */
final class InvoiceLogoStore
{
    public function store(int $workspaceId, string $uploadedBytes): string
    {
        $image = @imagecreatefromstring($uploadedBytes);

        if ($image === false) {
            throw new InvalidArgumentException('The logo could not be read as an image.');
        }

        imagesavealpha($image, true);
        ob_start();
        imagepng($image, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        $path = "workspaces/{$workspaceId}/invoicing/logos/".hash('sha256', $png).'.png';
        $disk = Storage::disk(InvoicingProfile::LOGO_DISK);

        if (! $disk->exists($path)) {
            $disk->put($path, $png);
        }

        return $path;
    }

    public function deleteIfUnused(int $workspaceId, ?string $path): void
    {
        if ($path === null) {
            return;
        }

        $stillUsed = InvoicingProfile::query()->where('logo_path', $path)->exists()
            || Invoice::query()->where('workspace_id', $workspaceId)->where('bill_from->logo_path', $path)->exists();

        if (! $stillUsed) {
            Storage::disk(InvoicingProfile::LOGO_DISK)->delete($path);
        }
    }

    /** The image as a data URI for the PDF (dompdf never fetches files or URLs itself). */
    public function dataUri(?string $path): ?string
    {
        if ($path === null || ! Storage::disk(InvoicingProfile::LOGO_DISK)->exists($path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) Storage::disk(InvoicingProfile::LOGO_DISK)->get($path));
    }
}
