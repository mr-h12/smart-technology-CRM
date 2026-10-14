import { apiDownload } from '@/api';

/**
 * §17's one file route, and it belongs to **Storage**, not to any module.
 *
 * `GET /api/v1/files/{id}/download` is registered once, outside every module
 * prefix, and authorises through the parent entity (`D-38`,
 * `DownloadFile::forActor()`). Putting it inside `supplier-quotations.ts` —
 * whose docblock says "Module 6's five routes, and nothing else" — would make
 * the next module that needs a file copy it, which is how one shared route ends
 * up written down four times.
 *
 * There is deliberately **no list call here**: the API has exactly one `/files`
 * route (`grep -c "/files" routes/api.php` → 1), so nothing can ask which files
 * an entity has. That gap is on the debt register, not something this file can
 * paper over.
 */
/**
 * A-24: the file picker offers what Storage accepts — `AllowedFileType`'s six
 * extensions. A hint, not the check: the server reads the true type (§17).
 */
export const FILE_ACCEPT = '.pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx';

/** D-116: what a browser draws in the viewer. DOCX/XLSX stay download-only. */
export const VIEWABLE_TYPES: readonly string[] = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

/**
 * The credential is a header, so the file arrives as bytes in memory rather
 * than as a navigation. An object URL is what gives those bytes an address; it
 * pins the blob for the life of the document, so the caller revokes it.
 */
export async function fetchFileUrl(fileId: string): Promise<string> {
    return URL.createObjectURL(await apiDownload(`/files/${fileId}/download`));
}

export async function downloadFile(fileId: string, filename: string): Promise<void> {
    // Revoked as soon as the save gesture has it.
    const url = await fetchFileUrl(fileId);

    try {
        const anchor = document.createElement('a');

        anchor.href = url;
        // The server's own `Content-Disposition` carries the name, but reading
        // it back means parsing RFC 5987's `filename*` for the Arabic case.
        // Every caller already holds `original_name` from the upload payload,
        // so the name is passed in rather than re-derived.
        anchor.download = filename;
        document.body.append(anchor);
        anchor.click();
        anchor.remove();
    } finally {
        URL.revokeObjectURL(url);
    }
}
