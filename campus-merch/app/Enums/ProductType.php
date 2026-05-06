<?php

namespace App\Enums;

/**
 * 商品类型枚举
 *
 * 定义商品的所有合法类型值，用于导入校验等场景。
 * 值采用英文，与 ProductStatus / OrderStatus 风格一致。
 */
enum ProductType: string
{
    case STANDARD = 'standard';   // 标准款（无定制）
    case CUSTOM   = 'custom';     // 定制款（需上传设计稿）
}
