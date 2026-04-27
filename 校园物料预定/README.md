# CampusMerch API

校园文创与活动物料预订管理系统后端，基于 Laravel 13 + Sanctum。

## 当前已完成的工程骨架

- Laravel 13 标准项目结构
- API 路由入口
- Sanctum Token 认证
- 按模块拆分的控制器、请求类、服务层、枚举、模型
- `products`、`orders`、`order_attachments`、`audit_logs` 迁移骨架
- `role` 中间件

## 目录结构

```text
app/
  Http/
    Controllers/Api/
    Middleware/
    Requests/
  Services/
  Enums/
  Models/
database/migrations/
routes/api.php
```

## 快速启动

1. 安装依赖

```bash
composer install
```

2. 配置环境

编辑 `.env` 中的数据库配置，默认使用 MySQL：

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=campus_merch
DB_USERNAME=root
DB_PASSWORD=
```

3. 生成应用密钥

```bash
php artisan key:generate
```

4. 执行迁移

```bash
php artisan migrate
```

5. 启动服务

```bash
php artisan serve
```

## 认证接口

- `POST /api/register`
- `POST /api/login`
- `POST /api/logout`
- `GET /api/me`

认证方式：`Authorization: Bearer <token>`

## 核心业务接口

- `GET /api/products`
- `GET /api/products/{id}`
- `PUT /api/products/{id}`
- `POST /api/products/import`
- `POST /api/orders`
- `GET /api/my-orders`
- `POST /api/orders/{id}/design`
- `PUT /api/admin/orders/{id}/review`
- `POST /api/orders/{id}/complete`
- `GET /api/orders/export`
- `GET /api/admin/stats`

## 建议分工

- 组长：商品与订单核心模块
- 成员 A：Auth 与用户模块
- 成员 B：文件上传与报表模块

## 当前说明

当前项目已经具备完整 Laravel 工程骨架和基础认证能力，但业务逻辑仍以占位实现为主，适合三人小组继续分模块开发。
