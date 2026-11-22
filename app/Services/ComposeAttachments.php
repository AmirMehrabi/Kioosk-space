<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ComposeAttachments
{
    private const LIMIT = 16 * 1024 * 1024;

    public function store(User $user, UploadedFile $file, string $draft, array $previous): array
    {
        $disk = Storage::disk('local');
        $directory = $this->directory($user);
        $stored = $this->draftFiles($user, $draft, $previous);
        $size = array_sum(array_column($stored, 'size'));
        abort_if(count($stored) >= 10 || $size + $file->getSize() > self::LIMIT, 422, 'حداکثر ۱۰ پیوست با مجموع ۱۶ مگابایت مجاز است.');

        $id = (string) Str::uuid();
        $name = Str::limit(preg_replace('/[\x00-\x1F\x7F]/u', '_', $file->getClientOriginalName()) ?: 'attachment', 180, '');
        $metadata = ['id' => $id, 'draft' => $draft, 'name' => $name, 'size' => $file->getSize(), 'type' => $file->getMimeType() ?: 'application/octet-stream'];

        abort_unless($disk->putFileAs($directory, $file, "$id.bin"), 503, 'ذخیرهٔ پیوست ممکن نشد.');
        if (! $disk->put("$directory/$id.json", json_encode($metadata, JSON_THROW_ON_ERROR))) {
            $disk->delete("$directory/$id.bin");
            abort(503, 'ذخیرهٔ پیوست ممکن نشد.');
        }

        return $metadata;
    }

    public function available(User $user, array $ids, string $draft, array $previous = []): array
    {
        $disk = Storage::disk('local');
        $directory = $this->directory($user);

        $files = [];
        foreach ($ids as $id) {
            if (! Str::isUuid($id) || ! $disk->exists("$directory/$id.json") || ! $disk->exists("$directory/$id.bin")) {
                continue;
            }
            $metadata = json_decode($disk->get("$directory/$id.json"), true, flags: JSON_THROW_ON_ERROR);
            if (($metadata['draft'] ?? null) === $draft || (! isset($metadata['draft']) && in_array($id, $previous, true))) {
                $files[] = $metadata;
            }
        }

        return $files;
    }

    public function metadata(User $user, array $ids, string $draft, array $previous): array
    {
        $files = $this->available($user, $ids, $draft, $previous);
        abort_unless(count($files) === count($ids), 422, 'یکی از پیوست‌ها دیگر در دسترس نیست.');

        return $files;
    }

    public function upload(User $user, array $ids, string $draft): array
    {
        $files = $this->metadata($user, $ids, $draft, $ids);
        $base = rtrim(config('stalwart.url'), '/');
        $http = Http::withBasicAuth($user->mailbox_address, $user->mail_secret)->withoutRedirecting()->timeout(20);
        $session = $http->get($base.'/jmap/session');
        $template = $session->json('uploadUrl');
        abort_unless($session->successful() && is_string($template) && str_starts_with($template, $base.'/'), 502, 'نشانی بارگذاری پیوست در دسترس نیست.');
        $url = str_replace('{accountId}', rawurlencode($user->mail_account_id), $template);
        $directory = $this->directory($user);

        return array_map(function (array $file) use ($http, $url, $directory): array {
            $binary = Storage::disk('local')->get($directory.'/'.$file['id'].'.bin');
            abort_unless(is_string($binary), 422, 'پیوست در دسترس نیست.');
            $response = $http->withBody($binary, $file['type'])->post($url);
            $blob = $response->json('blobId');
            abort_unless($response->successful() && is_string($blob), 502, 'بارگذاری پیوست روی سرور نامه انجام نشد.');

            return ['blobId' => $blob, 'type' => $file['type'], 'name' => $file['name'], 'disposition' => 'attachment'];
        }, $files);
    }

    public function delete(User $user, string $id): void
    {
        if (! Str::isUuid($id)) {
            return;
        }

        $directory = $this->directory($user);
        Storage::disk('local')->delete(["$directory/$id.bin", "$directory/$id.json"]);
    }

    public function discard(User $user, string $draft, array $previous): void
    {
        foreach ($this->draftFiles($user, $draft, $previous) as $file) {
            $this->delete($user, $file['id']);
        }
    }

    private function draftFiles(User $user, string $draft, array $previous): array
    {
        $paths = Storage::disk('local')->files($this->directory($user));
        $ids = array_map(fn (string $path): string => basename($path, '.json'),
            array_filter($paths, fn (string $path): bool => str_ends_with($path, '.json')));

        return $this->available($user, $ids, $draft, $previous);
    }

    private function directory(User $user): string
    {
        return 'compose/'.$user->id;
    }
}
