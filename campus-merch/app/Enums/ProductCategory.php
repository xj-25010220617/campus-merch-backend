<?php

namespace App\Enums;

/**
 * 商品分类枚举
 *
 * 定义商品的所有合法分类值，用于导入校验等场景。
 * 值采用英文，与 ProductStatus / OrderStatus 风格一致。
 */
enum ProductCategory: string
{
    case CLOTHING    = 'clothing';      // 服装（T恤、卫衣等）
    case DAILY       = 'daily';         // 日用品（帆布袋、马克杯等）
    case CREATIVE    = 'creative';      // 文创（徽章、贴纸、明信片等）
    case STATIONERY  = 'stationery';    // 文具（笔记本、笔、文件夹等）
    case ACCESSORY   = 'accessory';     // 配饰（挂绳、钥匙扣等）
}
