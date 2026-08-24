<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * The one place a file leaves the request and lands on disk.
 *
 * Before item 9 nothing in the app wrote an upload. Three columns were already
 * *read* as though something did — `products.image`, `media.file_name`,
 * `business.logo` — and `config/constants.php` already declared where each of
 * them lives. So this class does not invent a convention; it implements the one
 * the readers assumed.
 *
 * Three decisions are worth stating, because each has a plausible alternative:
 *
 * 1. **`public/uploads`, not the `storage/app/public` symlink.** Laravel's
 *    default would be `Storage::disk('public')`, and for a normal app it is the
 *    better answer. Here it is the worse one. `Product::getImageUrlAttribute()`
 *    and `Media::getDisplayUrlAttribute()` already build `asset('uploads/…')`
 *    URLs, so a second root would mean two conventions for the same kind of
 *    file. And the first consumer of an upload in this codebase is an invoice
 *    logo rendered by DomPDF, which has no HTTP client: it needs a filesystem
 *    path, and `public_path()` hands it one with no symlink to recreate on every
 *    deploy. `/public/uploads` is gitignored, so nothing lands in the tree.
 *
 * 2. **The stored value is a bare filename, never a path.** `products.image`
 *    and `media.file_name` are both bare, and keeping to that means the upload
 *    root stays a config value: moving it is an edit to `config/constants.php`,
 *    not a data migration over every row that ever stored a logo.
 *
 * 3. **The extension comes from the file's contents, not its name.**
 *    `UploadedFile::extension()` guesses from the detected MIME type, where
 *    `getClientOriginalExtension()` echoes whatever the browser sent. Combined
 *    with the caller's `image` validation rule, a file called `logo.png` that is
 *    actually a PHP script cannot be stored under a name ending in `.png`.
 */
class UploadService
{
    /**
     * Move an uploaded file into the directory named by a `constants.*_path`
     * config key, and return the bare filename to store on the model.
     *
     * `$replacing` is the value currently on the model. When a new file arrives
     * the old one is deleted, so a business that re-uploads its logo twenty
     * times leaves one file behind rather than twenty. Passing null (or the same
     * name) skips the delete.
     *
     * @param  string  $pathKey  key under `constants`, e.g. `business_logo_path`
     */
    public function store(?UploadedFile $file, string $pathKey, ?string $replacing = null): ?string
    {
        if (empty($file) || ! $file->isValid()) {
            return null;
        }

        $directory = $this->directory($pathKey);

        if (! is_dir($directory)) {
            // Recursive, because `uploads/` itself may not exist on a fresh
            // checkout — /public/uploads is gitignored, so it is absent until
            // something writes the first file.
            mkdir($directory, 0755, true);
        }

        $name = $this->fileName($file);

        $file->move($directory, $name);

        if (filled($replacing) && $replacing !== $name) {
            $this->delete($pathKey, $replacing);
        }

        return $name;
    }

    /**
     * Remove a stored file. Silent when it is already gone — a row pointing at a
     * file somebody deleted by hand should still be clearable from the UI.
     */
    public function delete(string $pathKey, ?string $fileName): void
    {
        $path = $this->path($pathKey, $fileName);

        if (filled($path) && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Browser-facing URL, or null when the file is missing.
     *
     * Null rather than a placeholder: a logo is optional, and an invoice with no
     * logo should print without one. Only the screens that *must* show something
     * (the product grid) fall back to a placeholder, and they do it themselves.
     */
    public function url(string $pathKey, ?string $fileName): ?string
    {
        if (empty($this->path($pathKey, $fileName))) {
            return null;
        }

        return asset(config('constants.'.$pathKey).'/'.$fileName);
    }

    /**
     * Absolute filesystem path, or null when the file does not exist.
     *
     * The existence check is the point. DomPDF renders a missing `<img>` as a
     * broken-image glyph in the middle of a customer's invoice, so the template
     * asks for a path and gets null when there is nothing to draw.
     */
    public function path(string $pathKey, ?string $fileName): ?string
    {
        if (blank($fileName)) {
            return null;
        }

        // A stored name is always bare (see the class docblock). Anything with a
        // separator in it did not come from store(), so refuse it rather than
        // let `../../.env` be resolved into a path.
        if (basename($fileName) !== $fileName) {
            return null;
        }

        $path = $this->directory($pathKey).DIRECTORY_SEPARATOR.$fileName;

        return is_file($path) ? $path : null;
    }

    /**
     * Absolute directory for a `constants.*_path` key.
     */
    protected function directory(string $pathKey): string
    {
        $relative = (string) config('constants.'.$pathKey);

        if (blank($relative)) {
            throw new \InvalidArgumentException("Unknown upload path key [{$pathKey}].");
        }

        return public_path($relative);
    }

    /**
     * A collision-proof name that still hints at the original.
     *
     * The timestamp prefix matches what `Media::getDisplayNameAttribute()`
     * already strips back off (`/^\d+_/`), so a file uploaded through here
     * displays the way the existing accessor expects.
     */
    protected function fileName(UploadedFile $file): string
    {
        $base = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));

        if (blank($base)) {
            // A name that slugs to nothing — Arabic-only, or all punctuation.
            $base = 'file';
        }

        $extension = $file->extension() ?: 'bin';

        return now()->timestamp.'_'.Str::limit($base, 40, '').'_'.Str::random(6).'.'.$extension;
    }
}
