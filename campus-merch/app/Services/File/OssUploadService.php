<?php

namespace App\Services\File;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class OssUploadService
{
    /**
     * 允许的 MIME 类型白名单（设计稿/封面图）
     */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'application/pdf',
        'application/illustrator', // .ai
        'image/vnd.adobe.photoshop', // .psd
    ];

    /**
     * 允许的文件扩展名
     */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf', 'ai', 'psd'];

    /**
     * 单文件最大大小：15MB (单位: KB)
     */
    private const MAX_FILE_SIZE_KB = 15360;

    /**
     * 上传文件到 OSS / 本地存储
     *
     * @param UploadedFile $file 上传的文件
     * @param string $directory 存储目录前缀，如 merch-designs/{order_id}
     * @return array{path: string, url: string, name: string, mime: string, size: int, ext: string}
     * @throws \InvalidArgumentException 校验失败时抛出
     */
    public function upload(UploadedFile $file, string $directory): array
    {
        $this->validateMimeType($file);
        $this->validateFileSize($file);

        $extension = strtolower($file->getClientOriginalExtension());
        $originalName = $file->getClientOriginalName();
        $this->validateExtension($extension);

        // 防目录穿越：清理目录名
        $directory = $this->sanitizePath($directory);

        // 生成安全文件名：时间戳_uuid.扩展名
        $fileName = time() . '_' . Str::uuid()->toString() . '.' . $extension;
        $storagePath = "{$directory}/{$fileName}";

        // 存储到磁盘（local 或 s3/oss）
        $disk = Storage::disk(config('filesystem.disk', 'local'));
        $disk->put($storagePath, $file->getContent());

        return [
            'path' => $storagePath,
            'url' => $this->resolveUrl($disk, $storagePath),
            'name' => $originalName,
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'ext' => $extension,
        ];
    }

    /**
     * 获取带时效的临时访问链接（OSS）或本地路径
     */
    public function getTemporaryUrl(string $storagePath, int $expiresMinutes = 120): string
    {
        $disk = Storage::disk(config('filesystem.disk', 'local'));

        if ($disk->getDriver()->getAdapter() instanceof \League\Flysystem\AwsS3V3\AwsS3Adapter) {
            return $disk->temporaryUrl($storagePath, now()->addMinutes($expiresMinutes));
        }

        // 本地存储直接返回 URL
        return $disk->url($storagePath);
    }

    /**
     * 删除存储中的物理文件
     */
    public function delete(string $storagePath): bool
    {
        $disk = Storage::disk(config('filesystem.disk', 'local'));

        if ($disk->exists($storagePath)) {
            return $disk->delete($storagePath);
        }

        return false;
    }

    /**
     * 校验 MIME 类型（双重校验）
     */
    private function validateMimeType(UploadedFile $file): void
    {
        $mime = $file->getMimeType();

        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException(
                "文件格式不支持。允许的类型：" . implode(', ', self::ALLOWED_EXTENSIONS)
            );
        }
    }

    /**
     * 校验文件大小
     */
    private function validateFileSize(UploadedFile $file): void
    {
        $sizeKb = $file->getSize() / 1024;

        if ($sizeKb > self::MAX_FILE_SIZE_KB) {
            throw new \InvalidArgumentException(
                "文件大小超出限制，最大允许 " . (self::MAX_FILE_SIZE_KB / 1024) . "MB"
            );
        }
    }

    /**
     * 校验扩展名
     */
    private function validateExtension(string $extension): void
    {
        if (! in_array(strtolower($extension), self::ALLOWED_EXTENSIONS, true)) {
            throw new \InvalidArgumentException(
                "文件扩展名不被允许。允许的扩展名：" . implode(', ', self::ALLOWED_EXTENSIONS)
            );
        }
    }

    /**
     * 清理路径，防止目录穿越攻击
     */
    private function sanitizePath(string $path): string
    {
        $clean = str_replace(['..', '\\'], ['', '/'], $path);
        $clean = preg_replace('#/+#', '/', trim($clean, '/'));

        if ($clean !== $path) {
            Log::warning('File path sanitized', ['original' => $path, 'cleaned' => $clean]);
        }

        return $clean;
    }

    /**
     * 解析文件的访问 URL
     */
    private function resolveUrl($disk, string $storagePath): string
    {
        $adapter = $disk->getDriver()->getAdapter();

        // S3/OSS adapter
        if ($adapter instanceof \League\Flysystem\AwsS3V3\AwsS3Adapter) {
            return $disk->url($storagePath);
        }

        // Local disk - 返回可访问 URL 或相对路径
        if (config('filesystem.disk') === 'public') {
            return $disk->url($storagePath);
        }

        // local/private 磁盘通过临时 URL 访问（或代理下载）
        return '/api/files/' . urlencode($storagePath);
    }
}
