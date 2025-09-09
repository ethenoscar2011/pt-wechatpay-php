# 微信支付 WeChatPay OpenAPI SDK (PHP 5.6+ 兼容版)

[A]Sync Chainable WeChatPay v2&v3's OpenAPI SDK for PHP - 兼容 PHP 5.6+

[![Packagist Stars](https://img.shields.io/packagist/stars/pt/wechatpay-php)](https://packagist.org/packages/pt/wechatpay-php)
[![Packagist Downloads](https://img.shields.io/packagist/dm/pt/wechatpay-php)](https://packagist.org/packages/pt/wechatpay-php)
[![Packagist Version](https://img.shields.io/packagist/v/pt/wechatpay-php)](https://packagist.org/packages/pt/wechatpay-php)
[![Packagist PHP Version Support](https://img.shields.io/packagist/php-v/pt/wechatpay-php)](https://packagist.org/packages/pt/wechatpay-php)
[![Packagist License](https://img.shields.io/packagist/license/pt/wechatpay-php)](https://packagist.org/packages/pt/wechatpay-php)

## 概览

基于 [Guzzle HTTP Client](http://docs.guzzlephp.org/) 的微信支付 PHP 开发库，兼容 PHP 5.6+ 版本。

### 功能介绍

1. 微信支付 APIv2 和 APIv3 的 Guzzle HTTP 客户端，支持 [同步](#同步请求) 或 [异步](#异步请求) 发送请求，并自动进行请求签名和应答验签

1. [链式实现的 URI Template](#链式-uri-template)

1. [敏感信息加解密](#敏感信息加解密)

1. [回调通知](#回调通知)的验签和解密

## 项目状态

当前版本为 `1.4.12` 版，基于官方 wechatpay-php 重构，兼容 PHP 5.6+ 版本。
项目版本遵循 [语义化版本号](https://semver.org/lang/zh-CN/)。

## 环境要求

项目支持的环境如下：

+ PHP >= 5.6.0
+ Guzzle 6.0+ 或 7.0+
+ ext-curl, ext-libxml, ext-simplexml, ext-openssl

我们推荐使用目前处于 [Active Support](https://www.php.net/supported-versions.php) 阶段的 PHP 8 和 Guzzle 7。

## 安装

推荐使用 PHP 包管理工具 [Composer](https://getcomposer.org/) 安装 SDK：

```shell
composer require ethenoscar2011/pt-wechatpay-php
```

## 开始

:information_source: 以下是 [微信支付 API v3](https://pay.weixin.qq.com/docs/merchant/development/interface-rules/introduction.html) 的指引。如果你是 API v2 的使用者，请看 [README_APIv2](README_APIv2.md)。

### 概念

+ **商户 API 证书**，是用来证实商户身份的。证书中包含商户号、证书序列号、证书有效期等信息，由证书授权机构（Certificate Authority ，简称 CA）签发，以防证书被伪造或篡改。详情见 [什么是商户API证书？如何获取商户API证书？](https://kf.qq.com/faq/161222NneAJf161222U7fARv.html) 。

+ **商户 API 私钥**。你申请商户 API 证书时，会生成商户私钥，并保存在本地证书文件夹的文件 apiclient_key.pem 中。为了证明 API 请求是由你发送的，你应使用商户 API 私钥对请求进行签名。

  > :key: 不要把私钥文件暴露在公共场合，如上传到 Github，写在 App 代码中等。

+ **微信支付平台证书**。微信支付平台证书是指：由微信支付负责申请，包含微信支付平台标识、公钥信息的证书。你需使用微信支付平台证书中的公钥验证 API 应答和回调通知的签名。

  > :bookmark: 通用的 composer 命令，像安装依赖包一样 [下载平台证书](#如何下载平台证书) 文件，供SDK初始化使用。

+ **证书序列号**。每个证书都有一个由 CA 颁发的唯一编号，即证书序列号。

+ **微信支付公钥**，用于应答及回调通知的数据签名，可在 [微信支付商户平台](https://pay.weixin.qq.com) -> 账户中心 -> API安全 直接下载。

+ **微信支付公钥ID**，是微信支付公钥的唯一标识，可在 [微信支付商户平台](https://pay.weixin.qq.com) -> 账户中心 -> API安全 直接查看。

### 初始化一个APIv3客户端

```php
<?php

require_once('vendor/autoload.php');

use PtWeChatPay\Builder;
use PtWeChatPay\Crypto\Rsa;

// 设置参数

// 商户号
$merchantId = '190000****';

// 从本地文件中加载「商户API私钥」，「商户API私钥」会用来生成请求的签名
$merchantPrivateKeyFilePath = 'file:///path/to/merchant/apiclient_key.pem';
$merchantPrivateKeyInstance = Rsa::from($merchantPrivateKeyFilePath, Rsa::KEY_TYPE_PRIVATE);

// 「商户API证书」的「证书序列号」
$merchantCertificateSerial = '3775B6A45ACD588826D15E583A95F5DD********';

// 从本地文件中加载「微信支付平台证书」，可由内置CLI工具下载到，用来验证微信支付应答的签名
$platformCertificateFilePath  = 'file:///path/to/wechatpay/certificate.pem';
$onePlatformPublicKeyInstance = Rsa::from($platformCertificateFilePath, Rsa::KEY_TYPE_PUBLIC);

// 「微信支付平台证书」的「平台证书序列号」
// 可以从「微信支付平台证书」文件解析，也可以在 商户平台 -> 账户中心 -> API安全 查询到
$platformCertificateSerial = '7132D72A03E93CDDF8C03BBD1F37EEDF********';

// 从本地文件中加载「微信支付公钥」，用来验证微信支付应答的签名
$platformPublicKeyFilePath    = 'file:///path/to/wechatpay/publickey.pem';
$twoPlatformPublicKeyInstance = Rsa::from($platformPublicKeyFilePath, Rsa::KEY_TYPE_PUBLIC);

// 「微信支付公钥」的「微信支付公钥ID」
// 需要在 商户平台 -> 账户中心 -> API安全 查询
$platformPublicKeyId = 'PUB_KEY_ID_01142321349124100000000000********';

// 构造一个 APIv3 客户端实例
$instance = Builder::factory(array(
    'mchid'      => $merchantId,
    'serial'     => $merchantCertificateSerial,
    'privateKey' => $merchantPrivateKeyInstance,
    'certs'      => array(
        $platformCertificateSerial => $onePlatformPublicKeyInstance,
        $platformPublicKeyId       => $twoPlatformPublicKeyInstance,
    ),
));
```

### 示例，第一个请求：查询「微信支付平台证书」

```php
// 发送请求
try {
    $resp = $instance->chain('v3/certificates')->get(
        // array('debug' => true) // 调试模式
    );
    echo (string) $resp->getBody(), PHP_EOL;
} catch(Exception $e) {
    // 进行异常捕获并进行错误判断处理
    echo $e->getMessage(), PHP_EOL;
    if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
        $r = $e->getResponse();
        echo $r->getStatusCode() . ' ' . $r->getReasonPhrase(), PHP_EOL;
        echo (string) $r->getBody(), PHP_EOL, PHP_EOL, PHP_EOL;
    }
    echo $e->getTraceAsString(), PHP_EOL;
}
```

## 与官方版本的主要差异

本版本基于官方 wechatpay-php 重构，主要变化包括：

1. **移除严格类型声明**：去除了 `declare(strict_types=1)` 和所有类型声明，兼容 PHP 5.6
2. **移除 PHP 7+ 特性**：去除了 `#[\SensitiveParameter]` 属性、变长参数等
3. **调整依赖版本**：确保 Guzzle 版本兼容 PHP 5.6
4. **保持功能完整性**：所有核心功能保持不变，API 接口完全兼容

## 兼容性测试

本版本已在以下 PHP 版本中测试通过：
- PHP 5.6.40
- PHP 7.3.32  
- PHP 8.1.29

## 常见问题

### 如何下载平台证书？

使用内置的[微信支付平台证书下载器](bin/README.md)。

```bash
composer exec CertificateDownloader.php -- -k ${apiV3key} -m ${mchId} -f ${mchPrivateKeyFilePath} -s ${mchSerialNo} -o ${outputFilePath}
```

### 证书和回调解密需要的AesGcm解密在哪里？

请参考[AesGcm.php](src/Crypto/AesGcm.php)，例如内置的`平台证书`下载工具解密代码如下:

```php
AesGcm::decrypt($cert->ciphertext, $apiv3Key, $cert->nonce, $cert->associated_data);
```

### 如何加载公/私钥和证书

`v1.2`提供了统一的加载函数 `Rsa::from($thing, $type)`。

- `Rsa::from($thing, $type)` 支持从文件/字符串加载公/私钥和证书，使用方法可参考 [RsaTest.php](tests/Crypto/RsaTest.php)
- `Rsa::fromPkcs1`是个语法糖，支持加载 `PKCS#1` 格式的公/私钥，入参是 `base64` 字符串
- `Rsa::fromPkcs8`是个语法糖，支持加载 `PKCS#8` 格式的私钥，入参是 `base64` 字符串
- `Rsa::fromSpki`是个语法糖，支持加载 `SPKI` 格式的公钥，入参是 `base64` 字符串
- `Rsa::pkcs1ToSpki`是个 `RSA公钥` 格式转换函数，入参是 `base64` 字符串

## 联系我们

如果你发现了**BUG**或者有任何疑问、建议，请通过issue进行反馈。

也欢迎访问我们的[开发者社区](https://developers.weixin.qq.com/community/pay)。

## 链接

+ [GuzzleHttp官方版本支持](https://docs.guzzlephp.org/en/stable/overview.html#requirements)
+ [PHP官方版本支持](https://www.php.net/supported-versions.php)
+ [原版 wechatpay-php](https://github.com/wechatpay-apiv3/wechatpay-php)

## License

[Apache-2.0 License](LICENSE)
