# 测试说明

这是 pt-wechatpay-php 的测试套件，已重构为支持 PHP 5.6+ 版本。

## 运行测试

### 使用 PHPUnit

```bash
# 安装 PHPUnit (需要 PHP 7.0+)
composer require --dev phpunit/phpunit

# 运行所有测试
./vendor/bin/phpunit

# 运行特定测试
./vendor/bin/phpunit tests/FormatterTest.php
```

### 使用不同 PHP 版本

```bash
# PHP 5.6
/usr/bin/php56 ./vendor/bin/phpunit

# PHP 7.3
/usr/bin/php73 ./vendor/bin/phpunit

# PHP 8.1
/usr/bin/php81 ./vendor/bin/phpunit
```

## 测试覆盖

- **FormatterTest**: 测试格式化工具类
- **Crypto/HashTest**: 测试哈希加密功能
- **Crypto/AesEcbTest**: 测试 AES-ECB 加密
- **Crypto/AesGcmTest**: 测试 AES-GCM 加密
- **Crypto/RsaTest**: 测试 RSA 加密/解密/签名/验证
- **TransformerTest**: 测试 XML/数组转换
- **Util/MediaUtilTest**: 测试媒体文件处理
- **Util/PemUtilTest**: 测试 PEM 证书处理

## 注意事项

1. 测试已重构为兼容 PHP 5.6+ 语法
2. 移除了所有类型声明和严格类型检查
3. 使用 `assertInternalType()` 替代 `assertIs*()` 方法
4. 使用 `array()` 替代 `[]` 语法
5. 移除了变长参数语法，使用 `func_get_args()`
