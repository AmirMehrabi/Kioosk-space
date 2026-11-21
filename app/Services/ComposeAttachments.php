<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ComposeAttachments
{
    private const LIMIT = 16 * 1024 * 1024;

    public function store(User $user, UploadedFile $file): array
    {
        return Cache::lock('compose-upload:'.$user->id, 20)->block(5, function () use ($user, $file): array {
            $disk = Storage::disk('local');
            $directory = $this->directory($user);
            $stored = $disk->files($directory);
            $size = array_sum(array_map(fn ($path) => str_ends_with($path, '.bin') ? $disk->size($path) : 0, $stored));

            abort_if(count($stored) >= 20 || $size + $file->getSize() > self::LIMIT, 422, 'حداکثر ۱۰ پیوست با مجموع ۱۶ مگابایت مجاز است.');

            $id = (string) Str::uuid();
            $name = Str::limit(preg_replace('/[\x00-\x1F\x7F]/u', '_', $file->getClientOriginalName()) ?: 'attachment', 180, '');
            $metadata = ['id' => $id, 'name' => $name, 'size' => $file->getSize(), 'type' => $file->getMimeType() ?: 'application/octet-stream'];

            abort_unless($disk->putFileAs($directory, $file, "$id.bin"), 503, 'ذخیرهٔ پیوست ممکن نشد.');
            if (! $disk->put("$directory/$id.json", json_encode($metadata, JSON_THROW_ON_ERROR))) {
                $disk->delete("$directory/$id.bin");
                abort(503, 'ذخیرهٔ پیوست ممکن نشد.');
            }

            return $metadata;
        });
    }

    public function metadata(User $user, array $ids): array
    {
        $disk = Storage::disk('local');
        $directory = $this->directory($user);

        return array_map(function (string $id) use ($disk, $directory): array {
            abort_unless(Str::isUuid($id) && $disk->exists("$directory/$id.json") && $disk->exists("$directory/$id.bin"), 422, 'یکی از پیوست‌ها دیگر در دسترس نیست.');

            return json_decode($disk->get("$directory/$id.json"), true, flags: JSON_THROW_ON_ERROR);
        }, $ids);
    }

    public function upload(User $user, array $ids): array
    {
        $files = $this->metadata($user, $ids);
        $base = rtrim(config('stalwart.url'), '/');
        $http = Http::withBasicAuth($user->mailbox_address, $user->mail_secret)->withoutRedirecting()->timeout(20);
        $session = $http->get($base.'/.well-known/jmap');
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

    private function directory(User $user): string
    {
        return 'compose/'.$user->id;
    }
}
