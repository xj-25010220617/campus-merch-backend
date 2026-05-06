<?php

/**
 * ============================================================================
 * 【接口1相关】OSS 文件上传基础服务
 * ============================================================================
 *
 * 📌 功能概述：
 *    统一的文件上传服务层，负责处理所有文件上传的安全校验和存储操作。
 *    支持本地存储 和 云端 OSS 存储（通过 Laravel Storage 门面自动切换）。
 *
 * 🔗 对接接口：
 *    - POST /api/orders/{id}/design  （定制稿上传）
 *    - 未来可复用于商品封面图、其他文件上传等场景
 *
 * 💡 核心知识点：
 *    1. MIME 类型校验：防止恶意文件伪装扩展名上传（如 .php 伪装成 .jpg）
 *    2. 目录穿越攻击防护：防止通过 ../ 访问服务器敏感文件
 *    3. UUID 文件名：避免文件名冲突和中文字符乱码问题
 *    4. Laravel Storage 门面：统一抽象的文件系统接口
 *
 * 📖 相关概念：
 *    - OSS：Object Storage Service（对象存储服务），云端的文件存储方案
 *    - MIME：Multipurpose Internet Mail Extensions，文件的"真实类型标识"
 *    - CDN：Content Delivery Network，内容分发网络，加速文件访问
 */

namespace App\Services\File;

use Illuminate\Http\UploadedFile;       // Laravel 的上传文件封装类
use Illuminate\Support\Facades\Log;     // 日志门面，用于记录安全告警等
use Illuminate\Support\Facades\Storage; // 文件存储门面（核心！支持 local/s3/ftp 等）
use Illuminate\Support\Str;             // 字符串工具类，用于生成 UUID

class OssUploadService
{
    /*
     * ──────────────────────────────────────────────
     * 第一部分：安全配置常量
     * ──────────────────────────────────────────────
     *
     * 使用 const 定义常量的好处：
     *   1. 不可修改（安全）
     *   2. 类级别共享（所有实例共用同一套规则）
     *   3. 语义清晰（一看就知道是配置项）
     */

    /**
     * 允许的 MIME 类型白名单（设计稿/封面图）
     *
     * 📖 什么是 MIME 类型？
     *    MIME 是文件的"身份证"，由文件内容决定，而非扩展名。
     *    例如：把 virus.exe 改名为 virus.jpg，
     *         扩展名看起来是 .jpg，但 MIME 仍是 application/octet-stream
     *
     * 🔒 为什么要做 MIME 白名单？
     *    防止攻击者上传恶意文件。仅允许业务需要的文件类型。
     *
     * 📋 当前允许的类型：
     *    - image/jpeg        → .jpg / .jpeg（照片、图片）
     *    - image/png          → .png（透明背景图片、设计稿截图）
     *    - application/pdf    → .pdf（PDF 设计文档）
     *    - application/illustrator → .ai（Adobe Illustrator 原始设计稿）
     *    - image/vnd.adobe.photoshop → .psd（Photoshop 分层源文件）
     */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',                    // JPEG 图片
        'image/png',                     // PNG 图片
        'application/pdf',               // PDF 文档
        'application/illustrator',       // Adobe Illustrator (.ai)
        'image/vnd.adobe.photoshop',     // Adobe Photoshop (.psd)
    ];

    /**
     * 允许的文件扩展名
     *
     * ⚠️ 注意：这是第二道防线！
     *    MIME 校验可能被绕过（某些特殊构造的文件），
     *    所以同时检查扩展名，双重保险。
     *
     * 🔍 扩展名 vs MIME 的区别：
     *    扩展名 = 文件名的后缀（可随意修改）
     *    MIME = 文件内容的真实类型（更可靠）
     *    两者都要校验才安全！
     */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf', 'ai', 'psd'];

    /**
     * 单文件最大大小：15MB (单位: 字节 → 这里用 KB)
     *
     * 💾 为什么限制 15MB？
     *    - 设计稿文件通常在 5-50MB 之间
     *    - 防止恶意用户上传超大文件占满磁盘/带宽
     *    - PHP 默认上传限制通常是 2-8MB，这里适当放宽到 15MB
     *
     * ⚙️ PHP 配置配合：
     *    还需要在 php.ini 中设置：
     *      upload_max_filesize = 20M
     *      post_max_size = 20M
     *      memory_limit = 256M
     */
    private const MAX_FILE_SIZE_KB = 15360; // 15360 KB = 15 MB

    /**
     * 图片最小宽高限制（像素）
     *
     * 📖 为什么需要校验图片宽高？
     *    文档要求"设计稿分辨率建议 ≥ 300DPI"。
     *    由于后端无法直接获取 DPI（DPI 是打印概念，需要结合物理尺寸计算），
     *    我们退而求其次，校验图片的像素宽高是否满足最低要求。
     *
     *    💡 DPI 与像素的关系：
     *      假设一张 10cm × 10cm 的印刷品，300DPI 要求：
     *      10cm ≈ 4英寸 → 4 × 300 = 1200 像素
     *
     *    所以设定最小 300×300 像素，作为设计稿的基本质量门槛。
     *    低于此分辨率的图片印刷出来会模糊不清。
     *
     * ⚠️ 注意：此校验仅对 jpg/jpeg/png 生效！
     *    PDF/AI/PSD 是矢量或多层格式，无法用 getimagesize() 获取像素尺寸，
     *    因此跳过校验，由前端提示即可。
     */
    private const MIN_IMAGE_WIDTH = 300;   // 最小宽度 300 像素
    private const MIN_IMAGE_HEIGHT = 300;  // 最小高度 300 像素

    /**
     * 需要进行宽高校验的 MIME 类型（仅位图格式）
     *
     * 📖 为什么只校验 jpg/png？
     *    - image/jpeg、image/png → 位图，有固定像素尺寸，可以校验
     *    - application/pdf → 矢量/混合格式，getimagesize() 无法读取
     *    - application/illustrator (.ai) → 矢量格式，无像素概念
     *    - image/vnd.adobe.photoshop (.psd) → 多层格式，getimagesize() 不稳定
     */
    private const IMAGE_MIME_TYPES_FOR_DIMENSION_CHECK = [
        'image/jpeg',
        'image/png',
    ];

    /*
     * ──────────────────────────────────────────────
     * 第二部分：核心方法 - upload() 文件上传入口
     * ──────────────────────────────────────────────
     */

    /**
     * 上传文件到 OSS / 本地存储
     *
     * 🔄 完整流程：
     *    1. 校验 MIME 类型（第一道安检）
     *    2. 校验文件大小（第二道安检）
     *    3. 校验扩展名（第三道安检）
     *    4. 校验图片宽高（第四道安检，仅 jpg/png）
     *    5. 清理目录路径（防目录穿越）
     *    6. 生成安全的唯一文件名（UUID）
     *    7. 调用 Storage 写入磁盘
     *    8. 返回完整的文件元信息
     *
     * @param  UploadedFile  $file      Laravel 封装的上传文件对象（从 $request->file() 获得）
     * @param  string        $directory 存储目录前缀，例如 "merch-designs/123"
     *                                   最终路径：merch-designs/123/1740123456_uuid.jpg
     * @return array{path: string, url: string, name: string, mime: string, size: int, ext: string}
     *         返回值是一个关联数组，包含以下字段：
     *           - path:     存储路径（如 merch-designs/123/xxx.jpg）
     *           - url:      可访问的 URL 或相对路径
     *           - name:     用户原始文件名（如 我的设计稿.psd）
     *           - mime:     MIME 类型（如 image/png）
     *           - size:     文件大小（字节）
     *           - ext:      扩展名（如 png）
     *
     * @throws \InvalidArgumentException 当校验失败时抛出此异常（不是 404/500，而是参数错误）
     *
     * 💡 使用示例：
     *    $result = $ossService->upload($request->file('design'), 'designs/1');
     *    echo $result['path'];  // designs/1/1740123456_a1b2c3d4.png
     *    echo $result['url'];   // http(s)://xxx/desigs/1/1740123456_a1b2c3d4.png
     */
    public function upload(UploadedFile $file, string $directory): array
    {
        // ════════════════════════════════════════
        // 步骤1-4：四重安全校验（任何一项不通过直接抛异常）
        // ════════════════════════════════════════
        $this->validateMimeType($file);   // ✅ 校验 MIME 类型（最可靠）
        $this->validateFileSize($file);   // ✅ 校验文件大小
        $extension = strtolower($file->getClientOriginalExtension()); // 获取原始扩展名并转小写
        $originalName = $file->getClientOriginalName();              // 保留原始文件名（用于显示）
        $this->validateExtension($extension); // ✅ 校验扩展名
        $this->validateImageDimensions($file); // ✅ 校验图片宽高（仅 jpg/png）

        // ════════════════════════════════════════
        // 步骤5：清理目录路径（安全防护）
        // ════════════════════════════════════════
        $directory = $this->sanitizePath($directory);

        // ════════════════════════════════════════
        // 步骤6：生成安全的唯一文件名
        // ════════════════════════════════════════
        //
        // ❌ 为什么不用用户的原始文件名？
        //    1. 中文文件名可能导致编码问题（GBK vs UTF-8）
        //    2. 特殊字符（空格、括号等）可能在 URL 中引起问题
        //    3. 同名文件会互相覆盖
        //    4. 可能包含敏感信息（如用户名、日期）
        //
        // ✅ 我们的命名规则：时间戳_UUID.扩展名
        //    例：1740480000_a1b2c3d4-e5f6-7890-abcd-ef1234567890.png
        //
        // 🔍 各部分含义：
        //    time()           → Unix 时间戳，方便按时间排序和定位问题
        //    Str::uuid()      → 全球唯一标识符（UUID v4），保证不会重复
        //    $extension       → 保留原始扩展名，便于识别文件类型
        $fileName = time() . '_' . Str::uuid()->toString() . '.' . $extension;
        $storagePath = "{$directory}/{$fileName}"; // 拼接完整存储路径

        // ════════════════════════════════════════
        // 步骤7：写入存储（核心操作）
        // ════════════════════════════════════════
        //
        // 📦 Storage::disk() 是什么？
        //    Laravel 的文件系统抽象层，通过配置可以切换不同的存储后端：
        //    - disk('local')  → 存到服务器本地 storage/app/private/
        //    - disk('public') → 存到 storage/app/public/（可通过 web 访问）
        //    - disk('oss/s3') → 存到阿里云 OSS / AWS S3 等云存储
        //
        // ⚙️ config('filesystems.default', 'local') 含义：
        //    从 config/filesystems.php 或 .env 读取 FILESYSTEM_DISK 配置，
        //    如果没配置则默认使用 'local'（本地存储）。
        //
        // 📝 put() 方法：
        //    第一个参数：存储路径（相对于磁盘根目录）
        //    第二个参数：文件内容（字符串或二进制数据）
        //    $file->getContent() 获取上传文件的完整二进制内容
        $disk = Storage::disk(config('filesystems.default', 'local'));
        $disk->put($storagePath, $file->getContent());

        // ════════════════════════════════════════
        // 步骤8：返回完整的文件元信息
        // ════════════════════════════════════════
        return [
            'path' => $storagePath,                    // 存储路径（存数据库用）
            'url'  => $this->resolveUrl($disk, $storagePath), // 可访问URL（返回前端用）
            'name' => $originalName,                   // 原始文件名（展示给用户看）
            'mime' => $file->getMimeType(),            // MIME类型（记录用）
            'size' => $file->getSize(),                // 文件大小/字节（记录用）
            'ext'  => $extension,                      // 扩展名（记录用）
        ];
    }

    /*
     * ──────────────────────────────────────────────
     * 第三部分：辅助方法 - getTemporaryUrl() 临时链接
     * ──────────────────────────────────────────────
     */

    /**
     * 获取带时效的临时访问链接（OSS）或本地路径
     *
     * 🎯 使用场景：
     *    文件存在私有存储中（不能公开访问），需要给用户一个临时下载链接。
     *    链接过期后自动失效，安全性更高。
     *
     * 🔑 时效链接原理（以阿里云 OSS 为例）：
     *    服务端用 AccessKeySecret 对 [路径 + 过期时间] 做签名 → 生成带签名的 URL
     *    用户访问时，OSS 验证签名是否有效（未过期 + 签名正确）→ 允许下载
     *
     * ⏰ 参数说明：
     *    $expiresMinutes = 120 表示链接有效期 2 小时
     *
     * @param  string  $storagePath      文件存储路径
     * @param  int     $expiresMinutes   链接有效时长（分钟），默认120分钟=2小时
     * @return string  可访问的 URL
     *
     * 💡 返回值示例：
     *    本地模式：/api/files/merch-designs%2F123%2Fxxx.jpg
     *    OSS模式：https://your-bucket.oss-cn-hangzhou.aliyuncs.com/...?signature=xxx&expires=xxx
     */
    public function getTemporaryUrl(string $storagePath, int $expiresMinutes = 120): string
    {
        $disk = Storage::disk(config('filesystems.default', 'local'));

        /*
         * instanceof 是什么？
         *    PHP 的类型运算符，检查对象是否属于某个类（或其子类）。
         *
         * 这里用来判断当前使用的是不是 S3/OSS 适配器：
         *    - 如果是 → 调用 temporaryUrl() 生成签名临时链接
         *    - 如果不是（本地存储）→ 直接返回普通 URL
         */
        if ($disk->getDriver()->getAdapter() instanceof \League\Flysystem\AwsS3V3\AwsS3Adapter) {
            // 🌐 OSS/S3 模式：生成带签名的临时 URL
            // now()->addMinutes($expiresMinutes) → 当前时间 + N 分钟（Laravel Carbon 辅助函数）
            return $disk->temporaryUrl($storagePath, now()->addMinutes($expiresMinutes));
        }

        // 📁 本地存储模式：直接返回 URL（无时效限制）
        return $disk->url($storagePath);
    }

    /*
     * ──────────────────────────────────────────────
     * 第四部分：辅助方法 - delete() 删除文件
     * ──────────────────────────────────────────────
     */

    /**
     * 删除存储中的物理文件
     *
     * ⚠️ 注意：这只删除物理文件，不会删除数据库中的记录！
     *    通常需要配合 OrderAttachmentService 一起使用（先删DB记录，再删物理文件）
     *
     * @param  string  $storagePath  文件存储路径
     * @return bool    true=删除成功, false=文件不存在
     */
    public function delete(string $storagePath): bool
    {
        $disk = Storage::disk(config('filesystems.default', 'local'));

        // 先判断文件是否存在，避免报错
        if ($disk->exists($storagePath)) {
            return $disk->delete($storagePath); // delete() 返回 true/false
        }

        return false; // 文件不存在，不算错误，返回 false
    }

    /*
     * ──────────────────────────────────────────────
     * 第五部分：私有校验方法（内部使用，外部不可调用）
     * ──────────────────────────────────────────────
     *
     * 用 private 修饰的方法只能在类内部调用，
     * 这是面向对象编程的"封装性"原则——隐藏实现细节。
     */

    /**
     * 校验 MIME 类型（双重校验的第一道）
     *
     * 🔒 为什么 MIME 校验最重要？
     *    因为扩展名可以随便改（virus.exe → virus.jpg），但 MIME 由文件内容决定，
     *    更难伪造。所以 MIME 是最可靠的文件类型判断方式。
     *
     * 📖 $file->getMimeType() 做了什么？
     *    PHP 通过读取文件的"魔术字节"（文件头部的特征字节序列）来判断真实类型。
     *    例：PNG 文件头永远是 89 50 4E 47
     *
     * @throws \InvalidArgumentException
     */
    private function validateMimeType(UploadedFile $file): void
    {
        $mime = $file->getMimeType();

        // in_array($needle, $haystack, true) 第三个参数 true = 严格比较（类型+值都要相等）
        if (! in_array($mime, self::ALLOWED_MIME_TYPES, true)) {
            throw new \InvalidArgumentException(
                "文件格式不支持。允许的类型：" . implode(', ', self::ALLOWED_EXTENSIONS)
                // implode() 把数组合并为逗号分隔的字符串，用于友好提示用户
            );
        }
    }

    /**
     * 校验文件大小
     *
     * 💾 $file->getSize() 返回的是**字节**（bytes）
     *    所以要除以 1024 转换为 KB，再与上限比较
     *
     * 📏 单位换算：
     *    1 KB = 1024 Bytes
     *    1 MB = 1024 KB = 1,048,576 Bytes
     *    15 MB = 15,360 KB = 15,728,640 Bytes
     *
     * @throws \InvalidArgumentException
     */
    private function validateFileSize(UploadedFile $file): void
    {
        $sizeKb = $file->getSize() / 1024; // 字节 → KB

        if ($sizeKb > self::MAX_FILE_SIZE_KB) {
            throw new \InvalidArgumentException(
                "文件大小超出限制，最大允许 " . (self::MAX_FILE_SIZE_KB / 1024) . "MB"
            );
        }
    }

    /**
     * 校验文件扩展名
     *
     * 🛡️ 这是第二道防线，虽然不如 MIME 可靠，但能拦截一些明显错误的文件。
     *    两道防线结合：MIME + 扩展名 = 双重保障
     *
     * @param  string  $extension  文件扩展名（不含点号，如 "jpg"）
     * @throws \InvalidArgumentException
     */
    private function validateExtension(string $extension): void
    {
        if (! in_array(strtolower($extension), self::ALLOWED_EXTENSIONS, true)) {
            // strtolower() 转小写，防止 "JPG" 和 "jpg" 不匹配的问题
            throw new \InvalidArgumentException(
                "文件扩展名不被允许。允许的扩展名：" . implode(', ', self::ALLOWED_EXTENSIONS)
            );
        }
    }

    /**
     * 校验图片宽高是否满足最低要求（仅对 jpg/png 位图格式校验）
     *
     * 📏 为什么需要这个校验？
     *    文档要求"设计稿分辨率建议 ≥ 300DPI（前端提示，后端校验宽高）"。
     *    后端无法直接获取 DPI，但可以获取图片像素尺寸（宽×高）。
     *    像素尺寸过低 → 印刷效果模糊 → 不符合设计稿质量要求。
     *
     * 🔍 getimagesize() 是什么？
     *    PHP 内置函数，读取图片文件的头部信息，返回宽、高、类型等。
     *    不需要加载整张图片到内存，速度很快。
     *    仅支持位图格式（jpg/png/gif/bmp/webp），
     *    不支持矢量格式（pdf/ai）和多层格式（psd）。
     *
     * 📖 返回值结构：
     *    [
     *        0 => 宽度（像素）,    例如 1200
     *        1 => 高度（像素）,    例如 800
     *        2 => 图片类型常量,    例如 IMAGETYPE_JPEG = 2
     *        3 => HTML 属性字符串, 例如 'width="1200" height="800"'
     *        'mime' => MIME 类型,  例如 'image/jpeg'
     *    ]
     *
     * ⚠️ 为什么 PDF/AI/PSD 跳过校验？
     *    - PDF 可能包含多页，每页尺寸不同
     *    - AI 是矢量格式，没有固定像素概念
     *    - PSD 是多层格式，getimagesize() 可能读取失败
     *    这些格式由前端提示即可，后端不做强制校验
     *
     * @throws \InvalidArgumentException 当图片宽高低于最小限制时抛出
     */
    private function validateImageDimensions(UploadedFile $file): void
    {
        // 只对 jpg/png 位图格式校验，其他格式跳过
        $mime = $file->getMimeType();
        if (! in_array($mime, self::IMAGE_MIME_TYPES_FOR_DIMENSION_CHECK, true)) {
            // PDF/AI/PSD → 跳过宽高校验
            return;
        }

        // getimagesize() 读取图片的真实像素尺寸
        // $file->getRealPath() 获取上传文件的临时存储路径
        $dimensions = @getimagesize($file->getRealPath());

        // @ 符号抑制错误：如果文件损坏导致 getimagesize() 失败，
        // 不会抛出 PHP Warning，而是返回 false
        if ($dimensions === false) {
            // 无法读取图片尺寸 → 可能文件损坏，允许通过
            // （因为前面 MIME 校验已经通过，文件类型没问题，只是无法解析尺寸）
            Log::warning('Unable to read image dimensions', [
                'file' => $file->getClientOriginalName(),
                'mime' => $mime,
            ]);
            return;
        }

        // $dimensions[0] = 宽度（像素），$dimensions[1] = 高度（像素）
        $width = $dimensions[0];
        $height = $dimensions[1];

        // 校验宽高是否低于最低限制
        if ($width < self::MIN_IMAGE_WIDTH || $height < self::MIN_IMAGE_HEIGHT) {
            throw new \InvalidArgumentException(
                "图片分辨率过低（当前 {$width}×{$height} 像素），"
                . "设计稿建议至少 " . self::MIN_IMAGE_WIDTH . "×" . self::MIN_IMAGE_HEIGHT . " 像素"
                . "（约等于 300DPI 印刷质量）"
            );
        }
    }

    /**
     * 清理路径，防止目录穿越攻击（Path Traversal）
     *
     * 🚨 什么是目录穿越攻击？
     *    攻击者通过在路径中插入 "../" 来访问不该访问的文件/目录。
     *    例：正常路径 "uploads/1/file.jpg"
     *        攻击路径 "../../etc/passwd" → 可能读到服务器密码文件！
     *
     * 🛡️ 防护措施：
     *    1. 移除 ".." （上级目录引用）
     *    2. 将 "\" 替换为 "/" （Windows 路径分隔符统一）
     *    3. 合并多余的斜杠 "a//b" → "a/b"
     *    4. trim() 去除首尾多余字符
     *
     * @param  string  $path  原始路径输入
     * @return string  清理后的安全路径
     *
     * 💡 示例：
     *    输入：  "merch-designs/123/../../secret"
     *    输出：  "merch-designs/123/secret"  （同时记录日志警告）
     */
    private function sanitizePath(string $path): string
    {
        // str_replace 可以接受数组作为搜索参数，一次性替换多个目标
        $clean = str_replace(['..', '\\'], ['', '/'], $path);

        // 正则表达式 #/+# 匹配连续的一个或多个斜杠，替换为单个 "/"
        // # 是正则表达式的定界符（PHP风格），等同于 "/\/+/"
        $clean = preg_replace('#/+#', '/', trim($clean, '/'));

        // 如果清理前后不一致，说明检测到了可疑路径，记录安全警告日志
        if ($clean !== $path) {
            Log::warning('File path sanitized', ['original' => $path, 'cleaned' => $clean]);
            // Log::warning() 会写到 storage/logs/laravel.log 中
        }

        return $clean;
    }

    /**
     * 解析文件的访问 URL
     *
     * 🔗 不同存储方式返回不同类型的 URL：
     *    1. S3/OSS → 返回完整的 HTTPS URL（可直接浏览器打开）
     *    2. public 磁盘 → 返回 /storage/xxx 相对路径（可通过 Web 访问）
     *    3. local/private 磁盘 → 返回 API 代理路径（需要额外写个下载接口）
     *
     * @param  mixed   $disk          Storage 磁盘实例
     * @param  string  $storagePath   存储路径
     * @return string  可访问的 URL
     */
    private function resolveUrl($disk, string $storagePath): string
    {
        $adapter = $disk->getDriver()->getAdapter();

        // 检查是否为 S3/OSS 适配器
        if ($adapter instanceof \League\Flysystem\AwsS3V3\AwsS3Adapter) {
            return $disk->url($storagePath);
            // S3/OSS 会返回类似：
            // https://bucket-name.oss-cn-hangzhou.aliyuncs.com/path/to/file.jpg
        }

        // public 磁盘：文件可通过 Web 直接访问
        // 需要 php artisan storage:link 创建符号链接
        if (config('filesystems.default') === 'public') {
            return $disk->url($storagePath);
            // 返回类似：http://localhost/storage/path/to/file.jpg
        }

        // local/private 磁盘：文件不可直接通过 Web 访问
        // 返回一个 API 路径，需要额外的控制器来代理文件下载
        // urlencode() 对路径进行 URL 编码，防止特殊字符问题
        return '/api/files/' . urlencode($storagePath);
        // 返回类似：/api/files/merch-designs%2F123%2Fxxx.jpg
    }
}
