# PHP 5.6+ 兼容性说明

## 概述

本项目是基于官方 wechatpay-php 重构的版本，专门为支持 PHP 5.6+ 而设计。所有核心功能保持不变，API 接口完全兼容。

## 主要变化

### 1. 移除严格类型声明
- 去除了所有文件开头的 `declare(strict_types=1)`
- 移除了所有函数参数和返回值的类型声明
- 移除了所有类属性的类型声明

### 2. 移除 PHP 7+ 特性
- 去除了 `#[\SensitiveParameter]` 属性
- 将变长参数 `...$args` 改为 `func_get_args()`
- 将匿名类改为具名类 `BuilderChainableImpl`

### 3. 修复 PHP 5.6 兼容性问题
- 将 `private const` 改为 `private static $`
- 修复了 `random_bytes()` 函数不存在的问题，使用 `openssl_random_pseudo_bytes()` 作为后备
- 修复了数组常量的问题

### 4. 调整依赖版本
- 确保 Guzzle 版本兼容 PHP 5.6
- 更新了 composer.json 中的 PHP 版本要求

## 测试结果

已在以下 PHP 版本中测试通过：

- ✅ PHP 5.6.40 - 完全兼容
- ✅ PHP 7.3.32 - 完全兼容  
- ✅ PHP 8.1.29 - 完全兼容（有弃用警告但不影响功能）

## 使用方法

使用方法与原版 wechatpay-php 完全相同：

```php
<?php
require_once('vendor/autoload.php');

use PtWeChatPay\Builder;
use PtWeChatPay\Crypto\Rsa;

// 初始化客户端
$instance = Builder::factory(array(
    'mchid'      => $merchantId,
    'serial'     => $merchantCertificateSerial,
    'privateKey' => $merchantPrivateKeyInstance,
    'certs'      => array(
        $platformCertificateSerial => $platformPublicKeyInstance,
    ),
));

// 发送请求
$resp = $instance->chain('v3/certificates')->get();
echo (string) $resp->getBody();
```

## 注意事项

1. **性能**: PHP 5.6 的性能可能不如新版本，建议在生产环境中使用 PHP 7.4+ 或更高版本
2. **安全性**: PHP 5.6 已经不再接收安全更新，建议尽快升级到支持的 PHP 版本
3. **功能**: 所有核心功能与原版保持一致，包括签名、验签、加密解密等

## 文件结构

```
pt-wechatpay-php/
├── src/
│   ├── Builder.php                    # 主构建器类
│   ├── BuilderChainable.php          # 链式接口
│   ├── BuilderTrait.php              # 链式特性
│   ├── ClientDecorator.php           # 客户端装饰器
│   ├── ClientDecoratorInterface.php  # 客户端接口
│   ├── ClientJsonTrait.php           # JSON 客户端特性
│   ├── ClientXmlTrait.php            # XML 客户端特性
│   ├── Formatter.php                 # 格式化工具
│   ├── Transformer.php               # XML/Array 转换器
│   ├── Crypto/                       # 加密相关
│   │   ├── AesEcb.php
│   │   ├── AesGcm.php
│   │   ├── AesInterface.php
│   │   ├── Hash.php
│   │   └── Rsa.php
│   ├── Exception/                    # 异常类
│   │   ├── InvalidArgumentException.php
│   │   └── WeChatPayException.php
│   └── Util/                         # 工具类
│       ├── MediaUtil.php
│       └── PemUtil.php
├── bin/
│   └── CertificateDownloader.php     # 证书下载工具
├── composer.json                     # 依赖配置
├── README.md                         # 说明文档
├── LICENSE                           # 许可证
└── COMPATIBILITY.md                  # 本文件
```

## 许可证

本项目基于 Apache-2.0 许可证，与原版 wechatpay-php 保持一致。
